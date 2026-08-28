<?php

/*
 * Copyright (C) 2026 Deciso B.V.
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

class AssignmentController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'interface';
    protected static $internalModelClass = 'OPNsense\Interfaces\NetworkInterface';

    private function hasPolicyManager(): bool
    {
        return class_exists('\\OPNsense\\ApiExtensions\\PolicyAssignmentManager');
    }

    private function policyPayload(): ?string
    {
        if (!$this->hasPolicyManager() || !$this->request->isPost() || !$this->request->hasPost('interface')) {
            return null;
        }
        $payload = $this->request->getPost('interface');
        if (!is_array($payload) || !array_key_exists('ha_policy', $payload)) {
            return null;
        }
        return trim((string)$payload['ha_policy']);
    }

    private function validatePolicyPayload(): ?array
    {
        $policy = $this->policyPayload();
        if ($policy === null) {
            return null;
        }
        try {
            \OPNsense\ApiExtensions\PolicyAssignmentManager::validatePolicy($policy);
        } catch (\Throwable $error) {
            return [
                'result' => 'failed',
                'validations' => [
                    'interface.ha_policy' => $error->getMessage()
                ]
            ];
        }
        return null;
    }

    private function policyState(string $identifier): array
    {
        if (!$this->hasPolicyManager() || $identifier === '') {
            return [
                'policy_id' => '',
                'policy_description' => '',
                'synchronize' => false,
                'owner' => 'unassigned',
                'ha_service_enabled' => false,
            ];
        }
        return \OPNsense\ApiExtensions\PolicyAssignmentManager::interfaceState($identifier);
    }

    private function policyOptions(string $selected): array
    {
        $options = [];
        if (!$this->hasPolicyManager()) {
            return $options;
        }
        foreach (\OPNsense\ApiExtensions\PolicyAssignmentManager::policies() as $policy) {
            $label = $policy['id'];
            if (!empty($policy['description'])) {
                $label .= ' — ' . $policy['description'];
            }
            $options[$policy['id']] = [
                'value' => $label,
                'selected' => $policy['id'] === $selected ? 1 : 0,
            ];
        }
        return $options;
    }

    private function readonlyReplica(string $identifier): ?array
    {
        if (!$this->hasPolicyManager() || $identifier === '') {
            return null;
        }
        $state = $this->policyState($identifier);
        if ($state['owner'] !== 'ha_peer') {
            return null;
        }
        return [
            'result' => 'failed',
            'validations' => [
                'interface.ha_policy' => gettext('This interface is an HA peer replica and is read-only on this node.')
            ]
        ];
    }

    private function configuredInterfaceNames(): array
    {
        $config = Config::getInstance()->toArray();
        return isset($config['interfaces']) && is_array($config['interfaces']) ? array_keys($config['interfaces']) : [];
    }

    public function searchItemAction()
    {
        $result = $this->searchBase("interface");
        if (!$this->hasPolicyManager() || !isset($result['rows']) || !is_array($result['rows'])) {
            return $result;
        }
        foreach ($result['rows'] as &$row) {
            $identifier = trim((string)($row['identifier'] ?? ''));
            $state = $this->policyState($identifier);
            $row['ha_policy'] = $state['policy_id'];
            $row['ha_owner'] = $state['owner'];
            $row['ha_synchronize'] = $state['synchronize'] ? '1' : '0';
        }
        unset($row);
        return $result;
    }

    public function setItemAction($ifname)
    {
        if (($readonly = $this->readonlyReplica((string)$ifname)) !== null) {
            return $readonly;
        }
        if (($validation = $this->validatePolicyPayload()) !== null) {
            return $validation;
        }
        if ($this->request->isPost() && $this->request->hasPost('interface')) {
            $payload = $this->request->getPost('interface');
            if (is_array($payload) && !empty($payload['identifier']) && $payload['identifier'] !== $ifname) {
                return [
                    'result' => 'failed',
                    'validations' => [
                        'interface.identifier' => gettext('The interface identifier is immutable after creation.')
                    ]
                ];
            }
        }
        $policy = $this->policyPayload();
        $result = $this->setBase("interface", "interface", $ifname, ['identifier' => $ifname]);
        if (($result['result'] ?? '') === 'saved' && $policy !== null && $this->hasPolicyManager()) {
            try {
                \OPNsense\ApiExtensions\PolicyAssignmentManager::setInterface((string)$ifname, $policy);
            } catch (\Throwable $error) {
                return ['result' => 'failed', 'validations' => ['interface.ha_policy' => $error->getMessage()]];
            }
        }
        return $result;
    }

    public function addItemAction()
    {
        if (($validation = $this->validatePolicyPayload()) !== null) {
            return $validation;
        }
        $policy = $this->policyPayload();
        $before = $policy !== null ? $this->configuredInterfaceNames() : [];
        $payload = $this->request->hasPost('interface') ? $this->request->getPost('interface') : [];
        $requestedIdentifier = is_array($payload) ? trim((string)($payload['identifier'] ?? '')) : '';
        $result = $this->addBase("interface", "interface");
        if (($result['result'] ?? '') === 'saved' && $policy !== null && $this->hasPolicyManager()) {
            $identifier = $requestedIdentifier;
            if ($identifier === '') {
                $created = array_values(array_diff($this->configuredInterfaceNames(), $before));
                if (count($created) === 1) {
                    $identifier = $created[0];
                }
            }
            if ($identifier === '') {
                return [
                    'result' => 'failed',
                    'validations' => [
                        'interface.ha_policy' => gettext('The new interface was saved, but its final identifier could not be resolved for the HA policy assignment.')
                    ]
                ];
            }
            try {
                \OPNsense\ApiExtensions\PolicyAssignmentManager::setInterface($identifier, $policy);
            } catch (\Throwable $error) {
                return ['result' => 'failed', 'validations' => ['interface.ha_policy' => $error->getMessage()]];
            }
        }
        return $result;
    }

    public function getItemAction($ifname = null)
    {
        $result = $this->getBase("interface", "interface", $ifname);
        if (!isset($result['interface']) || !is_array($result['interface'])) {
            return $result;
        }
        $identifier = $ifname === null ? '' : trim((string)$ifname);
        $state = $this->policyState($identifier);
        $result['interface']['ha_policy'] = $this->policyOptions($state['policy_id']);
        $result['interface']['ha_owner'] = $state['owner'];
        $result['interface']['ha_synchronize'] = $state['synchronize'] ? '1' : '0';
        return $result;
    }

    public function delItemAction($ifnames)
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed'];
        }
        foreach (explode(',', (string)$ifnames) as $ifname) {
            if (($readonly = $this->readonlyReplica(trim($ifname))) !== null) {
                return $readonly;
            }
        }
        Config::getInstance()->lock();
        $paths = [
            '/*/ifgroups/ifgroupentry' => gettext("The interface is part of a group. Please remove it from the group to continue"),
            '/*/bridges/bridged' => gettext("The interface is part of a bridge. Please remove it from the bridge to continue"),
            '/*/gres/gre' => gettext("The interface is part of a gre tunnel. Please delete the tunnel to continue"),
            '/*/gifs/gif' => gettext("The interface is part of a gif tunnel. Please delete the tunnel to continue")
        ];
        foreach ($paths as $path => $message) {
            foreach (explode(',', $ifnames) as $ifname) {
                foreach (Config::getInstance()->object()->xpath($path) as $node) {
                    $members = [];
                    foreach (['members', 'if'] as $tag) {
                        foreach (array_filter(explode(',', (string)$node->$tag)) as $member) {
                            $members[] = explode('_vip', $member)[0];
                        }
                    }
                    if (in_array($ifname, $members)) {
                        throw new UserException($message, sprintf(gettext('[%s] in use'), $ifname));
                    }
                }
                if (
                    !empty(Config::getInstance()->object()->interfaces->$ifname) &&
                    !empty(Config::getInstance()->object()->interfaces->$ifname->lock)
                ) {
                    throw new UserException(
                        gettext("Interface locked, unset lock first before removal"),
                        gettext('locked')
                    );
                }
            }
        }
        return $this->delBase("interface", $ifnames);
    }

    private function cleanRules($ifname)
    {
        $sources = [
            ['filter', 'rule'],
            ['nat', 'rule'],
            ['nat', 'outbound', 'rule'],
            ['OPNsense', 'Firewall', 'Filter', 'rules', 'rule'],
            ['OPNsense', 'Firewall', 'Filter', 'snatrules', 'rule'],
            ['OPNsense', 'Firewall', 'Filter', 'npt', 'rule'],
            ['OPNsense', 'Firewall', 'Filter', 'onetoone', 'rule'],
        ];

        foreach ($sources as $aliasref) {
            $cfgsection = Config::getInstance()->object();
            foreach ($aliasref as $cfgName) {
                if ($cfgsection != null) {
                    $cfgsection = $cfgsection->$cfgName;
                }
            }
            if ($cfgsection != null) {
                $to_delete = [];
                foreach ($cfgsection as $idx => $node) {
                    $ifnames = explode(',', $node->interface);
                    if (in_array($ifname, $ifnames)) {
                        $new_list = array_diff($ifnames, [$ifname]);
                        if (empty($new_list)) {
                            $to_delete[] = $node;
                        } else {
                            $node->interface = implode(',', $new_list);
                        }
                    }
                }
                foreach ($to_delete as $node) {
                    $dom = dom_import_simplexml($node);
                    $dom->parentNode->removeChild($dom);
                }
            }
        }
    }

    public function reconfigureAction()
    {
        if ($this->request->isPost()) {
            $backend = new Backend();
            /***
             * Interface apply and final configuration update are separated steps to avoid
             * reloading the filter with the previous interface configuation
             **/
            if (trim($backend->configdRun("interface apply")) == 'OK') {
                Config::getInstance()->lock();
                $deletedInterfaces = [];
                foreach ($this->getModel()->get_if_todo() as $key => $props) {
                    if (!isset(Config::getInstance()->object()->interfaces->$key)) {
                        continue;
                    }
                    if ($props['pending_action'] == 'delete') {
                        $this->cleanRules($key); /* remove associated rules */
                        unset(Config::getInstance()->object()->interfaces->$key);
                        $deletedInterfaces[] = $key;
                    } elseif ($props['pending_action'] == 'relink') {
                        Config::getInstance()->object()->interfaces->$key->if = $props['pending_if'];
                    }
                }
                Config::getInstance()->save();
                $this->getModel()->flush_todo();
                if ($this->hasPolicyManager()) {
                    foreach ($deletedInterfaces as $identifier) {
                        \OPNsense\ApiExtensions\PolicyAssignmentManager::removeInterface($identifier);
                    }
                }
                /* exec filter reload after doing accounting */
                $backend->configdRun('filter reload skip_alias', true);
                return ["status" => "ok"];
            }
        }
        return ["status" => "failed"];
    }
}
