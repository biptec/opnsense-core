<?php
/**
 * Issue #103: HA selective XMLRPC sync must preserve node-local firewall/NAT
 * rules without moving their precedence, and never adopt remote nosync rules.
 * Runs with stock php-cli (no OPNsense host needed), no network access.
 */
require_once __DIR__ . '/../src/etc/inc/xmlrpc/legacy.inc';

function check_same($want, $actual, string $description): void
{
    if ($actual !== $want) {
        fwrite(STDERR, "FAIL: {$description}\n");
        fwrite(STDERR, "expected " . var_export($want, true) . "\n");
        fwrite(STDERR, "actual " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function check_throws(callable $callable, string $description): void
{
    try {
        $callable();
    } catch (RuntimeException $error) {
        return;
    }
    fwrite(STDERR, "FAIL: {$description}: RuntimeException expected\n");
    exit(1);
}

function rule(string $id, bool $local = false): array
{
    $record = ['@attributes' => ['uuid' => $id], 'descr' => $id];
    if ($local) {
        $record['nosync'] = '1';
    }
    return $record;
}

// Local rules remain at their original slot between synced rules.
$before = [
    rule('local-pre', true), rule('old-A'), rule('local-middle', true),
    rule('old-B'), rule('local-bottom', true),
];
$incoming = [rule('new-A'), rule('new-B'), rule('new-C')];
$want = [
    rule('local-pre', true), rule('new-A'), rule('local-middle', true),
    rule('new-B'), rule('local-bottom', true), rule('new-C'),
];
check_same($want, ha_xmlrpc_merge_ordered_nosync($incoming, $before), 'ordered firewall local precedence preserved');
check_same($want, ha_xmlrpc_merge_ordered_nosync($incoming, $want), 'repeat XMLRPC sync idempotent');
check_same([rule('local-pre', true), rule('local-middle', true), rule('local-bottom', true)],
    ha_xmlrpc_merge_ordered_nosync([], $before), 'synced rules removed but local rules survive');
check_throws(fn() => ha_xmlrpc_merge_ordered_nosync(
    [rule('new-A'), rule('foreign-local', true)], $before
), 'sender must never override receiver local nosync records');
check_throws(fn() => ha_xmlrpc_merge_ordered_nosync([false], $before), 'reject malformed incoming rules');

// remove_nosync() unsets 27 source rows without reindexing the numeric list.
// A sparse PHP array must not be interpreted as one MVC singleton.
$sparse = [1 => rule('remote-one'), 3 => rule('remote-two')];
check_same([rule('remote-one'), rule('remote-two')],
    ha_xmlrpc_config_rows($sparse), 'sparse sender rows normalized');
check_same([
    rule('local-pre', true), rule('remote-one'), rule('local-middle', true),
    rule('remote-two'), rule('local-bottom', true),
], ha_xmlrpc_merge_ordered_nosync($sparse, $before), 'sparse sender ordered merge');
check_same([rule('one-singleton')], ha_xmlrpc_config_rows(rule('one-singleton')),
    'MVC singleton still preserved');

// Empty/string/singleton MVC structure is supported.
check_same([rule('new-A')], ha_xmlrpc_merge_ordered_nosync(rule('new-A'), ''), 'singleton incoming rule');
check_same([rule('local-only', true), rule('new-A')],
    ha_xmlrpc_merge_ordered_nosync([rule('new-A')], rule('local-only', true)), 'singleton local rule');

// Verify the EXACT recursive Core merge (not just the helper), including the
// sibling-path bug fix across filter, nat and nat.outbound collections.
$destination = [
    'filter' => ['rule' => [rule('local-firewall', true), rule('old-firewall')]],
    'nat' => [
        'rule' => [rule('old-nat'), rule('local-nat', true)],
        'outbound' => ['rule' => [rule('local-outbound', true), rule('old-outbound')]],
    ],
    'gateways' => ['gateway_item' => [
        ['name' => 'WAN_V4', 'nosync' => '1'], ['name' => 'endpoint-old'],
    ]],
];
$remote = [
    'filter' => ['rule' => [rule('new-firewall')]],
    'nat' => [
        'rule' => [rule('new-nat')],
        'outbound' => ['rule' => [rule('new-outbound')]],
    ],
    'gateways' => ['gateway_item' => [['name' => 'endpoint-new']]],
];
merge_config_attributes($remote, $destination);
check_same([rule('local-firewall', true), rule('new-firewall')],
    $destination['filter']['rule'], 'native filter rule merge preserves local');
check_same([rule('new-nat'), rule('local-nat', true)],
    $destination['nat']['rule'], 'native inbound NAT retains local slot');
check_same([rule('local-outbound', true), rule('new-outbound')],
    $destination['nat']['outbound']['rule'], 'native outbound NAT retains local slot');
check_same(['name' => 'WAN_V4', 'nosync' => '1'],
    $destination['gateways']['gateway_item'][0], 'local WAN gateway retained by existing general merge');
check_same('endpoint-new',
    $destination['gateways']['gateway_item'][1]['name'], 'synced endpoint gateway updated');
$second = $destination;
merge_config_attributes($remote, $destination);
check_same($second, $destination, 'entire recursive XMLRPC merge idempotent');

// Approximate live Etna/Rigi fleet counts at test creation:
// Etna 160 firewall entries (27 nosync), Rigi 159 (27 nosync).
$oldRigi = [];
for ($i = 0; $i < 159; $i++) {
    $oldRigi[] = ($i % 6 === 0 && $i / 6 < 27)
        ? rule(sprintf('rigi-local-%02d', $i), true)
        : rule(sprintf('rigi-synced-%02d', $i));
}
check_same(27, count(array_filter($oldRigi, fn($item) => !empty($item['nosync']))), 'fixture local count');
$incomingEtna = [];
for ($i = 0; $i < 133; $i++) {
    $incomingEtna[] = rule(sprintf('etna-synced-%02d', $i));
}
$merged = ha_xmlrpc_merge_ordered_nosync($incomingEtna, $oldRigi);
check_same(160, count($merged), 'full receiver rule count includes 27 untouched locals and 133 incoming');
$beforeLocal = array_values(array_filter($oldRigi, fn($item) => !empty($item['nosync'])));
$afterLocal = array_values(array_filter($merged, fn($item) => !empty($item['nosync'])));
check_same($beforeLocal, $afterLocal, 'all 27 node-local Rigi rules exactly preserved');
echo "HA_NOSYNC_ORDERED_MERGE_PASS test_cases=16 local_rules=27 synced_rules=133\n";
