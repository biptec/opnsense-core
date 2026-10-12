<?php
// Isolated tests for receiver-local endpoint CARP skew during XMLRPC restore.
require_once __DIR__ . '/../src/etc/inc/xmlrpc/legacy.inc';

if (!function_exists('get_unique_vip_key')) {
    function get_unique_vip_key($vip): string
    {
        return implode('|', [$vip['mode'] ?? '', $vip['interface'] ?? '', $vip['vhid'] ?? '', $vip['subnet'] ?? '']);
    }
}

function check($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "$label: expected " . var_export($expected, true)
            . ", got " . var_export($actual, true) . "\n");
        exit(1);
    }
}
function configWithSkew(?string $skew): array
{
    $hasync = [];
    if ($skew !== null) {
        $hasync['local_endpoint_carp_advskew'] = $skew;
    }
    return [
        'hasync' => $hasync,
        'OPNsense' => ['ApiExtensions' => ['InterfaceSyncPolicy' => [
            'shared' => ['policies' => ['policy' => [
                ['@attributes'=>['uuid'=>'p-endpoint'],'id'=>'endpoint','synchronize'=>'1'],
                ['@attributes'=>['uuid'=>'p-core'],'id'=>'core','synchronize'=>'0'],
            ]]],
            'assignments' => ['assignment' => [
                ['@attributes'=>['uuid'=>'a-customer'],'interface'=>'ep_customer','policy_id'=>'p-endpoint'],
                ['@attributes'=>['uuid'=>'a-core'],'interface'=>'core_wan','policy_id'=>'p-core'],
            ]],
        ]]],
        'virtualip'=>['vip'=>[
            ['mode'=>'carp','interface'=>'core_wan','vhid'=>'11','subnet'=>'192.0.2.2','advskew'=>'10'],
        ]],
    ];
}
function inbound(): array
{
    return ['virtualip'=>['vip'=>[
        ['mode'=>'carp','interface'=>'ep_customer','vhid'=>'70','subnet'=>'192.0.2.10','advskew'=>'200'],
        ['mode'=>'ipalias','interface'=>'ep_customer','vhid'=>'70','subnet'=>'2001:db8::10','advskew'=>'200'],
        ['mode'=>'carp','interface'=>'core_wan','vhid'=>'11','subnet'=>'192.0.2.2','advskew'=>'110'],
    ]]];
}

foreach (['0','100','254'] as $skew) {
    $payload = inbound();
    ha_xmlrpc_apply_receiver_carp_skew($payload, configWithSkew($skew));
    check($skew, $payload['virtualip']['vip'][0]['advskew'], 'endpoint skew '.$skew);
    check('200', $payload['virtualip']['vip'][1]['advskew'], 'ipalias unchanged');
    check('10', $payload['virtualip']['vip'][2]['advskew'], 'core CARP preserved');
}
$failed = false;
try {
    $payload = inbound();
    ha_xmlrpc_apply_receiver_carp_skew($payload, configWithSkew(null));
} catch (RuntimeException $error) {
    $failed = str_contains($error->getMessage(), 'configure local endpoint CARP advskew');
}
check(true, $failed, 'missing setting fails closed');

foreach (['-1','255','notanumber'] as $invalid) {
    $failed = false;
    try {
        $payload = inbound();
        ha_xmlrpc_apply_receiver_carp_skew($payload, configWithSkew($invalid));
    } catch (RuntimeException $error) {
        $failed = true;
    }
    check(true, $failed, 'invalid skew '.$invalid);
}
fwrite(STDOUT, "receiver-local CARP advskew tests passed\n");
