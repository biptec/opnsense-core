"""Issue #103: native HA staticroutes may not blackhole peer bootstrap.

These contract checks complement the PHP8.3 functional tests. They ensure the
guard is wired into both the sender and receiver before XMLRPC transport/merge,
not just implemented as a standalone helper.
"""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SENDER = ROOT / "src/etc/rc.filter_synchronize"
RECEIVER = ROOT / "src/etc/inc/xmlrpc/legacy.inc"
GUARD = ROOT / "src/etc/inc/ha_sync_route_guard.inc"


class HASyncBootstrapRouteContract(unittest.TestCase):
    def test_receiver_filters_before_config_merge_and_routing_reload(self):
        source = RECEIVER.read_text()
        self.assertIn('require_once(__DIR__ . "/../ha_sync_route_guard.inc");', source)
        guard = source.index("ha_sync_guard_bootstrap_routes($new_config, $config, true)")
        merge = source.index("merge_config_attributes($new_config, $config)")
        apply = source.index("ha_xmlrpc_apply_routing_diff($old_config, $config)")
        self.assertLess(guard, merge)
        self.assertLess(merge, apply)

    def test_sender_filters_after_excluding_nosync_and_before_rpc(self):
        source = SENDER.read_text()
        self.assertIn('require_once(__DIR__ . "/inc/ha_sync_route_guard.inc");', source)
        remove = source.index("remove_nosync($transport_data)")
        guard = source.index("ha_sync_guard_bootstrap_routes($transport_data, $config)")
        send = source.index("$client->query('opnsense.restore_config_section', $transport_data)")
        self.assertLess(remove, guard)
        self.assertLess(guard, send)

    def test_source_helper_is_pure_no_shell_or_config_write(self):
        source = GUARD.read_text()
        self.assertIn("ha_sync_bootstrap_route_conflict", source)
        self.assertIn("ha_sync_guard_bootstrap_routes", source)
        self.assertIn("ha_sync_cidrs_overlap", source)
        for forbidden in ("write_config(", "system_routing_configure(", "shell_exec(", "mwexec(", "file_put_contents("):
            self.assertNotIn(forbidden, source)


if __name__ == "__main__":
    unittest.main()
