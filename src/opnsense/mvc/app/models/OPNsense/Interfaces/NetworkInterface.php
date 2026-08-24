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

    private const RECONFIGURE_FIELDS = ['enable', 'spoofmac', 'promisc', 'mtu'];
    private const FILTER_FIELDS = ['blockpriv', 'blockbogons', 'gateway_interface', 'mss'];
    private const BOOLEAN_FIELDS = ['enable', 'blockpriv', 'blockbogons', 'gateway_interface', 'promisc'];
    private const DHCP4_FIELDS = ['dhcphostname', 'alias-address', 'alias-subnet', 'dhcprejectfrom'];
    private const DHCP6_FIELDS = [
        'dhcp6-ia-pd-len',
        'dhcp6-ia-pd-send-hint',
        'dhcp6prefixonly',
        'dhcp6usev4iface',
        'dhcp6vlanprio'
    ];
    private const TRACK6_FIELDS = ['track6-interface', 'track6-prefix-id', 'track6_assoc_pd'];
    private const DYNAMIC_BOOLEAN_FIELDS = [
        'dhcp6-ia-pd-send-hint',
        'dhcp6prefixonly',
        'dhcp6usev4iface'
    ];
    private const MANAGED_IPV4_MODES = ['none', 'static', 'dhcp'];
    private const MANAGED_IPV6_MODES = ['none', 'static', 'linklocal', 'slaac', 'dhcp6', 'track6', 'idassoc6'];
    private const IDENTIFIER_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    private function identifier_is_reserved($identifier)
    {
        return in_array($identifier, ['lan', 'wan']) || preg_match('/^opt[0-9]+$/', $identifier);
    }

    private function address_mode($address, $family)
    {
        $flag = $family == 6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_IP, $flag) !== false) {
            return 'static';
        }
        return $address === '' ? 'none' : $address;
    }

    private function effective_mode($interface, $family)
    {
        $mode_field = $family == 6 ? 'type6' : 'type';
        $address_field = $family == 6 ? 'ipaddrv6' : 'ipaddr';
        $mode = $interface->$mode_field->getValue();
        if (
            !$interface->$mode_field->isFieldChanged() &&
            $mode != 'static' &&
            $interface->$address_field->isFieldChanged() &&
            $interface->$address_field->getValue() !== ''
        ) {
            return 'static';
        }
        return $mode;
    }

    private function set_config_field($node, $field, $value)
    {
        $boolean_fields = array_merge(self::BOOLEAN_FIELDS, self::DYNAMIC_BOOLEAN_FIELDS);
        if (in_array($field, $boolean_fields)) {
            if ($value == '1') {
                $node->$field = '1';
            } else {
                unset($node->$field);
            }
        } elseif ($value === '') {
            unset($node->$field);
        } else {
            $node->$field = $value;
        }
    }

    private function serialize_family($intf, $model, $data, $family)
    {
        if ($family == 6) {
            $mode_field = 'type6';
            $address_field = 'ipaddrv6';
            $subnet_field = 'subnetv6';
            $gateway_field = 'gatewayv6';
            $all_dynamic = array_merge(self::DHCP6_FIELDS, self::TRACK6_FIELDS);
        } else {
            $mode_field = 'type';
            $address_field = 'ipaddr';
            $subnet_field = 'subnet';
            $gateway_field = 'gateway';
            $all_dynamic = self::DHCP4_FIELDS;
        }

        $mode = $data[$mode_field];
        $mode_changed = $model->$mode_field->isFieldChanged();
        if (
            !$mode_changed &&
            $mode != 'static' &&
            $model->$address_field->isFieldChanged() &&
            $data[$address_field] !== ''
        ) {
            /* Backwards compatibility for the static-address-only API payload. */
            $mode = 'static';
            $mode_changed = true;
        }
        $changed = $mode_changed;
        if ($mode_changed) {
            foreach (array_merge([$address_field, $subnet_field, $gateway_field], $all_dynamic) as $field) {
                unset($intf->$field);
            }
            if ($mode == 'static') {
                foreach ([$address_field, $subnet_field, $gateway_field] as $field) {
                    $this->set_config_field($intf, $field, $data[$field]);
                }
            } elseif ($mode != 'none') {
                $intf->$address_field = $mode;
            }
        } elseif ($mode == 'static') {
            foreach ([$address_field, $subnet_field, $gateway_field] as $field) {
                if ($model->$field->isFieldChanged()) {
                    $this->set_config_field($intf, $field, $data[$field]);
                    $changed = true;
                }
            }
        }

        $active_fields = [];
        if ($family == 4 && $mode == 'dhcp') {
            $active_fields = self::DHCP4_FIELDS;
        } elseif ($family == 6 && $mode == 'dhcp6') {
            $active_fields = self::DHCP6_FIELDS;
        } elseif ($family == 6 && in_array($mode, ['track6', 'idassoc6'])) {
            $active_fields = self::TRACK6_FIELDS;
        }
        foreach ($active_fields as $field) {
            if ($mode_changed || $model->$field->isFieldChanged()) {
                $this->set_config_field($intf, $field, $data[$field]);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * @param array $payload data to store
     */
    private function store_if_todo($id, $payload)
    {
        $fobj = new FileObject($this->todo_file, 'a+', 0600, LOCK_EX);
        $data = $fobj->readJson() ?? [];
        $current_action = $data[$id]['pending_action'] ?? '';
        $next_action = $payload['pending_action'] ?? '';
        if (
            (in_array($current_action, ['delete', 'relink']) && in_array($next_action, ['filter', 'reconfigure'])) ||
            ($current_action == 'reconfigure' && $next_action == 'filter')
        ) {
            unset($payload['pending_action']);
        }
        if (!empty($payload['pending_families'])) {
            $payload['pending_families'] = array_values(array_unique(array_merge(
                $data[$id]['pending_families'] ?? [],
                $payload['pending_families']
            )));
        }
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
            $node->identifier->markUnchanged();
            $node->lock = empty((string)$intf->lock) ? '0' : '1';
            foreach (array_merge(self::RECONFIGURE_FIELDS, self::FILTER_FIELDS) as $field) {
                if (in_array($field, self::BOOLEAN_FIELDS)) {
                    $node->$field = empty((string)$intf->$field) ? '0' : '1';
                } else {
                    $node->$field = (string)$intf->$field;
                }
                $node->$field->markUnchanged();
            }
            $node->type = $this->address_mode((string)$intf->ipaddr, 4);
            $node->type6 = $this->address_mode((string)$intf->ipaddrv6, 6);
            $node->type->markUnchanged();
            $node->type6->markUnchanged();
            foreach (array_merge(self::DHCP4_FIELDS, self::DHCP6_FIELDS, self::TRACK6_FIELDS) as $field) {
                if (in_array($field, self::DYNAMIC_BOOLEAN_FIELDS)) {
                    $node->$field = empty((string)$intf->$field) ? '0' : '1';
                } else {
                    $node->$field = (string)$intf->$field;
                }
                $node->$field->markUnchanged();
            }
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
                $node->$addr_field->markUnchanged();
                $node->$subnet_field->markUnchanged();
                $node->$gateway_field->markUnchanged();
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
                $pending_action = '';
                foreach (array_merge(self::RECONFIGURE_FIELDS, self::FILTER_FIELDS) as $field) {
                    if (!$model_interfaces[$key]->$field->isFieldChanged()) {
                        continue;
                    }
                    $value = $interfaces[$key][$field];
                    if (in_array($field, self::BOOLEAN_FIELDS)) {
                        if ($value == '1') {
                            $intf->$field = '1';
                        } else {
                            unset($intf->$field);
                        }
                    } elseif ($value === '') {
                        unset($intf->$field);
                    } else {
                        $intf->$field = $value;
                    }
                    if (in_array($field, self::RECONFIGURE_FIELDS)) {
                        $pending_action = 'reconfigure';
                    } elseif ($pending_action === '') {
                        $pending_action = 'filter';
                    }
                }
                if ($pending_action !== '') {
                    $this->store_if_todo($key, ['pending_action' => $pending_action]);
                }
                $changed_families = [];
                foreach ([4, 6] as $family) {
                    if ($this->serialize_family($intf, $model_interfaces[$key], $interfaces[$key], $family)) {
                        $changed_families[] = $family;
                    }
                }
                if (!empty($changed_families)) {
                    $this->store_if_todo($key, [
                        'pending_action' => 'reconfigure',
                        'pending_families' => $changed_families
                    ]);
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
                $requested_key = trim($intf['identifier']);
                if ($requested_key !== '') {
                    $new_key = $requested_key;
                } else {
                    while (in_array('opt' . $next_if, $existing_ifnames)) {
                        $next_if++;
                    }
                    $new_key = 'opt' . $next_if;
                    $next_if++;
                }
                $newif = Config::getInstance()->object()->interfaces->addChild($new_key);
                $newif->if = $intf['if'];
                $newif->descr = $intf['descr'];
                $newif->lock = $intf['lock'];
                foreach (array_merge(self::RECONFIGURE_FIELDS, self::FILTER_FIELDS) as $field) {
                    $value = $intf[$field];
                    if ((in_array($field, self::BOOLEAN_FIELDS) && $value == '1') ||
                        (!in_array($field, self::BOOLEAN_FIELDS) && $value !== '')) {
                        $newif->$field = $value;
                    }
                }
                $this->serialize_family($newif, $model_interfaces[$key], $intf, 4);
                $this->serialize_family($newif, $model_interfaces[$key], $intf, 6);
                $this->store_if_todo($new_key, ['pending_action' => 'reconfigure']);
                $existing_ifnames[] = $new_key;
            }
        }
        return true;
    }

    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);
        $pending_identifiers = [];
        foreach ($this->interface->iterateItems() as $ifname => $if) {
            if (!$validateFullModel && !$if->isFieldChanged()) {
                continue;
            }
            $key = $if->__reference;
            $identifier = trim($if->identifier->getValue());
            $is_existing = isset(Config::getInstance()->object()->interfaces->$ifname);
            if ($is_existing && $identifier !== $ifname) {
                $messages->appendMessage(new Message(
                    gettext('The interface identifier is immutable after creation.'),
                    $key . '.identifier'
                ));
            } elseif (!$is_existing && $identifier !== '') {
                if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
                    $messages->appendMessage(new Message(
                        gettext('The interface identifier must start with a lowercase letter and contain only lowercase letters, digits, and underscores (maximum 32 characters).'),
                        $key . '.identifier'
                    ));
                } elseif ($this->identifier_is_reserved($identifier)) {
                    $messages->appendMessage(new Message(
                        gettext('The interface identifier is reserved for automatic or built-in interface assignments.'),
                        $key . '.identifier'
                    ));
                } elseif (isset(Config::getInstance()->object()->interfaces->$identifier) || isset($pending_identifiers[$identifier])) {
                    $messages->appendMessage(new Message(
                        gettext('The interface identifier is already in use.'),
                        $key . '.identifier'
                    ));
                } else {
                    $pending_identifiers[$identifier] = true;
                }
            }
            foreach ([
                4 => ['type', 'ipaddr', 'subnet', 'gateway', 'inet', self::MANAGED_IPV4_MODES],
                6 => ['type6', 'ipaddrv6', 'subnetv6', 'gatewayv6', 'inet6', self::MANAGED_IPV6_MODES]
            ] as $family => $fields) {
                [$mode_field, $address_field, $subnet_field, $gateway_field, $protocol, $managed_modes] = $fields;
                $mode = $this->effective_mode($if, $family);
                $mode_changed = $if->$mode_field->isFieldChanged();
                $config_address = isset(Config::getInstance()->object()->interfaces->$ifname) ?
                    (string)Config::getInstance()->object()->interfaces->$ifname->$address_field : '';
                $current_mode = $this->address_mode($config_address, $family);
                if ($mode_changed && !in_array($mode, $managed_modes) && $mode != $current_mode) {
                    $msg = gettext('This address mode is managed by another interface subsystem.');
                    $messages->appendMessage(new Message($msg, $key . '.' . $mode_field));
                }

                $has_address = $if->$address_field->getValue() !== '';
                $has_prefix = $if->$subnet_field->getValue() !== '';
                if ($mode == 'static' && (!$has_address || !$has_prefix)) {
                    $msg = gettext('A static IP address and its prefix length must be configured together.');
                    $messages->appendMessage(new Message($msg, $key . '.' . $address_field));
                    $messages->appendMessage(new Message($msg, $key . '.' . $subnet_field));
                } elseif ($mode != 'static' && !$mode_changed) {
                    foreach ([$address_field, $subnet_field, $gateway_field] as $field) {
                        if ($if->$field->isFieldChanged() && $if->$field->getValue() !== '') {
                            $msg = gettext('Static addressing fields require static address mode.');
                            $messages->appendMessage(new Message($msg, $key . '.' . $field));
                        }
                    }
                }

                $gateway = $if->$gateway_field->getValue();
                if ($mode == 'static' && $gateway !== '' && !$has_address) {
                    $msg = gettext('A gateway requires a static IP address of the same address family.');
                    $messages->appendMessage(new Message($msg, $key . '.' . $gateway_field));
                } elseif ($mode == 'static' && $gateway !== '') {
                    $gateway_found = false;
                    foreach (Config::getInstance()->object()->xpath('//OPNsense/Gateways/gateway_item') as $gateway_node) {
                        if (
                            (string)$gateway_node->name === $gateway &&
                            (string)$gateway_node->interface === $ifname &&
                            (string)$gateway_node->ipprotocol === $protocol
                        ) {
                            $gateway_found = true;
                            break;
                        }
                    }
                    if (!$gateway_found) {
                        $msg = gettext('The selected gateway does not exist for this interface and address family.');
                        $messages->appendMessage(new Message($msg, $key . '.' . $gateway_field));
                    }
                }
            }

            $mode_requirements = [
                'dhcp' => self::DHCP4_FIELDS,
                'dhcp6' => self::DHCP6_FIELDS,
                'track6' => self::TRACK6_FIELDS,
                'idassoc6' => self::TRACK6_FIELDS
            ];
            foreach ($mode_requirements as $required_mode => $fields) {
                $actual_mode = $required_mode == 'dhcp' ? $this->effective_mode($if, 4) : $this->effective_mode($if, 6);
                foreach ($fields as $field) {
                    $value = $if->$field->getValue();
                    if ($if->$field->isFieldChanged() && $value !== '' && $value != '0' && $actual_mode != $required_mode) {
                        if (!in_array($actual_mode, ['track6', 'idassoc6']) || !in_array($required_mode, ['track6', 'idassoc6'])) {
                            $msg = sprintf(gettext('Field %s is not valid for the selected address mode.'), $field);
                            $messages->appendMessage(new Message($msg, $key . '.' . $field));
                        }
                    }
                }
            }

            if ($this->effective_mode($if, 4) == 'dhcp') {
                $has_alias = $if->{'alias-address'}->getValue() !== '';
                $has_alias_subnet = $if->{'alias-subnet'}->getValue() !== '';
                if ($has_alias !== $has_alias_subnet) {
                    $msg = gettext('A DHCP alias address and its prefix length must be configured together.');
                    $messages->appendMessage(new Message($msg, $key . '.alias-address'));
                    $messages->appendMessage(new Message($msg, $key . '.alias-subnet'));
                }
                foreach (array_filter(array_map('trim', explode(',', $if->dhcprejectfrom->getValue()))) as $address) {
                    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        $msg = gettext('A valid IPv4 address list must be specified for rejected DHCP servers.');
                        $messages->appendMessage(new Message($msg, $key . '.dhcprejectfrom'));
                        break;
                    }
                }
            }

            $ipv6_mode = $this->effective_mode($if, 6);
            if (in_array($ipv6_mode, ['track6', 'idassoc6'])) {
                $track_interface = $if->{'track6-interface'}->getValue();
                if ($track_interface === '') {
                    $messages->appendMessage(new Message(
                        gettext('A tracked interface is required.'),
                        $key . '.track6-interface'
                    ));
                } elseif ($track_interface == $ifname) {
                    $messages->appendMessage(new Message(
                        gettext('An interface cannot track itself.'),
                        $key . '.track6-interface'
                    ));
                } elseif (!isset(Config::getInstance()->object()->interfaces->$track_interface)) {
                    $messages->appendMessage(new Message(
                        gettext('The tracked interface does not exist.'),
                        $key . '.track6-interface'
                    ));
                } else {
                    $tracked = Config::getInstance()->object()->interfaces->$track_interface;
                    $tracked_mode = $this->address_mode((string)$tracked->ipaddrv6, 6);
                    if (!in_array($tracked_mode, ['dhcp6', 'slaac'])) {
                        $messages->appendMessage(new Message(
                            gettext('The tracked interface must use DHCPv6 or SLAAC.'),
                            $key . '.track6-interface'
                        ));
                    }
                    $prefix_id = $if->{'track6-prefix-id'}->getValue();
                    $delegation = (string)$tracked->{'dhcp6-ia-pd-len'};
                    if ($prefix_id !== '' && ctype_digit($delegation) && $delegation >= 48 && $delegation <= 64) {
                        $maximum = (2 ** (64 - (int)$delegation)) - 1;
                        if ((int)$prefix_id > $maximum) {
                            $messages->appendMessage(new Message(
                                gettext('The tracked prefix ID is outside the delegated prefix range.'),
                                $key . '.track6-prefix-id'
                            ));
                        }
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
