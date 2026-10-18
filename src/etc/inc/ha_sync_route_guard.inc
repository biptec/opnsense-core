<?php

/*
 * Native HA safety: never push routes over a router's local bootstrap network.
 *
 * Interfaces/VLAN and HAProxy use policy_id for symmetric object sync.
 * Native "staticroutes" remains operator-selectable. A connected bootstrap
 * subnet is node-local even when a Coder endpoint supplies a competing route
 * for the whole subnet. Unlike that route, a host-specific Coder return route
 * (/32 or /128) is intentional and must remain synchronized. Guard the
 * subnet route, but never discard an unrelated endpoint host return route.
 *
 * These helpers are pure array transforms: no config writes, shell commands,
 * global state, or network access. PHP >= 8.1 (OPNsense baseline).
 */

function ha_sync_parse_cidr($input): ?array
{
    if (!is_string($input) || substr_count($input, '/') !== 1) {
        return null;
    }
    [$address, $prefixText] = explode('/', $input, 2);
    if ($address === '' || !ctype_digit($prefixText)) {
        return null;
    }
    $packed = @inet_pton($address);
    if ($packed === false) {
        return null;
    }
    $limit = strlen($packed) * 8;
    $prefix = (int)$prefixText;
    if ($prefix < 0 || $prefix > $limit) {
        return null;
    }
    return [$packed, $prefix];
}

function ha_sync_cidrs_overlap($a, $b): bool
{
    $left = ha_sync_parse_cidr($a);
    $right = ha_sync_parse_cidr($b);
    if ($left === null || $right === null || strlen($left[0]) !== strlen($right[0])) {
        return false;
    }
    $bits = min($left[1], $right[1]);
    $bytes = intdiv($bits, 8);
    if (substr($left[0], 0, $bytes) !== substr($right[0], 0, $bytes)) {
        return false;
    }
    $remainder = $bits % 8;
    if ($remainder === 0) {
        return true;
    }
    $mask = (0xff << (8 - $remainder)) & 0xff;
    return (ord($left[0][$bytes]) & $mask) === (ord($right[0][$bytes]) & $mask);
}

/**
 * Only genuinely local bootstrap interfaces should suppress route transport;
 * arbitrary endpoint interfaces must continue receiving native routes.
 */
function ha_sync_bootstrap_networks(array $local): array
{
    $networks = [];
    foreach (($local['interfaces'] ?? []) as $identifier => $section) {
        if (!is_array($section)) {
            continue;
        }
        $description = strtolower(trim((string)($section['descr'] ?? '')));
        if ($identifier !== 'lan' && !str_contains($description, 'bootstrap')) {
            continue;
        }
        foreach ([['ipaddr', 'subnet'], ['ipaddrv6', 'subnetv6']] as [$ipKey, $prefixKey]) {
            $address = $section[$ipKey] ?? '';
            $prefix = $section[$prefixKey] ?? '';
            if (!is_scalar($address) || !is_scalar($prefix) || $prefix === '') {
                continue;
            }
            $candidate = (string)$address . '/' . (string)$prefix;
            if (ha_sync_parse_cidr($candidate) !== null) {
                $networks[] = $candidate;
            }
        }
    }
    return array_values(array_unique($networks));
}

function ha_sync_route_rows($value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (!is_array($value)) {
        throw new RuntimeException('invalid HA staticroute container');
    }
    if ($value === []) {
        return [];
    }
    if (isset($value['network'])) {
        return [$value];
    }
    foreach ($value as $key => $item) {
        if (!is_int($key) || !is_array($item)) {
            throw new RuntimeException('invalid HA staticroute row');
        }
    }
    // remove_nosync() unsets individual rows and leaves numeric gaps.
    return array_values($value);
}

function ha_sync_bootstrap_route_conflict(array $route, array $local): bool
{
    $network = $route['network'] ?? '';
    if (!is_string($network) || ha_sync_parse_cidr($network) === null) {
        // Let native OPNsense route validation handle unrelated bad data.
        return false;
    }
    $routeCidr = ha_sync_parse_cidr($network);
    foreach (ha_sync_bootstrap_networks($local) as $bootstrap) {
        $localCidr = ha_sync_parse_cidr($bootstrap);
        // A less-specific route cannot displace the connected network.
        if (strlen($routeCidr[0]) !== strlen($localCidr[0])
            || $routeCidr[1] < $localCidr[1]
            || !ha_sync_cidrs_overlap($network, $bootstrap)) {
            continue;
        }
        // A deliberate host-only return route (for example, Coder
        // 10.200.0.202/32) must be allowed; it affects only that endpoint,
        // not the connected bootstrap subnet. Never replace our own address.
        if ($routeCidr[1] === strlen($routeCidr[0]) * 8
            && $routeCidr[0] !== $localCidr[0]) {
            continue;
        }
        return true;
    }
    return false;
}

/**
 * Filter peer transport/receive route list without modifying live config.
 *
 * On SEND, the sender's local bootstrap routes never go out over XMLRPC.
 * On RECEIVE, skip incoming routes which overlap receiver bootstrap CIDRs,
 * but retain any pre-existing receiver-local routes for those CIDRs.
 * Thus the native merge preserves an intentional local route unchanged.
 *
 * Native gateway objects are untouched: they may be shared with other
 * non-conflicting routes and must not be blindly deleted.
 *
 * @return int number of conflicting incoming static routes suppressed
 */
function ha_sync_guard_bootstrap_routes(array &$incoming, array $local, bool $receiver = false): int
{
    if (!array_key_exists('staticroutes', $incoming)) {
        return 0;
    }
    if ($incoming['staticroutes'] === null || $incoming['staticroutes'] === '') {
        $incoming['staticroutes'] = ['route' => []];
    } elseif (!is_array($incoming['staticroutes'])) {
        throw new RuntimeException('invalid HA staticroutes section');
    }
    $incomingRows = ha_sync_route_rows($incoming['staticroutes']['route'] ?? []);
    $safe = [];
    $suppressed = 0;
    foreach ($incomingRows as $route) {
        if (ha_sync_bootstrap_route_conflict($route, $local)) {
            ++$suppressed;
        } else {
            $safe[] = $route;
        }
    }

    $localProtected = [];
    if ($receiver) {
        foreach (ha_sync_route_rows($local['staticroutes']['route'] ?? []) as $route) {
            if (ha_sync_bootstrap_route_conflict($route, $local)) {
                $localProtected[] = $route;
            }
        }
    }
    $incoming['staticroutes']['route'] = array_merge($localProtected, $safe);
    return $suppressed;
}
