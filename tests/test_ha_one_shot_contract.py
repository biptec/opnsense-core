#!/usr/bin/env python3
"""Source-contract tests; full XMLRPC and WebUI testing requires the shared-test routers."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "src"


class OneShotHASyncContract(unittest.TestCase):
    def test_one_shot_target_is_peer_only_and_never_persisted(self):
        rc = (SRC / "etc/rc.filter_synchronize").read_text()
        self.assertIn("peertarget=", rc)
        self.assertIn("$hasync['pfsyncpeerip']", rc)
        self.assertIn("$one_shot_target !== $allowed", rc)
        self.assertIn("filter_var($allowed, FILTER_VALIDATE_IP)", rc)
        self.assertIn("$hasync['synchronizetoip'] = $one_shot_target", rc)
        self.assertNotIn("$config['hasync']['synchronizetoip'] = $one_shot_target", rc)
        self.assertIn("$GLOBALS['opnsense_hasync_runtime_peer'] = $one_shot_target", rc)
        client = (SRC / "etc/inc/XMLRPC_Client.inc").read_text()
        self.assertIn("$runtime_peer = $GLOBALS['opnsense_hasync_runtime_peer'] ?? null", client)
        self.assertIn("$runtime_peer !== $configured_peer", client)
        self.assertIn("$hasync['synchronizetoip'] = $runtime_peer", client)
        self.assertNotIn("$config['hasync']['synchronizetoip'] = $runtime_peer", client)
        self.assertIn("!empty($hasync['password'])", rc.replace("empty($hasync['password'])", "!empty($hasync['password'])"))

    def test_cli_api_and_gui_expose_selective_peer_sync(self):
        actions = (SRC / "opnsense/service/conf/actions.d/actions_filter.conf").read_text()
        self.assertIn("[sync_peer]", actions)
        self.assertIn("parameters:syncitems=%s peertarget=%s restart_services", actions)
        handler = (SRC / "opnsense/scripts/system/ha_xmlrpc_exec.php").read_text()
        self.assertIn("configdp_run('filter sync_peer', [$service, $service_id])", handler)
        controller = (SRC / "opnsense/mvc/app/controllers/OPNsense/Core/Api/HasyncStatusController.php").read_text()
        self.assertIn("public function peerAction()", controller)
        self.assertIn("public function syncAction()", controller)
        self.assertIn("$target !== $allowed", controller)
        view = (SRC / "opnsense/mvc/app/views/OPNsense/Core/hasync_status.volt").read_text()
        self.assertIn("one_shot_sync_target", view)
        self.assertIn("one_shot_sync_items", view)
        self.assertIn("ajaxCall('/api/core/hasync_status/sync', {items: items, target: target}", view)

    def test_virtualip_is_always_the_final_sync_phase(self):
        rc = (SRC / "etc/rc.filter_synchronize").read_text()
        self.assertIn("$pre_vip_syncitems = $selected_syncitems;", rc)
        self.assertIn("$vip_syncitems['virtualip'] = $pre_vip_syncitems['virtualip'];", rc)
        self.assertIn("unset($pre_vip_syncitems['virtualip']);", rc)
        self.assertIn("$sync_phases[] = $pre_vip_syncitems;", rc)
        self.assertIn("$sync_phases[] = $vip_syncitems;", rc)

        phase_loop = rc.split("foreach ($sync_phases as $phase_syncitems)", 1)[1]
        prepare = phase_loop.index("ha_sync_callback($sync_item, 'sync_prepare')")
        transfer = phase_loop.index("carp_sync_xml(")
        configure = phase_loop.index('$client->query("opnsense.filter_configure", $phase_names)')
        finalize = phase_loop.index("ha_sync_callback($sync_item, 'sync_finalize')")
        restart = phase_loop.index("ha_sync_restart_services($client, $services_to_restart)")
        self.assertLess(prepare, transfer)
        self.assertLess(transfer, configure)
        self.assertLess(configure, finalize)
        self.assertLess(finalize, restart)

        late_activate = rc.index("ha_sync_callback($sync_item, 'sync_activate')")
        legacy_restart = rc.index("if ($restart_services && $requested_syncitems === null)")
        success = rc.index('log_msg("Filter sync successfully completed with {$synchronizeto}.")')
        self.assertGreater(late_activate, restart)
        self.assertLess(late_activate, legacy_restart)
        self.assertLess(legacy_restart, success)

    def test_plugins_can_register_post_phase_activation_hooks(self):
        plugins = (SRC / "etc/inc/plugins.inc").read_text()
        self.assertIn("!empty($helper['sync_activate'])", plugins)
        rc = (SRC / "etc/rc.filter_synchronize").read_text()
        self.assertIn("ha_sync_callback($sync_item, 'sync_activate')", rc)
        self.assertEqual(rc.count("ha_sync_callback($sync_item, 'sync_activate')"), 1)

    def test_deferred_sync_keeps_manual_target_strict(self):
        controller = (SRC / "opnsense/mvc/app/controllers/OPNsense/Core/Api/HasyncStatusController.php").read_text()
        self.assertIn("configdpRun('system ha deferred_enqueue'", controller)
        self.assertIn("configdpRun('system ha exec', ['exec_sync', implode(',', $requested), $target]", controller)
        self.assertIn("'deferred' => !empty($result['deferred'])", controller)

        actions = (SRC / "opnsense/service/conf/actions.d/actions_system.conf").read_text()
        self.assertIn("[ha.deferred_enqueue]", actions)
        self.assertIn("[ha.deferred_reconcile]", actions)

        filter_actions = (SRC / "opnsense/service/conf/actions.d/actions_filter.conf").read_text()
        self.assertIn("[sync_deferred]", filter_actions)
        self.assertIn("defer_peer_unavailable", filter_actions)
        strict_block = filter_actions.split("[sync_selected]", 1)[1].split("[sync_deferred]", 1)[0]
        self.assertNotIn("defer_peer_unavailable", strict_block)

    def test_deferred_sync_is_durable_locked_and_snapshot_based(self):
        helper_path = SRC / "opnsense/scripts/system/ha_deferred_sync.php"
        helper = helper_path.read_text()
        self.assertTrue(helper_path.stat().st_mode & 0o100)
        self.assertIn("'/var/db/hasync_deferred.json'", helper)
        self.assertIn("'/var/run/hasync_deferred.lock'", helper)
        self.assertIn("LOCK_EX | LOCK_NB", helper)
        self.assertIn("array_unique(array_merge($pending, $requested))", helper)
        self.assertIn("tempnam(dirname(HASYNC_DEFERRED_STATE)", helper)
        self.assertIn("rename($temporary, HASYNC_DEFERRED_STATE)", helper)
        self.assertIn("!chmod($temporary, 0600)", helper)
        self.assertIn("!unlink(HASYNC_DEFERRED_STATE)", helper)
        self.assertIn("require_once('script/load_phalcon.php')", helper)
        self.assertIn("use OPNsense\\Core\\Backend;", helper)
        self.assertIn("(new Backend())->configdpRun('filter sync_deferred'", helper)
        self.assertNotIn("configdp_run(", helper)
        self.assertIn("$result === 'peer_unavailable'", helper)
        self.assertIn("hasync_deferred_store([])", helper)
        self.assertNotIn("generation", helper)
        self.assertNotIn("endpoint", helper)

        cron = (SRC / "etc/cron.d/hasync_deferred").read_text()
        self.assertIn("configctl -d system ha deferred_reconcile", cron)

    def test_peer_unavailable_is_typed_without_hiding_tls_or_auth_errors(self):
        client = (SRC / "etc/inc/XMLRPC_Client.inc").read_text()
        self.assertIn("public $curl_error_code = 0", client)
        self.assertIn("$this->curl_error_code = curl_errno($ch)", client)
        self.assertIn("curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);", client)

        rc = (SRC / "etc/rc.filter_synchronize").read_text()
        self.assertIn("$timeout = $defer_peer_unavailable ? 5 : 60;", rc)
        self.assertIn("new SimpleXMLRPC_Client($url, $timeout)", rc)
        for error_name in (
            "CURLE_COULDNT_CONNECT",
            "CURLE_OPERATION_TIMEDOUT",
        ):
            self.assertIn(error_name, rc)
        peer_block = rc.split("$peer_unavailable = in_array", 1)[1].split("], true);", 1)[0]
        for strict_error in (
            "CURLE_GOT_NOTHING",
            "CURLE_SEND_ERROR",
            "CURLE_RECV_ERROR",
            "CURLE_SSL_CONNECT_ERROR",
            "CURLE_PEER_FAILED_VERIFICATION",
        ):
            self.assertNotIn(strict_error, peer_block)
        self.assertIn("return 'failed';", rc)
        self.assertIn('"Authentication failed"', rc)

    def test_node_local_skew_is_operator_configurable(self):
        model = (SRC / "opnsense/mvc/app/models/OPNsense/Core/Hasync.xml").read_text()
        self.assertIn('local_endpoint_carp_advskew type="IntegerField"', model)
        self.assertIn("<MinimumValue>0</MinimumValue>", model)
        self.assertIn("<MaximumValue>254</MaximumValue>", model)
        forms = (SRC / "opnsense/mvc/app/controllers/OPNsense/Core/forms/hasyncSettings.xml").read_text()
        self.assertIn("<id>hasync.local_endpoint_carp_advskew</id>", forms)
        cli = (SRC / "opnsense/scripts/system/ha_xmlrpc_exec.php").read_text()
        self.assertIn("case 'set_local_carp_skew':", cli)
        self.assertIn("case 'get_local_carp_skew':", cli)


if __name__ == '__main__':
    unittest.main()
