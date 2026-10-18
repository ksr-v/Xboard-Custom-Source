"""Endpoint candidate selection and Root-before-settings ordering, without services or real state."""
from contextlib import ExitStack
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch


ROOT = Path(__file__).resolve().parents[2]
if os.name == 'nt':
    sys.modules.setdefault('pwd', SimpleNamespace())
spec = importlib.util.spec_from_file_location('endpoint_selection_installer', ROOT / 'scripts/install-panel.py')
install = importlib.util.module_from_spec(spec)
spec.loader.exec_module(install)


class EndpointPlanTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name).resolve()
        self.app = self.base / 'xboard'
        self.app.mkdir()
        self.state = self.base / 'root-state'
        self.state.mkdir()
        self.args = SimpleNamespace(host=None, port=None, non_interactive=False, nginx='/fixture/nginx')
        self.site = {'app': str(self.app), 'site_config': '/fixture/panel.conf', 'site_name': 'panel.example',
                     'include_dir': '/fixture/http', 'backend': 'unix:/fixture/php.sock', 'ws_backend': ''}
        self.manager = SimpleNamespace(Environment=Mock(return_value=object()), safe_state=Mock(return_value=self.state),
                                       trusted_file=Mock(), Manager=Mock())
        self.quick = SimpleNamespace(select_communication_host=Mock(return_value='8.8.8.8'),
                                     discover=Mock(return_value=[self.site]), select=Mock(return_value=self.site))
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.stack.enter_context(patch.dict(sys.modules, {'manager': self.manager}))
        self.stack.enter_context(patch.object(install, 'ROOT_STATE', self.state))
        self.user_calls = self.stack.enter_context(patch.object(install, 'as_user'))

    def existing(self, host='8.8.8.8'):
        (self.state / 'installation.json').write_text('owned fixture')
        plan = {'app': str(self.app), 'host': host, 'port': 8443, 'https': True}
        self.manager.Manager.return_value = SimpleNamespace(assert_owned=Mock(return_value={'plan': plan}))
        return plan

    def test_fresh_install_omits_host_and_uses_shared_interactive_selection(self):
        plan, existing = install.endpoint_plan(self.args, self.app, self.quick)
        self.assertFalse(existing)
        self.assertEqual(plan['host'], '8.8.8.8')
        self.assertEqual(plan['port'], 8443)
        self.quick.select_communication_host.assert_called_once_with(explicit=None)
        self.manager.Environment.assert_called_once_with('/fixture/nginx')
        self.user_calls.assert_not_called()

    def test_explicit_new_host_is_validated_by_the_same_shared_selector(self):
        self.args.host = '1.1.1.1'
        self.quick.select_communication_host.return_value = '1.1.1.1'
        plan, existing = install.endpoint_plan(self.args, self.app, self.quick)
        self.assertFalse(existing)
        self.assertEqual(plan['host'], '1.1.1.1')
        self.quick.select_communication_host.assert_called_once_with(explicit='1.1.1.1')

    def test_existing_real_host_is_preserved_without_discovery_or_panel_write(self):
        expected = self.existing()
        plan, existing = install.endpoint_plan(self.args, self.app, self.quick)
        self.assertTrue(existing)
        self.assertIs(plan, expected)
        self.quick.select_communication_host.assert_called_once_with(current='8.8.8.8', explicit=None)
        self.manager.Environment.assert_not_called()
        self.quick.discover.assert_not_called()
        self.user_calls.assert_not_called()

    def test_existing_host_or_port_change_requires_separate_modify(self):
        self.existing()
        for explicit, selected, port in [('1.1.1.1', '1.1.1.1', None), (None, '8.8.8.8', 9443)]:
            with self.subTest(host=explicit, port=port):
                self.args.host, self.args.port = explicit, port
                self.quick.select_communication_host.return_value = selected
                with self.assertRaisesRegex(RuntimeError, 'modify'):
                    install.endpoint_plan(self.args, self.app, self.quick)
        self.user_calls.assert_not_called()

    def test_fresh_documentation_address_is_rejected_before_nginx_or_panel_changes(self):
        self.args.host = '203.0.113.10'
        self.quick.select_communication_host.side_effect = ValueError('documentation IP')
        with self.assertRaisesRegex(ValueError, 'documentation IP'):
            install.endpoint_plan(self.args, self.app, self.quick)
        self.manager.Environment.assert_not_called()
        self.user_calls.assert_not_called()

    def test_existing_documentation_address_gives_explicit_repair_guidance(self):
        expected = self.existing('203.0.113.10')
        self.quick.select_communication_host.side_effect = ValueError('documentation IP')
        with self.assertRaisesRegex(ValueError, '--repair-address'):
            install.endpoint_plan(self.args, self.app, self.quick)
        self.assertEqual(expected['host'], '203.0.113.10')
        self.quick.select_communication_host.assert_called_once_with(current='203.0.113.10', explicit=None)
        self.manager.Environment.assert_not_called()
        self.user_calls.assert_not_called()

    def test_first_noninteractive_install_does_not_probe_or_guess_address(self):
        self.args.non_interactive = True
        with self.assertRaisesRegex(RuntimeError, 'SSH'):
            install.endpoint_plan(self.args, self.app, self.quick)
        self.quick.select_communication_host.assert_not_called()
        self.manager.Environment.assert_not_called()
        self.user_calls.assert_not_called()


class CompletionOrderTests(unittest.TestCase):
    def fixture(self):
        self.events = []
        self.app = Path('/fixture/xboard')
        self.user = SimpleNamespace(pw_name='fixture-user')
        self.php = '/fixture/php'
        self.plan = {'host': '8.8.8.8', 'port': 8443, 'nginx': '/fixture/nginx', 'ws_backend': '',
                     'site': {'site_config': '/fixture/panel.conf', 'site_name': 'panel.example',
                              'include_dir': '/fixture/http', 'backend': 'unix:/fixture/php.sock'}}
        self.manager = SimpleNamespace(parser=Mock())
        self.manager.parser.return_value.parse_args.side_effect = lambda argv: SimpleNamespace(argv=argv)
        self.quick = SimpleNamespace(configure_firewall=Mock(side_effect=self.firewall))

    def firewall(self):
        self.events.append('firewall')
        return {'status': 'ensured'}

    def complete(self, root_failure=None, existing=False):
        def root(*args):
            self.events.append('root')
            if root_failure:
                raise root_failure

        def panel(*args):
            self.events.append('settings')

        def ca():
            self.events.append('ca')
            return {'status': 'imported'}

        with patch.dict(sys.modules, {'manager': self.manager, 'panel_ca': SimpleNamespace(sync_panel_ca=ca)}), \
                patch.object(install, 'execute_endpoint_install', side_effect=root) as root_call, \
                patch.object(install, 'as_user', side_effect=panel) as panel_call, patch('builtins.print'):
            install.complete_endpoint(self.app, self.user, self.php, self.quick, self.plan, existing)
            return root_call, panel_call

    def test_root_success_precedes_panel_settings_firewall_and_public_ca_import(self):
        self.fixture()
        root, panel = self.complete()
        self.assertEqual(self.events, ['root', 'settings', 'firewall', 'ca'])
        root.assert_called_once()
        panel.assert_called_once_with(self.user, self.app, [self.php, 'artisan', 'xboard:configure-endpoint',
                                                           '--host=8.8.8.8', '--port=8443', '--ws-mode=rest'])

    def test_root_failure_or_confirmation_cancellation_never_writes_panel_settings(self):
        for failure in [RuntimeError('fixture transaction failure'), EOFError('fixture cancellation')]:
            with self.subTest(error=type(failure).__name__):
                self.fixture()
                with self.assertRaises(type(failure)):
                    self.complete(root_failure=failure)
                self.assertEqual(self.events, ['root'])
                self.quick.configure_firewall.assert_not_called()

    def test_existing_owned_entry_syncs_panel_without_reinstalling_root(self):
        self.fixture()
        root, panel = self.complete(existing=True)
        self.assertEqual(self.events, ['settings', 'firewall', 'ca'])
        root.assert_not_called()
        panel.assert_called_once()

    def test_websocket_mode_is_preserved_after_successful_root_stage(self):
        self.fixture()
        self.plan['ws_backend'] = 'http://127.0.0.1:8076'
        root, panel = self.complete()
        self.assertEqual(self.events, ['root', 'settings', 'firewall', 'ca'])
        self.assertIn('--enable-ws', root.call_args.args[1].argv)
        self.assertIn('--ws-mode=rewrite', panel.call_args.args[2])


if __name__ == '__main__':
    unittest.main()
