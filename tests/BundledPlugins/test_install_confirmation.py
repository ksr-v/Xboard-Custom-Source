"""Scoped installer confirmation and PHP CLI preflight regressions."""
import ast
import importlib.util
import io
import json
import os
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch
import zipfile

ROOT = Path(__file__).resolve().parents[2]
if os.name == 'nt':
    sys.modules.setdefault('pwd', SimpleNamespace())
spec = importlib.util.spec_from_file_location('install_confirmation_runtime', ROOT / 'scripts/install-panel.py')
install = importlib.util.module_from_spec(spec)
spec.loader.exec_module(install)


class NativeConfirmationError(RuntimeError):
    pass


def native_confirmation():
    # Exercise the immutable manager's actual guard without importing its Root
    # module or any PKI/Nginx implementation into this isolated unit fixture.
    with zipfile.ZipFile(ROOT / 'root-assets/node-endpoint-tools-1.0.5.zip') as archive:
        tree = ast.parse(archive.read('tools/panel/manager.py'))
    function = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'confirmation')
    namespace = {'SafetyError': NativeConfirmationError}
    exec(compile(ast.Module(body=[function], type_ignores=[]), '<pinned-manager-confirmation>', 'exec'), namespace)
    return namespace['confirmation']


NATIVE_CONFIRMATION = native_confirmation()


class ConfirmationTests(unittest.TestCase):
    def fixture(self, expected=None, supplied=None, failure=None):
        args = SimpleNamespace(host='203.0.113.10', port=8443, confirm=supplied)
        phrase = expected or 'INSTALL 203.0.113.10:8443'
        events = []
        original = Mock(wraps=NATIVE_CONFIRMATION)
        manager = SimpleNamespace(confirmation=original)

        def execute(value):
            self.assertIs(value, args)
            events.append('plan')
            print('reviewable_plan')
            if failure == 'before-confirmation':
                raise NativeConfirmationError('fixture plan failure')
            manager.confirmation(phrase, value.confirm)
            events.append('transaction')
            if failure == 'transaction':
                raise NativeConfirmationError('fixture transaction failure')
            return 'installed'

        manager.execute = Mock(side_effect=execute)
        return manager, args, original, events

    def test_typos_retry_only_confirmation_after_plan_and_before_one_transaction(self):
        manager, args, original, events = self.fixture()
        phrase = 'INSTALL 203.0.113.10:8443'
        answers = iter(['INSTALL', '', 'yes', 'INSTALL 203.0.113.11:8443',
                        'INSTALL 203.0.113.10:443', '  ' + phrase + '  '])

        def answer(prompt):
            self.assertTrue(events and events[0] == 'plan')
            self.assertNotIn('transaction', events)
            events.append('input')
            return next(answers)

        with patch('builtins.input', side_effect=answer) as prompt, \
             patch('sys.stdout', new_callable=io.StringIO) as output:
            self.assertEqual(install.execute_endpoint_install(manager, args), 'installed')
        self.assertEqual(events, ['plan'] + ['input'] * 6 + ['transaction'])
        self.assertEqual(prompt.call_count, 6)
        self.assertEqual(output.getvalue().count('输入不匹配'), 5)
        self.assertLess(output.getvalue().index('reviewable_plan'), output.getvalue().index('请核对'))
        original.assert_called_once_with(phrase, phrase)
        manager.execute.assert_called_once_with(args)
        self.assertIs(manager.confirmation, original)

    def test_cancel_eof_and_ctrl_c_stop_before_transaction_and_restore_original(self):
        for answer in ('CANCEL', ' cancel ', EOFError(), KeyboardInterrupt()):
            with self.subTest(answer=type(answer).__name__ if isinstance(answer, BaseException) else answer):
                manager, args, original, events = self.fixture()
                options = {'side_effect': answer} if isinstance(answer, BaseException) else {'return_value': answer}
                with patch('builtins.input', **options), patch('sys.stdout', new_callable=io.StringIO):
                    with self.assertRaisesRegex(RuntimeError, '已取消.*未进入证书签发'):
                        install.execute_endpoint_install(manager, args)
                self.assertEqual(events, ['plan'])
                original.assert_not_called()
                manager.execute.assert_called_once_with(args)
                self.assertIs(manager.confirmation, original)

    def test_supplied_confirmation_retains_native_strictness_and_never_prompts(self):
        phrase = 'INSTALL 203.0.113.10:8443'
        for supplied in (phrase, 'INSTALL', phrase + ' ', ''):
            with self.subTest(supplied=supplied):
                manager, args, original, events = self.fixture(supplied=supplied)
                with patch('builtins.input', side_effect=AssertionError('Supplied confirmation must not prompt')) as prompt, \
                     patch('sys.stdout', new_callable=io.StringIO):
                    if supplied == phrase:
                        self.assertEqual(install.execute_endpoint_install(manager, args), 'installed')
                    else:
                        with self.assertRaisesRegex(NativeConfirmationError, 'did not match'):
                            install.execute_endpoint_install(manager, args)
                prompt.assert_not_called()
                original.assert_called_once_with(phrase, supplied)
                self.assertEqual(events, ['plan', 'transaction'] if supplied == phrase else ['plan'])
                self.assertIs(manager.confirmation, original)

    def test_other_operations_and_other_install_addresses_use_original_prompt(self):
        for phrase in ('ROLLBACK NODE ENDPOINT', 'INSTALL 203.0.113.11:8443'):
            with self.subTest(phrase=phrase):
                manager, args, original, events = self.fixture(expected=phrase)
                with patch('builtins.input', return_value=phrase) as prompt, \
                     patch('sys.stdout', new_callable=io.StringIO):
                    self.assertEqual(install.execute_endpoint_install(manager, args), 'installed')
                original.assert_called_once_with(phrase, None)
                prompt.assert_called_once()
                self.assertIn('"' + phrase + '"', prompt.call_args.args[0])
                self.assertEqual(events, ['plan', 'transaction'])
                self.assertIs(manager.confirmation, original)

    def test_execute_or_transaction_failures_are_never_retried_and_restore_callback(self):
        for phase in ('before-confirmation', 'transaction'):
            with self.subTest(phase=phase):
                manager, args, original, events = self.fixture(failure=phase)
                with patch('builtins.input', return_value='INSTALL 203.0.113.10:8443') as prompt, \
                     patch('sys.stdout', new_callable=io.StringIO):
                    with self.assertRaisesRegex(NativeConfirmationError, 'fixture'):
                        install.execute_endpoint_install(manager, args)
                manager.execute.assert_called_once_with(args)
                self.assertEqual(prompt.call_count, int(phase == 'transaction'))
                self.assertEqual(events.count('transaction'), int(phase == 'transaction'))
                self.assertIs(manager.confirmation, original)

    def test_existing_root_entry_syncs_without_install_or_confirmation_wrapper(self):
        manager = SimpleNamespace(parser=Mock(), execute=Mock(), confirmation=Mock())
        ca = SimpleNamespace(sync_panel_ca=Mock(return_value={'status': 'imported'}))
        quick = SimpleNamespace(configure_firewall=Mock(return_value={'status': 'ensured'}))
        plan = {'host': '203.0.113.10', 'port': 8443, 'ws_backend': None}
        with patch.dict(sys.modules, {'manager': manager, 'panel_ca': ca}), \
             patch.object(install, 'as_user') as app_command, \
             patch.object(install, 'execute_endpoint_install') as endpoint_install, \
             patch('sys.stdout', new_callable=io.StringIO):
            install.complete_endpoint(Path('/fixture/site'), SimpleNamespace(), '/fixture/php', quick, plan, True)
        endpoint_install.assert_not_called()
        manager.parser.assert_not_called()
        manager.execute.assert_not_called()
        manager.confirmation.assert_not_called()
        app_command.assert_called_once()
        quick.configure_firewall.assert_called_once()
        ca.sync_panel_ca.assert_called_once()

    def test_cancelled_new_entry_does_not_reach_firewall_or_public_ca_sync(self):
        manager, args, original, events = self.fixture()
        manager.parser = Mock(return_value=SimpleNamespace(parse_args=Mock(return_value=args)))
        ca = SimpleNamespace(sync_panel_ca=Mock())
        quick = SimpleNamespace(configure_firewall=Mock())
        plan = {'host': args.host, 'port': args.port, 'nginx': None, 'ws_backend': None,
                'site': {'site_config': '/fixture/site.conf', 'site_name': 'fixture.test',
                         'include_dir': '/fixture/sites', 'backend': '/fixture/php.sock'}}
        with patch.dict(sys.modules, {'manager': manager, 'panel_ca': ca}), \
             patch.object(install, 'as_user'), \
             patch('builtins.input', return_value='CANCEL'), \
             patch('sys.stdout', new_callable=io.StringIO):
            with self.assertRaisesRegex(RuntimeError, '已取消'):
                install.complete_endpoint(Path('/fixture/site'), SimpleNamespace(pw_name='fixture'),
                                          '/fixture/php', quick, plan, False)
        self.assertEqual(events, ['plan'])
        quick.configure_firewall.assert_not_called()
        ca.sync_panel_ca.assert_not_called()
        self.assertIs(manager.confirmation, original)


class PHPPreflightTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='xboard-php-preflight-')
        self.addCleanup(self.temp.cleanup)
        self.app = Path(self.temp.name) / 'site'
        self.app.mkdir()
        self.user = SimpleNamespace(pw_name='fixture-site')
        self.php = '/fixture/php83'

    def output(self, **values):
        result = {'version_id': 80300, 'symlink': True, 'ini': '/fixture/php83.ini'}
        result.update(values)
        return 'PHP startup warning fixture\nXBOARD_PHP_PREFLIGHT=' + json.dumps(result) + '\n'

    def test_capability_marker_is_read_after_startup_warnings_as_the_site_user(self):
        with patch.object(install, 'as_user', return_value=self.output()) as run:
            install.check_php(self.user, self.app, self.php, storage_link=True)
        run.assert_called_once()
        self.assertIs(run.call_args.args[0], self.user)
        self.assertEqual(run.call_args.args[1], self.app)
        self.assertEqual(run.call_args.args[2][:2], [self.php, '-r'])
        self.assertTrue(run.call_args.kwargs['capture'])

    def test_unavailable_or_truthy_nonboolean_symlink_fails_with_the_cli_ini_and_recovery(self):
        for unavailable in (False, None, 'true', 1):
            with self.subTest(symlink=unavailable):
                with patch.object(install, 'as_user', return_value=self.output(symlink=unavailable)):
                    with self.assertRaisesRegex(RuntimeError, r'symlink.*无需开放 exec.*php83.*php83\.ini'):
                        install.check_php(self.user, self.app, self.php, storage_link=True)

    def test_upgrade_without_link_creation_can_keep_symlink_disabled(self):
        with patch.object(install, 'as_user', return_value=self.output(symlink=False)):
            install.check_php(self.user, self.app, self.php, storage_link=False)

    def test_old_or_malformed_php_output_fails_closed(self):
        for output in (self.output(version_id=80199), self.output(version_id='80300'),
                       'unrecognized executable output', 'XBOARD_PHP_PREFLIGHT=not-json\n',
                       'XBOARD_PHP_PREFLIGHT=[]\n'):
            with self.subTest(output=output):
                with patch.object(install, 'as_user', return_value=output):
                    with self.assertRaises((RuntimeError, ValueError)):
                        install.check_php(self.user, self.app, self.php, storage_link=True)

    def test_fresh_install_preflight_stops_before_permission_composer_or_database_mutation(self):
        composer = self.app / 'composer.phar'
        composer.write_text('fixture')
        argv = ['install-panel.py', 'install', '--skip-endpoint', '--composer', str(composer)]
        with patch.object(install.sys, 'argv', argv), \
             patch.object(install.sys, 'platform', 'linux'), \
             patch.object(install.os, 'geteuid', return_value=0, create=True), \
             patch.object(install.os, 'umask'), \
             patch.object(install, 'application', return_value=self.app), \
             patch.object(install, 'site_user', return_value=self.user), \
             patch.object(install, 'executable', return_value=self.php), \
             patch.object(install, 'root_path', side_effect=Path), \
             patch.object(install, 'prepare_permissions') as permissions, \
             patch.object(install, 'check_composer') as composer_check, \
             patch.object(install, 'as_user', return_value=self.output(symlink=False)) as run:
            with self.assertRaisesRegex(RuntimeError, 'symlink'):
                install.main()
        permissions.assert_not_called()
        composer_check.assert_not_called()
        run.assert_called_once()
        self.assertEqual(run.call_args.args[2][:2], [self.php, '-r'])
        self.assertFalse((self.app / '.env').exists())

    @unittest.skipUnless(sys.platform == 'linux' and os.geteuid() == 0,
                         'Requires Linux Root, PHP 8.2+, and a real site-user boundary')
    def test_real_site_uid_php_disabled_functions_are_detected_without_requiring_exec(self):
        import pwd
        if not install.shutil.which('runuser'):
            self.skipTest('runuser is unavailable')
        try:
            user = pwd.getpwnam('nobody')
            php = install.executable(None, ['/usr/bin/php8.3', 'php8.3', 'php'])
        except (KeyError, RuntimeError):
            self.skipTest('A non-Root fixture user and trusted PHP CLI are required')
        Path(self.temp.name).chmod(0o755)
        self.app.chmod(0o755)
        actual_uid = install.as_user(user, self.app, [php, '-r', 'echo posix_geteuid();'], capture=True)
        self.assertEqual(actual_uid.strip(), str(user.pw_uid))
        real_as_user = install.as_user
        for disabled, should_pass in (('symlink,exec', False), ('exec', True)):
            with self.subTest(disabled_functions=disabled):
                def with_disabled_functions(site_user, app, argv, extra=None, capture=False):
                    self.assertEqual(argv[:2], [php, '-r'])
                    return real_as_user(site_user, app,
                                        [php, '-d', 'disable_functions=' + disabled, *argv[1:]], extra, capture)

                with patch.object(install, 'as_user', side_effect=with_disabled_functions):
                    if should_pass:
                        install.check_php(user, self.app, php, storage_link=True)
                    else:
                        with self.assertRaisesRegex(RuntimeError, 'symlink.*无需开放 exec'):
                            install.check_php(user, self.app, php, storage_link=True)


if __name__ == '__main__':
    unittest.main()
