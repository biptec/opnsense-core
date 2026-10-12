<?php

/*
 * Issue #103: protect per-node bootstrap connectivity from native XMLRPC
 * staticroutes, without disabling the explicitly synchronized endpoint routes.
 * Pure source/receiver config fixtures; no network or router required.
 */
require_once(__DIR__ . '/../src/etc/inc/ha_sync_route_guard.inc');

function check_same($expected, $actual, $why): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$why}\nwant=" . var_export($expected, true) . "\ngot=" . var_export($actual, true) . "\n");
        exit(1);
    }
}

function check_error(callable $run, string $why): void
{
    try {
        $run();
    } catch (RuntimeException $error) {
        return;
    }
    fwrite(STDERR, "FAIL: {$why} (missing RuntimeException)\n");
    exit(1);
}

$etna = [
    'interfaces' => [
        'lan' => ['ipaddr' => '10.200.0.180', 'subnet' => '24', 'descr' => 'Bootstrap (lan)'],
        'ep_coder' => ['ipaddr' => '10.32.200.1', 'subnet' => '30', 'descr' => 'Coder endpoint'],
        'opt_ha' => ['ipaddr' => '10.16.224.1', 'subnet' => '30', 'descr' => 'HA Control'],
    ],
    'staticroutes' => ['route' => [
        ['network' => '10.200.0.0/24', 'gateway' => 'nc-ep-coder-v4', 'descr' => 'Coder routed-test network'],
    ]],
];
$rigi = $etna;
$rigi['interfaces']['lan']['ipaddr'] = '10.200.0.181';
$rigi['staticroutes']['route'] = [];
$endpointV4 = ['network' => '203.0.113.0/24', 'gateway' => 'endpoint-v4', 'descr' => 'Issue103 v4'];
$endpointV6 = ['network' => '2001:db8:103::/64', 'gateway' => 'endpoint-v6', 'descr' => 'Issue103 v6'];
$legacyCoder = $etna['staticroutes']['route'][0];
$coderHost = ['network' => '10.200.0.202/32', 'gateway' => 'nc-ep-coder-v4', 'descr' => 'Coder host return route'];
$incoming = ['staticroutes' => ['route' => [$legacyCoder, $endpointV4, $endpointV6]]];

check_same(true, ha_sync_cidrs_overlap('10.200.0.0/24', '10.200.0.181/24'), 'same /24');
check_same(true, ha_sync_cidrs_overlap('10.200.0.128/25', '10.200.0.181/24'), 'subnet /25 overlap');
check_same(false, ha_sync_cidrs_overlap('203.0.113.0/24', '10.200.0.181/24'), 'different network');
check_same(false, ha_sync_cidrs_overlap('2001:db8::/32', '10.200.0.181/24'), 'different IP families');
check_same(true, ha_sync_cidrs_overlap('2001:db8:103::/64', '2001:db8:103::7/64'), 'IPv6 direct prefix');
check_same(false, ha_sync_cidrs_overlap('2001:db8:104::/64', '2001:db8:103::7/64'), 'IPv6 other /64');

check_same(true, ha_sync_bootstrap_route_conflict($legacyCoder, $rigi), 'Coder bootstrap route conflicts with Rigi vtnet0');
check_same(false, ha_sync_bootstrap_route_conflict($endpointV4, $rigi), 'IPv4 documentation endpoint route is safe');
check_same(false, ha_sync_bootstrap_route_conflict($endpointV6, $rigi), 'IPv6 documentation endpoint route is safe');
check_same(false, ha_sync_bootstrap_route_conflict(['network' => '0.0.0.0/0'], $rigi), 'default route less specific than connected');
check_same(false, ha_sync_bootstrap_route_conflict(['network' => '10.200.0.0/16'], $rigi), 'supernet cannot displace connected /24');
check_same(true, ha_sync_bootstrap_route_conflict(['network' => '10.200.0.128/25'], $rigi), 'more-specific bad route must be suppressed');
check_same(false, ha_sync_bootstrap_route_conflict($coderHost, $etna), 'Etna must keep explicit Coder /32 return route');
check_same(false, ha_sync_bootstrap_route_conflict($coderHost, $rigi), 'Rigi must receive explicit Coder /32 return route');
check_same(true, ha_sync_bootstrap_route_conflict(['network' => '10.200.0.180/32'], $etna), 'never hijack Etna own bootstrap address');
check_same(true, ha_sync_bootstrap_route_conflict(['network' => '10.200.0.181/32'], $rigi), 'never hijack Rigi own bootstrap address');
check_same(false, ha_sync_bootstrap_route_conflict(['network' => '10.200.0.202/128'], $rigi), 'invalid family is left to native route validation');
check_same(false, ha_sync_bootstrap_route_conflict(['network' => '10.32.200.0/30'], $rigi), 'endpoint VLAN route is not a bootstrap connected route');

$onWire = $incoming;
$old = $etna;
check_same(1, ha_sync_guard_bootstrap_routes($onWire, $old, false), 'sender filters Coder routed-test bootstrap conflict');
check_same([$endpointV4, $endpointV6], $onWire['staticroutes']['route'], 'endpoint routes still on wire');
check_same($old, $etna, 'send guard never mutates live config');

$recv = $incoming;
check_same(1, ha_sync_guard_bootstrap_routes($recv, $rigi, true), 'receiver protects itself from old unguarded Etna payload');
check_same([$endpointV4, $endpointV6], $recv['staticroutes']['route'], 'new Rigi only applies safe routes');
check_same([], $rigi['staticroutes']['route'], 'receiver local config not mutated before merge');

$hostRouteSend = ['staticroutes' => ['route' => [$legacyCoder, $coderHost, $endpointV4, $endpointV6]]];
check_same(1, ha_sync_guard_bootstrap_routes($hostRouteSend, $etna, false),
    'sender discards old /24 but keeps Coder /32');
check_same([$coderHost, $endpointV4, $endpointV6], $hostRouteSend['staticroutes']['route'],
    'Coder return route and both endpoint routes survive on wire');
$hostRouteReceive = ['staticroutes' => ['route' => [$legacyCoder, $coderHost, $endpointV4, $endpointV6]]];
check_same(1, ha_sync_guard_bootstrap_routes($hostRouteReceive, $rigi, true),
    'receiver discards /24 without dropping Coder /32');
check_same([$coderHost, $endpointV4, $endpointV6], $hostRouteReceive['staticroutes']['route'],
    'receiver receives the Coder /32 return route');

$recvEtna = $incoming;
check_same(1, ha_sync_guard_bootstrap_routes($recvEtna, $etna, true), 'receiver retains intentional old Etna local route');
check_same([$legacyCoder, $endpointV4, $endpointV6], $recvEtna['staticroutes']['route'],
    'reverse sync preserves Etna local Coder route ahead of safe peer routes');

$sparse = ['staticroutes' => ['route' => [1 => $legacyCoder, 3 => $endpointV4, 6 => $endpointV6]]];
check_same(1, ha_sync_guard_bootstrap_routes($sparse, $rigi, false), 'sparse numeric sender arrays are normalized');
check_same([$endpointV4, $endpointV6], $sparse['staticroutes']['route'], 'sparse ordering preserved');

$noSection = ['filter' => ['rule' => []]];
check_same(0, ha_sync_guard_bootstrap_routes($noSection, $etna), 'unselected staticroutes untouched');
check_same(['filter' => ['rule' => []]], $noSection, 'no phantom staticroutes category introduced');

$badPayload = ['staticroutes' => 'bad'];
check_error(fn() => ha_sync_guard_bootstrap_routes($badPayload, $etna),
    'invalid payload must fail closed');
check_error(fn() => ha_sync_route_rows([['network' => '203.0.113.0/24'], 'bad']),
    'invalid row must fail closed');

$ipv6 = $rigi;
$ipv6['interfaces']['lan']['ipaddrv6'] = 'fd42:103:3990::2';
$ipv6['interfaces']['lan']['subnetv6'] = '64';
check_same(true, ha_sync_bootstrap_route_conflict(['network' => 'fd42:103:3990::/64'], $ipv6),
    'IPv6 bootstrap network protected');
check_same(false, ha_sync_bootstrap_route_conflict(['network' => '2001:db8:103::/64'], $ipv6),
    'IPv6 documentation route still synchronized');
check_same(false, ha_sync_bootstrap_route_conflict(['network' => 'fd42:103:3990::202/128'], $ipv6),
    'IPv6 host-only endpoint return route survives');
check_same(true, ha_sync_bootstrap_route_conflict(['network' => 'fd42:103:3990::2/128'], $ipv6),
    'IPv6 local bootstrap address cannot be hijacked');

echo "HA_BOOTSTRAP_STATICROUTE_GUARD_PASS coder_host_routes_preserved bootstrap_subnets_protected\n";
