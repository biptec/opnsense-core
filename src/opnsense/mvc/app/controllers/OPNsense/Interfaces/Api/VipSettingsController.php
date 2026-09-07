<?php

/*
 * Copyright (C) 2022 Deciso B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Interfaces\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Base\UserException;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Firewall\Util;

class VipSettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'vip';
    protected static $internalModelClass = 'OPNsense\Interfaces\Vip';

    private function withVipReconfigure(array $result, array $interfaces): array
    {
        $normalized = [];
        foreach ($interfaces as $interface) {
            $interface = trim((string)$interface);
            if ($interface !== '') {
                $normalized[$interface] = true;
            }
        }
        if (!empty($normalized)) {
            $result['reconfigure'] = ['interfaces' => array_keys($normalized)];
        }
        return $result;
    }

    private function queueDeletedVip(string $uuid, string $interface, string $address): void
    {
        if (Util::isLinkLocal($address)) {
            $address .= "@{$interface}";
        }
        file_put_contents(
            "/tmp/delete_vip_{$uuid}.todo",
            $interface . "\t" . $address . PHP_EOL,
            FILE_APPEND
        );
    }

    /**
     * extract network field into subnet + bits for model
     */
    private function getVipOverlay()
    {
        $overlay = null;
        $tmp = $this->request->getPost('vip');
        if (!empty($tmp['network'])) {
            $overlay = ['subnet' => '', 'subnet_bits' => ''];
            $parts = explode('/', $tmp['network'], 2);
            $overlay['subnet'] = $parts[0];
            if (count($parts) == 2 && $parts[1] != '') {
                $overlay['subnet_bits'] = $parts[1];
            }
        }
        return $overlay;
    }

    /**
     * retrieve first unused VHID number
     */
    public function getUnusedVhidAction()
    {
        $vhids = [];
        foreach ($this->getModel()->vip->iterateItems() as $vip) {
            if (!in_array((string)$vip->vhid, $vhids) && !empty((string)$vip->vhid)) {
                $vhids[] = (string)$vip->vhid;
            }
        }
        for ($i = 1; $i <= 255; $i++) {
            if (!in_array((string)$i, $vhids)) {
                return ['vhid' => $i, 'status' => 'ok'];
            }
        }
        return ['status' => 'not_found'];
    }

    /**
     * remap subnet and subnet_bits to network (which represents combined field)
     */
    private function handleFormValidations($response)
    {
        if (!empty($response['validations'])) {
            foreach (array_keys($response['validations']) as $fieldname) {
                if (in_array($fieldname, ['vip.subnet', 'vip.subnet_bits'])) {
                    if (empty($response['validations']['vip.network'])) {
                        $response['validations']['vip.network'] = [];
                    }
                    if (is_array($response['validations'][$fieldname])) {
                        $response['validations']['vip.network'] = array_merge(
                            $response['validations']['vip.network'],
                            $response['validations'][$fieldname]
                        );
                    } else {
                        $response['validations']['vip.network'][] = $response['validations'][$fieldname];
                    }
                    unset($response['validations'][$fieldname]);
                }
            }
        }
        return $response;
    }

    public function searchItemAction()
    {
        // Forms only use POST, but since search offers both GET and POST, let's keep this compatible
        $mode = $this->request->get('mode') ?? $this->request->getPost('mode');
        $filter_funct = null;
        if (!empty($mode)) {
            $filter_funct = function ($record) use ($mode) {
                return in_array($record->mode, $mode);
            };
        }
        return $this->searchBase('vip', null, 'descr', $filter_funct);
    }

    public function setItemAction($uuid)
    {
        Config::getInstance()->lock();
        $node = $this->getModel()->getNodeByReference('vip.' . $uuid);
        $validations = [];
        $post_subnet = '';
        $post_interface = '';
        if (isset($_POST['vip'])) {
            $post_subnet = !empty($_POST['vip']['network']) ? explode('/', $_POST['vip']['network'])[0] : '';
            $post_interface = !empty($_POST['vip']['interface']) ? $_POST['vip']['interface'] : '';
        }

        if ($node != null && $post_subnet != (string)$node->subnet) {
            $validations = $this->getModel()->whereUsed((string)$node->subnet);
            if (!empty($validations)) {
                // XXX a bit unpractical, but we can not validate previous values from the model so
                //     we are obligated to return this as a single error (even if the form has other issues too)
                return [
                    'result' => 'failed',
                    'validations' => [
                        'vip.network' => array_slice($validations, 0, 2)
                    ]
                ];
            }
        }
        $previousInterface = $node !== null ? (string)$node->interface : '';
        if ($node != null && ($post_subnet != (string)$node->subnet || $post_interface != (string)$node->interface)) {
            $this->queueDeletedVip($uuid, (string)$node->interface, (string)$node->subnet);
        }

        $response = $this->handleFormValidations($this->setBase('vip', 'vip', $uuid, $this->getVipOverlay()));
        if (($response['result'] ?? '') === 'saved') {
            $current = $this->getModel()->getNodeByReference('vip.' . $uuid);
            $response = $this->withVipReconfigure($response, [
                $previousInterface,
                $current !== null ? (string)$current->interface : '',
            ]);
        }
        return $response;
    }

    public function addItemAction()
    {
        $response = $this->handleFormValidations($this->addBase('vip', 'vip', $this->getVipOverlay()));
        if (($response['result'] ?? '') === 'saved' && !empty($response['uuid'])) {
            $node = $this->getModel()->getNodeByReference('vip.' . $response['uuid']);
            if ($node !== null) {
                $response = $this->withVipReconfigure($response, [(string)$node->interface]);
            }
        }
        return $response;
    }

    public function getItemAction($uuid = null)
    {
        $vip = $this->getBase('vip', 'vip', $uuid);
        // Merge subnet + netmask into network field
        if (!empty($vip['vip']) && !empty($vip['vip']['subnet'])) {
            $vip['vip']['network'] = $vip['vip']['subnet'] . "/" . $vip['vip']['subnet_bits'];
        } elseif (!empty($vip['vip'])) {
            $vip['vip']['network'] = '';
        }
        unset($vip['vip']['subnet']);
        unset($vip['vip']['subnet_bits']);
        return $vip;
    }

    public function delItemAction($uuids)
    {
        Config::getInstance()->lock();
        $nodes = [];

        foreach (!empty($uuids) ? explode(",", $uuids) : [] as $uuid) {
            $node = $this->getModel()->getNodeByReference('vip.' . $uuid);
            if ($node == null) {
                continue;
            }

            $validations = $this->getModel()->whereUsed((string)$node->subnet);
            if (!empty($validations)) {
                throw new UserException(implode('<br/>', array_slice($validations, 0, 5)), gettext("Item in use by"));
            }

            if ((string)$node->mode == 'carp') {
                foreach ($this->getModel()->vip->iterateItems() as $vip) {
                    if ((string)$vip->mode == 'ipalias' && (string)$vip->vhid == (string)$node->vhid) {
                        $vhid = (string)$node->vhid;
                        throw new UserException(
                            sprintf(
                                gettext("Cannot delete CARP Virtual IP, IP Alias with VHID Group %s still exists."),
                                $vhid
                            ),
                            gettext("Error")
                        );
                    }
                }
            }

            $nodes[$uuid] = $node;
        }

        $response = $this->delBase("vip", $uuids);
        if (($response['result'] ?? '') == 'deleted') {
            $interfaces = [];
            foreach ($nodes as $uuid => $node) {
                $interface = (string)$node->interface;
                $interfaces[] = $interface;
                $this->queueDeletedVip($uuid, $interface, (string)$node->subnet);
            }
            $response = $this->withVipReconfigure($response, $interfaces);
        }
        return $response;
    }

    public function reconfigureAction()
    {
        $result = array("status" => "failed");
        if ($this->request->isPost()) {
            $command = 'interface vip configure';
            if ($this->request->hasPost('interfaces')) {
                $requested = $this->request->getPost('interfaces');
                if (!is_array($requested) || empty($requested)) {
                    return $result;
                }
                $configured = array_fill_keys(array_keys(Config::getInstance()->toArray()['interfaces'] ?? []), true);
                $interfaces = [];
                foreach ($requested as $interface) {
                    $interface = trim((string)$interface);
                    if ($interface === '' || !isset($configured[$interface])) {
                        return $result;
                    }
                    $interfaces[$interface] = true;
                }
                $command = 'interface vip configure_selected ' . implode(',', array_keys($interfaces));
            }
            $result['status'] = strtolower(trim((new Backend())->configdRun($command)));
        }
        return $result;
    }
}
