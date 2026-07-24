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

namespace OPNsense\Interfaces;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\Core\Config;
use OPNsense\Core\FileObject;

class NetworkInterface extends BaseModel
{
    var $todo_file = '/tmp/.interfaces.todo';

    /**
     * @param array $payload data to store
     */
    private function store_if_todo($id, $payload)
    {
        $fobj = new FileObject($this->todo_file, 'a+', 0600, LOCK_EX);
        $data = $fobj->readJson() ?? [];
        $data[$id] = array_merge($data[$id] ?? [], $payload);
        $fobj->truncate(0)->writeJson($data);
    }

    /**
     * @return itterator yielding interface names and configuration
     */
    private function iterate_assignments()
    {
        foreach (Config::getInstance()->object()->interfaces->children() as $key => $intf) {
            if (!empty((string)$intf->virtual)) {
                continue;
            }
            yield $key => $intf;
        }
    }

    /**
     * fetch all todo items
     * @return array
     */
    public function get_if_todo()
    {
        if (is_file($this->todo_file)) {
            return (new FileObject($this->todo_file, 'r'))->readJson() ?? [];
        } else {
            return [];
        }
    }

    /**
     * remove todo file after processing
     */
    public function flush_todo()
    {
        if (is_file($this->todo_file)) {
            unlink($this->todo_file);
        }
    }

    /**
     * Merge configuration data in "in memory" model on construction
     */
    public function __construct($lazyload = false)
    {
        parent::__construct($lazyload);
        $iftodos = $this->get_if_todo();
        foreach ($this->iterate_assignments() as $key => $intf) {
            $iftodo = $iftodos[$key] ?? [];
            if (($iftodo['pending_action'] ?? '') == 'delete') {
                continue;
            }
            $node = $this->interface->add($key);
            $node->descr = (string)$intf->descr;
            $node->identifier = $key;
            $node->lock = empty((string)$intf->lock) ? '0' : '1';
            foreach (['' => FILTER_FLAG_IPV4, 'v6' => FILTER_FLAG_IPV6] as $suffix => $filter_flag) {
                $addr_field = 'ipaddr' . $suffix;
                $subnet_field = 'subnet' . $suffix;
                $gateway_field = 'gateway' . $suffix;
                $address = (string)$intf->$addr_field;
                if ($address !== '' && filter_var($address, FILTER_VALIDATE_IP, $filter_flag) !== false) {
                    $node->$addr_field = $address;
                    $node->$subnet_field = (string)$intf->$subnet_field;
                    $node->$gateway_field = (string)$intf->$gateway_field;
                }
            }
            if (isset($iftodo['pending_if'])) {
                $node->if = $iftodo['pending_if'];
            } else {
                $node->if = (string)$intf->if;
            }
        }
    }


    /**
     *  Account changes in config.xml when persisting, return "true" so callers know to flush to the configuration
     */
    public function serializeToConfig($validateFullModel = false, $disable_validation = false)
    {
        /* flush and annotate configuration */
        $interfaces = $this->interface->getNodeContent();
        $existing_ifnames = [];
        /* mark pending actions as we need to wait for "apply" in order to persist them */
        $model_interfaces = iterator_to_array($this->interface->iterateItems());
        foreach ($this->iterate_assignments() as $key => $intf) {
            if (!isset($interfaces[$key])) {
                $this->store_if_todo($key, ['pending_action' => 'delete']);
            } else {
                $intf->descr = $interfaces[$key]['descr'];
                $intf->lock = $interfaces[$key]['lock'];
                foreach (['ipaddr', 'subnet', 'gateway', 'ipaddrv6', 'subnetv6', 'gatewayv6'] as $field) {
                    if ($model_interfaces[$key]->$field->isFieldChanged()) {
                        $value = $interfaces[$key][$field];
                        if ($value === '') {
                            unset($intf->$field);
                        } else {
                            $intf->$field = $value;
                        }
                    }
                }
                /* flush actions that need to be applied, for which we need history */
                if ($intf->if != $interfaces[$key]['if']) {
                    $this->store_if_todo($key, ['pending_action' => 'relink', 'pending_if' => $interfaces[$key]['if']]);
                }
            }
            $existing_ifnames[] = $key;
        }
        $next_if = 1;
        while (in_array('opt' . $next_if, $existing_ifnames)) {
            $next_if++;
        }

        foreach ($interfaces as $key => $intf) {
            if (!isset(Config::getInstance()->object()->interfaces->$key)) {
                $newif = Config::getInstance()->object()->interfaces->addChild('opt' . $next_if);
                $newif->if = $intf['if'];
                $newif->descr = $intf['descr'];
                $newif->lock = $intf['lock'];
                foreach (['ipaddr', 'subnet', 'gateway', 'ipaddrv6', 'subnetv6', 'gatewayv6'] as $field) {
                    if ($intf[$field] !== '') {
                        $newif->$field = $intf[$field];
                    }
                }
                $next_if++;
            }
        }
        return true;
    }

    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);
        foreach ($this->interface->iterateItems() as $ifname => $if) {
            if (!$validateFullModel && !$if->isFieldChanged()) {
                continue;
            }
            $key = $if->__reference;
            foreach ([['ipaddr', 'subnet'], ['ipaddrv6', 'subnetv6']] as $fields) {
                $has_address = $if->{$fields[0]}->getValue() !== '';
                $has_prefix = $if->{$fields[1]}->getValue() !== '';
                if ($has_address !== $has_prefix) {
                    $msg = gettext('A static IP address and its prefix length must be configured together.');
                    $messages->appendMessage(new Message($msg, $key . '.' . $fields[0]));
                    $messages->appendMessage(new Message($msg, $key . '.' . $fields[1]));
                }
            }
            foreach ([['gateway', 'ipaddr', 'inet'], ['gatewayv6', 'ipaddrv6', 'inet6']] as $fields) {
                $gateway = $if->{$fields[0]}->getValue();
                if ($gateway !== '' && $if->{$fields[1]}->getValue() === '') {
                    $msg = gettext('A gateway requires a static IP address of the same address family.');
                    $messages->appendMessage(new Message($msg, $key . '.' . $fields[0]));
                } elseif ($gateway !== '') {
                    $gateway_found = false;
                    foreach (Config::getInstance()->object()->xpath('//OPNsense/Gateways/gateway_item') as $gateway_node) {
                        if (
                            (string)$gateway_node->name === $gateway &&
                            (string)$gateway_node->interface === $ifname &&
                            (string)$gateway_node->ipprotocol === $fields[2]
                        ) {
                            $gateway_found = true;
                            break;
                        }
                    }
                    if (!$gateway_found) {
                        $msg = gettext('The selected gateway does not exist for this interface and address family.');
                        $messages->appendMessage(new Message($msg, $key . '.' . $fields[0]));
                    }
                }
            }
            if (preg_match('/^bridge[0-9]/', $if->if->getValue())) {
                foreach (Config::getInstance()->object()->xpath('/*/bridges/bridged') as $node) {
                    if (in_array($ifname, explode(',', $node->members))) {
                        $msg = sprintf(
                            gettext('You cannot set device %s to interface %s because it cannot be a member of itself.'),
                            $if->if,
                            $ifname
                        );
                        $messages->appendMessage(new Message($msg, $key . ".if"));
                    }
                }
            }
        }

        return $messages;
    }
}
