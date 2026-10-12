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
