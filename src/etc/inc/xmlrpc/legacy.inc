<?php

require_once(__DIR__ . "/../ha_sync_route_guard.inc");

/*
 * Copyright (C) 2015-2025 Deciso B.V.
 * Copyright (C) 2004-2010 Scott Ullrich <sullrich@gmail.com>
 * Copyright (C) 2005 Colin Smith <ethethlay@gmail.com>
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

/**
 * request functions which may be registered by the xmlrpc system
 * @return array
 */
function xmlrpc_publishable_legacy()
{
    $publish = [
       'filter_configure_xmlrpc',
       'firmware_version_xmlrpc',
       'restore_config_section_xmlrpc',
    ];

    return $publish;
}

/*
 * does_vip_exist($vip): return true or false if a vip is
 * configured.
 */
function does_vip_exist($vip)
{
    if (!$vip) {
        return false;
    }

    switch ($vip['mode']) {
        case "carp":
        case "ipalias":
            /* XXX: Make proper checks? */
            $device = get_real_interface($vip['interface']);
            if (!does_interface_exist($device)) {
                return false;
            }
            break;
        case "proxyarp":
            /* XXX: Implement this */
        default:
            return false;
    }

    foreach (array_keys(interfaces_addresses($device, true)) as $vipips) {
        if ($vipips == "{$vip['subnet']}/{$vip['subnet_bits']}") {
            return true;
        }
    }

    return false;
}

/**
 * Preserve node-local firewall/NAT rules when applying HA synchronised rules.
 *
 * These lists are order-sensitive: copying the primary's list would erase
 * receiver-local nosync rules, while prepending all local rules would move
 * their precedence. Fill old synchronized slots from the incoming sequence
 * and retain each local nosync rule in its previous relative position.
 * Excess incoming rules append in sender order; removed synced rules vanish.
 *
 * The sender's remove_nosync() removes its own local rules. Reject unexpected
 * nosync records received over XMLRPC, rather than adopting another node's
 * local rule. No UUID capture/handoff is involved.
 */
function ha_xmlrpc_merge_ordered_nosync($source, $local): array
{
    $incoming = ha_xmlrpc_config_rows($source);
    $existing = ha_xmlrpc_config_rows($local);
    foreach ($incoming as $row) {
        if (!is_array($row) || !empty($row['nosync'])) {
            throw new RuntimeException('HA synchronized ordered rules contain an invalid or node-local record');
        }
    }
    foreach ($existing as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('local ordered rules contain an invalid record');
        }
    }

    $result = [];
    $next = 0;
    foreach ($existing as $row) {
        if (!empty($row['nosync'])) {
            $result[] = $row;
        } elseif ($next < count($incoming)) {
            $result[] = $incoming[$next++];
        }
    }
    for (; $next < count($incoming); ++$next) {
        $result[] = $incoming[$next];
    }
    return $result;
}

/**
 * Merge source attributes into destination while preserving node-local items.
 * Ordered firewall/NAT collections require a position-preserving merge.
 */
function merge_config_attributes(&$cnf_source, &$cnf_dest, $path = '')
{
    foreach ($cnf_source as $cnf_key => &$cnf_value) {
        if (is_array($cnf_value)) {
            // Compute the path per child. Mutating $path across siblings
            // incorrectly changes later paths (filter.rule -> nat.rule).
            $child_path = $path === '' ? $cnf_key : sprintf('%s.%s', $path, $cnf_key);
            if (in_array($child_path, ['filter.rule', 'nat.rule', 'nat.outbound.rule'], true)) {
                $cnf_dest[$cnf_key] = ha_xmlrpc_merge_ordered_nosync(
                    $cnf_value, $cnf_dest[$cnf_key] ?? []
                );
                continue;
            }
            $no_sync_items = [];
            if (
                !isset($cnf_dest[$cnf_key]) || !is_array($cnf_dest[$cnf_key]) || // new
                (count($cnf_dest[$cnf_key]) > 0 && isset($cnf_dest[$cnf_key][0])) || // sequenced item
                (count($cnf_dest[$cnf_key]) > 0 && isset($cnf_dest[$cnf_key]['@attributes']['uuid'])) // mvc array
            ) {
                if (isset($cnf_dest[$cnf_key]) && is_array($cnf_dest[$cnf_key])) {
                    foreach (ha_xmlrpc_config_rows($cnf_dest[$cnf_key]) as $record) {
                        if (is_array($record) && !empty($record['nosync'])) {
                            $no_sync_items[] = $record;
                        }
                    }
                }
                // (re)set destination array when new or containing a sequenced list of items
                $cnf_dest[$cnf_key] = [];
            }
            merge_config_attributes($cnf_value, $cnf_dest[$cnf_key], $child_path);
            if (!empty($no_sync_items)) {
                /* append nosync items, local ones on-top */
                $cnf_dest[$cnf_key] = array_merge($no_sync_items, $cnf_dest[$cnf_key]);
            }
        } else {
            $cnf_dest[$cnf_key] = $cnf_value;
        }
    }
}

/**
 * retrieve firmware version
 * @return array
 */
function firmware_version_xmlrpc()
{
    return [
        'base' => ['version' => shell_safe('opnsense-version -v base')],
        'firmware' => ['version' => shell_safe('opnsense-version -v core')],
        'kernel' => ['version' => shell_safe('opnsense-version -v kernel')],
    ];
}

function ha_xmlrpc_config_rows($value)
{
    if (!is_array($value)) {
        return [];
    }
    if (array_is_list($value)) {
        return array_values($value);
    }
    // remove_nosync() intentionally unsets source rows and leaves gaps in
    // numeric indexes. Those are still ordered rows, NOT one MVC singleton.
    if (count(array_filter(array_keys($value), 'is_int')) === count($value)) {
        return array_values($value);
    }
    return [$value];
}

function ha_xmlrpc_canonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('ha_xmlrpc_canonicalize', $value);
    }
    ksort($value);
    foreach ($value as $key => $entry) {
        $value[$key] = ha_xmlrpc_canonicalize($entry);
    }
    return $value;
}

function ha_xmlrpc_gateway_map(array $source)
{
    $result = [];
    foreach ([
        $source['gateways']['gateway_item'] ?? [],
        $source['OPNsense']['Gateways']['gateway_item'] ?? [],
    ] as $container) {
        foreach (ha_xmlrpc_config_rows($container) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') {
                $result[$name] = $row;
            }
        }
    }
    return $result;
}

function ha_xmlrpc_route_map(array $source)
{
    $result = [];
    foreach (ha_xmlrpc_config_rows($source['staticroutes']['route'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uuid = trim((string)($row['@attributes']['uuid'] ?? ''));
        if ($uuid !== '') {
            $key = 'uuid:' . $uuid;
        } else {
            $key = 'row:' . hash('sha256', json_encode(ha_xmlrpc_canonicalize($row)));
        }
        $result[$key] = $row;
    }
    return $result;
}

function ha_xmlrpc_gateway_interface(array $gateways, $name)
{
    $name = trim((string)$name);
    if ($name === '' || !isset($gateways[$name])) {
        return '';
    }
    return trim((string)($gateways[$name]['interface'] ?? ''));
}

function ha_xmlrpc_delete_static_route(array $route)
{
    if (!empty($route['disabled'])) {
        return;
    }
    $network = trim((string)($route['network'] ?? ''));
    if (is_subnetv4($network)) {
        $family = 'inet';
    } elseif (is_subnetv6($network)) {
        $family = 'inet6';
    } else {
        return;
    }
    mwexecfm('/sbin/route delete -%s %s', [$family, $network]);
}

function ha_xmlrpc_apply_routing_diff(array $old_config, array $new_config)
{
    $old_gateways = ha_xmlrpc_gateway_map($old_config);
    $new_gateways = ha_xmlrpc_gateway_map($new_config);
    $old_routes = ha_xmlrpc_route_map($old_config);
    $new_routes = ha_xmlrpc_route_map($new_config);
    $interfaces = [];

    foreach (array_unique(array_merge(array_keys($old_gateways), array_keys($new_gateways))) as $name) {
        $old = $old_gateways[$name] ?? null;
        $new = $new_gateways[$name] ?? null;
        if (ha_xmlrpc_canonicalize($old) === ha_xmlrpc_canonicalize($new)) {
            continue;
        }
        foreach ([$old, $new] as $gateway) {
            if (is_array($gateway)) {
                $interface = trim((string)($gateway['interface'] ?? ''));
                if ($interface !== '') {
                    $interfaces[$interface] = true;
                }
            }
        }
    }

    foreach (array_unique(array_merge(array_keys($old_routes), array_keys($new_routes))) as $key) {
        $old = $old_routes[$key] ?? null;
        $new = $new_routes[$key] ?? null;
        if (ha_xmlrpc_canonicalize($old) === ha_xmlrpc_canonicalize($new)) {
            continue;
        }
        if (is_array($old)) {
            ha_xmlrpc_delete_static_route($old);
            $interface = ha_xmlrpc_gateway_interface($old_gateways, $old['gateway'] ?? '');
            if ($interface !== '') {
                $interfaces[$interface] = true;
            }
        }
        if (is_array($new)) {
            $interface = ha_xmlrpc_gateway_interface($new_gateways, $new['gateway'] ?? '');
            if ($interface !== '') {
                $interfaces[$interface] = true;
            }
        }
    }

    if (!empty($interfaces)) {
        system_routing_configure(false, array_keys($interfaces));
    }
}

/**
 * filter reconfigure
 * @return mixed
 */
function filter_configure_xmlrpc($syncitems = null)
{
    require_once("filter.inc");
    require_once("system.inc");
    require_once("util.inc");
    require_once("interfaces.inc");

    if ($syncitems === null) {
        system_routing_configure();
        system_resolver_configure();
        filter_configure();
        return true;
    }
    if (!is_array($syncitems)) {
        throw new InvalidArgumentException('selective XMLRPC filter configure expects a sync item list');
    }
    $syncitems = array_values(array_unique(array_filter(array_map('trim', $syncitems))));
    if (array_intersect($syncitems, ['virtualip', 'rules', 'staticroutes', 'aliases'])) {
        filter_configure();
    }

    return true;
}

/**
 * Normalize native/MVC singleton or list storage without changing it.
 */
function ha_xmlrpc_policy_records($root, string $item): array
{
    if (!is_array($root) || $root === []) {
        return [];
    }
    $rows = $root[$item] ?? [];
    if ($rows === '' || $rows === null || $rows === []) {
        return [];
    }
    if (!is_array($rows)) {
        throw new RuntimeException('invalid HA policy records');
    }
    return array_is_list($rows) ? $rows : [$rows];
}

/**
 * Keep CARP skew as a RECEIVER-local setting for synchronized interfaces.
 *
 * The native sender may still add 100 (legacy Etna -> new Rigi). We override
 * that value ONLY on interfaces whose locally assigned HA policy synchronizes.
 * Core/non-endpoint CARP entries retain their previous receiver skew when
 * present. This must execute before restore_config_section_xmlrpc merges the
 * incoming VIPs and before it computes its old-vs-new reconfigure plan.
 */
function ha_xmlrpc_apply_receiver_carp_skew(array &$incoming, array $local): void
{
    if (!isset($incoming['virtualip']['vip'])) {
        return;
    }
    $root = $local['OPNsense']['ApiExtensions']['InterfaceSyncPolicy'] ?? [];
    $policies = [];
    $policiesByUuid = [];
    foreach (ha_xmlrpc_policy_records($root['shared']['policies'] ?? [], 'policy') as $row) {
        $id = trim((string)($row['id'] ?? ''));
        $uuid = trim((string)($row['@attributes']['uuid'] ?? ''));
        if ($id === '' || $uuid === '') {
            throw new RuntimeException('invalid local HA sync policy');
        }
        $enabled = in_array($row['synchronize'] ?? null, [1, '1', true, 'yes', 'on'], true);
        $policies[$id] = $enabled;
        $policiesByUuid[$uuid] = $id;
    }

    $scope = [];
    foreach (ha_xmlrpc_policy_records($root['assignments'] ?? [], 'assignment') as $row) {
        $identifier = trim((string)($row['interface'] ?? ''));
        $policyRef = trim((string)($row['policy_id'] ?? ''));
        $policyId = $policiesByUuid[$policyRef] ?? $policyRef;
        if ($identifier === '' || !array_key_exists($policyId, $policies)) {
            throw new RuntimeException('invalid local HA policy assignment');
        }
        if ($policies[$policyId]) {
            $scope[$identifier] = true;
        }
    }

    $rawSkew = $local['hasync']['local_endpoint_carp_advskew'] ?? null;
    $skew = null;
    if ($rawSkew !== null && $rawSkew !== '') {
        $text = (string)$rawSkew;
        if (!preg_match('/^(0|[1-9][0-9]{0,2})$/D', $text) || (int)$text > 254) {
            throw new RuntimeException('local endpoint CARP advskew must be an integer between 0 and 254');
        }
        $skew = $text;
    }

    $currentByKey = [];
    foreach ($local['virtualip']['vip'] ?? [] as $vip) {
        if (is_array($vip) && !empty($vip['vhid'])) {
            $currentByKey[get_unique_vip_key($vip)] = $vip;
        }
    }
    foreach ($incoming['virtualip']['vip'] as &$vip) {
        if (!is_array($vip) || ($vip['mode'] ?? '') !== 'carp' || empty($vip['vhid'])) {
            continue;
        }
        $interface = trim((string)($vip['interface'] ?? ''));
        if (isset($scope[$interface])) {
            if ($skew === null) {
                throw new RuntimeException(
                    'configure local endpoint CARP advskew before receiving synchronized CARP VIPs'
                );
            }
            $vip['advskew'] = $skew;
        } else {
            $key = get_unique_vip_key($vip);
            if (isset($currentByKey[$key]['advskew'])) {
                $vip['advskew'] = $currentByKey[$key]['advskew'];
            }
        }
    }
    unset($vip);
}

/**
 * restore config section
 * @param $new_config
 * @return bool
 */
function restore_config_section_xmlrpc($new_config)
{
    global $config;
    // lock config write during merge
    OPNsense\Core\Config::getInstance()->lock();

    require_once("interfaces.inc");
    require_once("filter.inc");
    require_once("system.inc");
    require_once("util.inc");

    // save old config
    $old_config = $config;

    // Native staticroutes is operator-selectable, but the local bootstrap
    // connected network is never an HA-owned destination. Keep any
    // preexisting receiver-local route and drop a conflicting sender route.
    $suppressed = ha_sync_guard_bootstrap_routes($new_config, $config, true);
    if ($suppressed > 0) {
        log_msg(sprintf('HA XMLRPC ignored %d receiver bootstrap-overlapping static routes', $suppressed), LOG_WARNING);
    }

    ha_xmlrpc_apply_receiver_carp_skew($new_config, $config);

    $vhidVipsInNewConfig = [];
    if (isset($new_config['virtualip']['vip'])) {
        foreach ($new_config['virtualip']['vip'] as $vip) {
            $vhidVipsInNewConfig[get_unique_vip_key($vip)] = $vip;
        }
    }

    $vipbackup = [];
    $oldvips = [];
    if (isset($new_config['virtualip']['vip']) && isset($config['virtualip']['vip'])) {
        foreach ($config['virtualip']['vip'] as $vipindex => $vip) {
            $vipKey = get_unique_vip_key($vip);
            if (!empty($vip['vhid']) && empty($vip['nosync'])) {
                // rc.filter_synchronize only sends CARP VIPs and IP Aliases with a VHID. Keep the rest like it was.
                $oldvips[$vipKey] = $vip;
                // Remove entries that are present locally, but are not present in the new config.
                if (!array_key_exists($vipKey, $vhidVipsInNewConfig)) {
                    unset($config['virtualip']['vip'][$vipindex]);
                }
            } elseif (!empty($vip['nosync'])) {
                null; /* merge_config_attributes() will preserve these records */
            } elseif (!isset($vhidVipsInNewConfig[$vipKey])) {
                $vipbackup[$vipKey] = $vip;
            }
        }
    }

    // merge config attributes.
    merge_config_attributes($new_config, $config);

    if (count($vipbackup) > 0) {
        // if $new_config contained VIPs and the old config contained VIPs with no VHID, prepend original VIPs
        foreach ($vipbackup as $vip) {
            array_unshift($config['virtualip']['vip'], $vip);
        }
    }

    /* Log what happened */
    $mergedkeys = implode(",", array_merge(array_keys($new_config)));
    write_config(sprintf('Merged %s config sections from XMLRPC client.', $mergedkeys));

    /*
     * Handle virtual ips
     */
    if (isset($new_config['virtualip']['vip'])) {
        $proxyarp_interfaces = [];
        $pfsync = false;

        if (isset($config['virtualip']['vip'])) {
            foreach ($config['virtualip']['vip'] as $vip) {
                if (!empty($vip['nosync'])) {
                    continue; // node-local VIPs are preserved locally and must never be reconfigured by peer sync
                }
                $vipKey = get_unique_vip_key($vip);
                if (empty($vip['vhid']) && isset($vipbackup[$vipKey])) {
                    // This is a local non-VHID VIP preserved across the merge, not
                    // part of the synchronized virtualip payload. Leave it alone.
                    continue;
                }
                if (!empty($vip['vhid']) && isset($oldvips[$vipKey])) {
                    $is_changed = false;
                    foreach (['password', 'advskew', 'subnet', 'subnet_bits', 'advbase'] as $chk_key) {
                        if ($oldvips[$vipKey][$chk_key] != $vip[$chk_key]) {
                            $is_changed = true;
                            break;
                        }
                    }
                    if (!$is_changed) {
                        unset($oldvips[$vipKey]);
                        if (does_vip_exist($vip)) {
                            continue; // Skip reconfiguring this VIP since nothing has changed.
                        }
                    }
                }

                switch ($vip['mode']) {
                    case "proxyarp":
                        if (!empty($vip['interface'])) {
                            $proxyarp_interfaces[$vip['interface']] = true;
                        }
                        break;
                    case "ipalias":
                        interface_ipalias_configure($vip);
                        break;
                    case "carp":
                        interface_carp_configure($vip);
                        $pfsync = true;
                        break;
                }
            }
        }

        // remove old (carp/ipalias-with-vhid) virtual ip's
        foreach ($oldvips as $oldvip) {
            if (($oldvip['mode'] ?? '') === 'proxyarp' && !empty($oldvip['interface'])) {
                $proxyarp_interfaces[$oldvip['interface']] = true;
            }
            interface_vip_bring_down($oldvip);
        }

        if ($pfsync) {
            interfaces_pfsync_configure();
        }

        foreach (array_keys($proxyarp_interfaces) as $interface) {
            interface_proxyarp_configure($interface);
        }
    }

    if (
        array_key_exists('staticroutes', $new_config) ||
        array_key_exists('gateways', $new_config) ||
        isset($new_config['OPNsense']['Gateways'])
    ) {
        ha_xmlrpc_apply_routing_diff($old_config, $config);
    }

    if (isset($old_config['ipsec']['enable']) !== isset($config['ipsec']['enable'])) {
        plugins_configure('ipsec');
    }

    unset($old_config);

    return true;
}

function get_unique_vip_key($vip)
{
    if ($vip['mode'] === 'carp') {
        return "{$vip['mode']}_{$vip['interface']}_vip{$vip['vhid']}";
    } else {
        return "{$vip['mode']}_{$vip['interface']}_vip{$vip['vhid']}_{$vip['subnet']}_{$vip['subnet_bits']}";
    }
}
