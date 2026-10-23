"""Docker coordinator guards without a daemon or modifying an actual panel.

The fixed PHP stdin helper is executed against a small Artisan capture fixture.
Ownership, Docker, aaPanel, and real PKI integration remain Linux VM checks.
"""
from contextlib import ExitStack
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]
TOOLS = ROOT.parents[1] / 'tools/panel'
spec = importlib.util.spec_from_file_location('docker_root_installer', ROOT / 'scripts/install-docker.py')
install = importlib.util.module_from_spec(spec)
spec.loader.exec_module(install)


class TemporaryPanel(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='xboard-docker-guards-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name).resolve()
        self.app = self.base / 'app'
        self.app.mkdir()
        self.environment = self.app / '.env'

    def coordinator(self):
        with patch.object(install, 'PRIVATE_ROOT', self.base / 'private'):
            result = install.Installer(self.app)
        result.home.mkdir(parents=True)
        result.run_dir = result.home / 'logs'
        result.run_dir.mkdir()
        result.docker = '/usr/bin/docker'
        result.environment = {'PATH': install.SYSTEM_PATH, 'HOME': str(result.home), 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}
        return result

    def marker(self, coordinator):
        content = (json.dumps(install.compose_document(self.app, coordinator.key), indent=2) + '\n').encode()
        coordinator.private_compose.write_bytes(content)
        coordinator.public_compose.write_bytes(content)
        coordinator.marker = {'tool': install.TOOL, 'schema': 1, 'app': str(self.app), 'key': coordinator.key,
            'project': coordinator.project, 'compose_sha256': hashlib.sha256(content).hexdigest(),
            'host': '8.8.8.8', 'port': 8443, 'build_source_sha256': 'source-fixture'}
        coordinator.marker_path.write_text(json.dumps(coordinator.marker), encoding='utf-8')


class InstallationGuards(TemporaryPanel):
    def test_existing_install_key_is_read_only_and_never_regenerated(self):
        self.environment.write_text('INSTALLED=true\nAPP_KEY="base64:synthetic-existing"\nDB_PASSWORD=not-read\n')
        original = self.environment.read_bytes()
        flags = install.env_flags(self.environment)
        self.assertTrue(install.installed(flags))
        self.assertNotIn('DB_PASSWORD', flags)
        self.assertEqual(self.environment.read_bytes(), original)

    def test_partial_key_and_missing_key_both_refuse_without_changing_file(self):
        for value in ('APP_KEY=synthetic-existing\n', 'INSTALLED=true\n', 'APP_KEY=one\nAPP_KEY=two\n'):
            with self.subTest(value=value):
                self.environment.write_text(value)
                with self.assertRaises(RuntimeError):
                    install.installed(install.env_flags(self.environment))
                self.assertEqual(self.environment.read_text(), value)

    def test_fresh_empty_quoted_key_is_allowed(self):
        self.environment.write_text('APP_KEY=""\nINSTALLED=false\n')
        self.assertFalse(install.installed(install.env_flags(self.environment)))

    def test_mutable_source_is_refused_before_execution(self):
        source = self.app / 'Dockerfile'
        source.write_text('FROM scratch\n')
        for uid, mode, links in ((1000, 0o100644, 1), (0, 0o100664, 1), (0, 0o100644, 2)):
            with self.subTest(uid=uid, mode=mode, links=links), \
                 patch.object(install.os, 'fstat', return_value=SimpleNamespace(st_mode=mode, st_uid=uid, st_nlink=links)):
                with self.assertRaisesRegex(RuntimeError, 'Root'):
                    install.trusted_source(source)

    def test_runtime_fingerprint_detects_source_change(self):
        (self.app / 'Dockerfile').write_text('FROM audited\n')
        (self.app / 'composer.lock').write_text('{"version":1}')
        with patch.object(install, 'BUILD_PATHS', ('Dockerfile', 'composer.lock')), \
             patch.object(install, 'trusted_source', side_effect=lambda path: path.read_bytes()):
            first = install.build_source(self.app)
            (self.app / 'composer.lock').write_text('{"version":2}')
            self.assertNotEqual(install.build_source(self.app), first)

    def test_runtime_fingerprint_excludes_root_tools_and_credentials(self):
        self.assertNotIn('root-assets', install.BUILD_PATHS)
        self.assertNotIn('scripts', install.BUILD_PATHS)
        self.assertNotIn('.env', install.BUILD_PATHS)
        self.assertIn('.docker/.data', install.BUILD_EXCLUDED)
        self.assertIn('public/plugins', install.BUILD_EXCLUDED)

    def test_native_loader_uses_private_validated_snapshot(self):
        private = self.base / 'private-loader'
        private.mkdir()
        with patch.object(install, 'trusted_source', return_value=b'VERIFIED_SNAPSHOT = True\n') as snapshot:
            module = install.native_module(self.app, private)
        self.assertTrue(module.VERIFIED_SNAPSHOT)
        self.assertEqual(Path(module.__file__), private / 'verified-native-loader.py')
        snapshot.assert_called_once_with(self.app / 'scripts/install-panel.py')

    def test_private_setup_never_inherits_docker_or_proxy_controls(self):
        with patch.object(install, 'PRIVATE_ROOT', self.base / 'root-private'):
            coordinator = install.Installer(self.app)
        with patch.object(install, 'PRIVATE_ROOT', self.base / 'root-private'), \
             patch.object(install, 'root_path', side_effect=lambda value: Path(value)), \
             patch.object(install.shutil, 'which', return_value='/usr/bin/docker'), \
             patch.object(install.os, 'access', return_value=True), \
             patch.dict(os.environ, {'DOCKER_HOST': 'tcp://attacker:2375', 'DOCKER_CLI_PLUGIN_EXTRA_DIRS': '/tmp/plugins',
                                     'HTTP_PROXY': 'http://attacker', 'LD_PRELOAD': '/tmp/evil.so'}):
            coordinator.setup_private()
        self.assertEqual(set(coordinator.environment), {'PATH', 'HOME', 'LANG', 'LC_ALL'})
        self.assertEqual((coordinator.home / 'compose-empty.env').read_bytes(), b'')
        self.assertEqual(list((coordinator.home / 'docker-config').iterdir()), [])

    def test_compose_only_binds_selected_runtime_paths_and_loopback(self):
        document = install.compose_document(PurePosixPath('/www/wwwroot/panel$fixture'), 'fixture-key')
        service = document['services']['xboard']
        self.assertEqual(service['ports'], ['127.0.0.1:7001:7001'])
        self.assertNotIn('privileged', service)
        self.assertNotIn('pid', service)
        self.assertNotIn('network_mode', service)
        targets = {mount['target'] for mount in service['volumes']}
        self.assertEqual(targets, {'/www/.env', '/www/storage/app', '/www/storage/logs', '/www/storage/theme',
                                  '/www/.docker/.data', '/www/plugins', '/data'})
        self.assertTrue(all('panel$$fixture/' in mount['source'] for mount in service['volumes'] if mount['type'] == 'bind'))
        self.assertNotIn('docker.sock', json.dumps(document))
        self.assertNotIn('/var/lib/node-endpoint', json.dumps(document))
        self.assertEqual(document['networks']['default']['labels'][install.OWNER_LABEL], 'fixture-key')

    def test_application_compose_tamper_never_reaches_docker(self):
        coordinator = self.coordinator()
        self.marker(coordinator)
        coordinator.public_compose.write_text('services: {arbitrary: {image: attacker}}')
        with patch.object(install, 'root_path', side_effect=lambda path: Path(path)), \
             patch.object(coordinator, 'command') as command:
            with self.assertRaisesRegex(RuntimeError, 'Compose'):
                coordinator.compose(['up'], 'test')
            command.assert_not_called()

    def test_only_private_compose_and_empty_explicit_env_are_executed(self):
        coordinator = self.coordinator()
        self.marker(coordinator)
        with patch.object(install, 'root_path', side_effect=lambda path: Path(path)), \
             patch.object(coordinator, 'command', return_value=(0, b'')) as command:
            coordinator.compose(['up', '-d'], 'start')
        argv = command.call_args.args[0]
        self.assertEqual(argv[argv.index('--file') + 1], str(coordinator.private_compose))
        self.assertNotEqual(argv[argv.index('--file') + 1], str(coordinator.public_compose))
        self.assertEqual(argv[argv.index('--env-file') + 1], str(coordinator.home / 'compose-empty.env'))
        self.assertEqual(argv[argv.index('--project-directory') + 1], str(self.app))

    def test_docker_cli_uses_fixed_socket_config_and_clean_environment(self):
        coordinator = self.coordinator()
        captured = {}

        def run(argv, **kwargs):
            captured.update(argv=argv, **kwargs)
            kwargs['stdout'].write(b'27.0.0\n')
            return SimpleNamespace(returncode=0)

        with patch.dict(os.environ, {'DOCKER_HOST': 'tcp://attacker:2375', 'DOCKER_CONTEXT': 'evil',
                                   'DOCKER_CONFIG': '/tmp/evil', 'HTTP_PROXY': 'http://evil', 'DB_PASSWORD': 'secret-not-forwarded'}), \
             patch.object(install.subprocess, 'run', side_effect=run):
            _, output = coordinator.command(['version'], 'version')
        self.assertEqual(output, b'27.0.0\n')
        self.assertEqual(captured['argv'][:5], ['/usr/bin/docker', '--host', install.LOCAL_DAEMON, '--config', str(coordinator.home / 'docker-config')])
        self.assertEqual(captured['env'], coordinator.environment)
        self.assertTrue(all(name not in captured['env'] for name in ('DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'HTTP_PROXY', 'DB_PASSWORD')))
        self.assertNotIn('secret-not-forwarded', repr(captured['argv']))
        if os.name != 'nt':
            self.assertTrue(all(stat.S_IMODE(path.stat().st_mode) == 0o600 for path in coordinator.run_dir.iterdir()))

    def test_failed_command_only_reports_private_diagnostic_path(self):
        coordinator = self.coordinator()

        def run(argv, **kwargs):
            kwargs['stderr'].write(b'secret server error never printed')
            return SimpleNamespace(returncode=7)

        with patch.object(install.subprocess, 'run', side_effect=run):
            with self.assertRaises(RuntimeError) as failure:
                coordinator.command(['version'], 'version')
        self.assertIn(str(coordinator.run_dir), str(failure.exception))
        self.assertNotIn('secret server error', str(failure.exception))

    def test_metadata_response_is_bounded(self):
        coordinator = self.coordinator()

        def run(argv, **kwargs):
            kwargs['stdout'].write(b'x' * (2 * 1024 * 1024 + 1))
            return SimpleNamespace(returncode=0)

        with patch.object(install.subprocess, 'run', side_effect=run):
            with self.assertRaisesRegex(RuntimeError, '响应过大'):
                coordinator.command(['inspect'], 'inspect')
            self.assertEqual(coordinator.command(['compose', 'build'], 'build')[1], b'')

    def test_existing_application_never_installs_updates_or_stops(self):
        coordinator = self.coordinator()
        self.environment.write_text('APP_KEY=synthetic-existing\nINSTALLED=true\n')
        original = self.environment.read_bytes()
        with patch.object(coordinator, 'compose') as compose, patch.object(coordinator, 'save_marker'):
            coordinator.run_application(False, 'mysql', None)
        compose.assert_not_called()
        self.assertEqual(self.environment.read_bytes(), original)

    def test_mysql_password_only_enters_stdin_not_argv(self):
        coordinator = self.coordinator()
        secret = 'synthetic;$(must-not-execute)\nquote"value'

        def compose(argv, label, **kwargs):
            self.assertNotIn(secret, repr(argv))
            payload = json.loads(kwargs['data'])
            self.assertEqual(payload['mysql']['password'], secret)
            self.assertEqual(argv[argv.index('--user') + 1], '1000:1000')
            self.assertEqual(argv[argv.index('--entrypoint') + 1], '/usr/local/bin/php')
            self.environment.write_text('APP_KEY=synthetic-new\nINSTALLED=true\n')

        with patch.object(coordinator, 'compose', side_effect=compose), patch.object(coordinator, 'save_marker'):
            coordinator.run_application(True, 'mysql', 'test@example.com', {'password': secret})

    def test_missing_installed_flag_after_application_failure_is_not_success(self):
        coordinator = self.coordinator()
        self.environment.write_text('')
        with patch.object(coordinator, 'compose'), patch.object(coordinator, 'save_marker') as saved:
            with self.assertRaisesRegex(RuntimeError, '安装标记'):
                coordinator.run_application(True, 'sqlite', 'test@example.com')
            saved.assert_not_called()

    def test_backend_boot_wait_accepts_authenticated_route_guard_without_secrets(self):
        connection = Mock()
        connection.getresponse.return_value.status = 401
        with patch.object(install.http.client, 'HTTPConnection', return_value=connection):
            install.wait_for_backend('panel.example')
        connection.request.assert_called_once_with('GET', '/api/v2/server/handshake', headers={'Host': 'panel.example', 'Connection': 'close'})
        connection.close.assert_called_once()

    def test_backend_boot_timeout_keeps_application_for_same_command_retry(self):
        connection = Mock()
        connection.getresponse.return_value.status = 502
        with patch.object(install.http.client, 'HTTPConnection', return_value=connection), \
             patch.object(install.time, 'monotonic', side_effect=[0, 0, 91]), patch.object(install.time, 'sleep'):
            with self.assertRaisesRegex(RuntimeError, '重跑同一命令'):
                install.wait_for_backend('panel.example')
        connection.getresponse.return_value.read.assert_not_called()


class DockerResourceGuards(TemporaryPanel):
    def preflight_command(self, coordinator, *, resources=None, containers=None):
        resources = resources or {}
        containers = containers or {}

        def command(argv, label, **kwargs):
            if argv[0] == 'ps':
                return 0, '\n'.join(containers).encode()
            if argv[0] == 'inspect':
                return 0, json.dumps(containers[argv[-1]][label]).encode()
            if argv[0] in ('volume', 'network', 'image'):
                labels = resources.get(argv[0])
                return (0, json.dumps(labels).encode()) if labels is not None else (1, b'')
            return 0, b'27.0.0'
        return command

    def guards(self):
        stack = ExitStack()
        self.addCleanup(stack.close)
        stack.enter_context(patch.object(install, 'root_path', side_effect=lambda path: Path(path)))
        stack.enter_context(patch.object(Path, 'is_socket', return_value=True, create=True))
        self.listener = stack.enter_context(patch.object(install.socket, 'socket'))
        return stack

    def test_foreign_same_name_resources_are_refused_before_reuse(self):
        for kind in ('volume', 'network', 'image'):
            with self.subTest(kind=kind):
                coordinator = self.coordinator()
                with patch.object(install, 'root_path', side_effect=lambda path: Path(path)), \
                     patch.object(Path, 'is_socket', return_value=True, create=True), \
                     patch.object(coordinator, 'command', side_effect=self.preflight_command(coordinator, resources={kind: {}})):
                    with self.assertRaisesRegex(RuntimeError, '无归属'):
                        coordinator.preflight({})
                shutil.rmtree(coordinator.home)

    def test_owned_marker_does_not_adopt_a_foreign_container(self):
        coordinator = self.coordinator()
        self.marker(coordinator)
        identity = 'a' * 64
        self.guards()
        with patch.object(coordinator, 'command', side_effect=self.preflight_command(coordinator, containers={identity: {'container-owner': {}}})):
            with self.assertRaisesRegex(RuntimeError, '外来容器'):
                coordinator.preflight({'INSTALLED': 'true', 'APP_KEY': 'existing'})

    def test_foreign_listener_on_7001_is_preserved(self):
        coordinator = self.coordinator()
        self.guards()
        self.listener.return_value.__enter__.return_value.bind.side_effect = OSError('occupied')
        with patch.object(coordinator, 'command', side_effect=self.preflight_command(coordinator)):
            with self.assertRaisesRegex(RuntimeError, '其他服务占用'):
                coordinator.preflight({})

    def test_owned_loopback_container_can_be_reused_without_binding_or_stopping(self):
        coordinator = self.coordinator()
        self.marker(coordinator)
        identity = 'b' * 64
        self.guards()
        container = {'container-owner': {install.OWNER_LABEL: coordinator.key},
                     'container-backend': {'7001/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '7001'}]}}
        with patch.object(coordinator, 'command', side_effect=self.preflight_command(coordinator, containers={identity: container})):
            coordinator.preflight({'INSTALLED': 'true', 'APP_KEY': 'existing'})
        self.listener.assert_not_called()

    def test_existing_sqlite_without_install_markers_is_never_reinitialized(self):
        coordinator = self.coordinator()
        database = self.app / '.docker/.data/database.sqlite'
        database.parent.mkdir(parents=True)
        database.write_bytes(b'existing-database')
        self.guards()
        with patch.object(coordinator, 'command', side_effect=self.preflight_command(coordinator)):
            with self.assertRaisesRegex(RuntimeError, 'SQLite'):
                coordinator.preflight({})
        self.assertEqual(database.read_bytes(), b'existing-database')

    def test_installed_directory_without_marker_is_an_explicit_migration_boundary(self):
        coordinator = self.coordinator()
        self.guards()
        with patch.object(coordinator, 'command', side_effect=self.preflight_command(coordinator)):
            with self.assertRaisesRegex(RuntimeError, '单独迁移'):
                coordinator.preflight({'INSTALLED': 'true', 'APP_KEY': 'existing'})

    def test_owned_marker_refuses_replaced_app_key_before_any_container_action(self):
        coordinator = self.coordinator()
        self.marker(coordinator)
        coordinator.marker['app_key_sha256'] = hashlib.sha256(b'original-key').hexdigest()
        coordinator.marker_path.write_text(json.dumps(coordinator.marker))
        self.guards()
        with patch.object(coordinator, 'command', side_effect=self.preflight_command(coordinator)) as command:
            with self.assertRaisesRegex(RuntimeError, 'APP_KEY'):
                coordinator.preflight({'INSTALLED': 'true', 'APP_KEY': 'replacement-key'})
        self.assertEqual(command.call_count, 2)  # Only read-only version checks.


@unittest.skipUnless(TOOLS.is_dir(), 'Root parser source is required for Nginx fixtures')
class NginxSelectionTests(unittest.TestCase):
    def setUp(self):
        self.path = str(TOOLS)
        sys.path.insert(0, self.path)
        self.addCleanup(lambda: sys.path.remove(self.path))
        import nginx
        self.nginx = nginx
        self.manager = SimpleNamespace(Manager=Mock(return_value=SimpleNamespace(installed=None)), trusted_file=Mock())

    def environment(self, files):
        nodes = []
        for filename, text in files.items():
            nodes.extend(self.nginx.parse(text, filename))
        return SimpleNamespace(files=files, nodes=nodes, prefix='/fixture')

    def test_real_site_text_is_locked_by_hash_and_original_is_never_changed(self):
        text = 'server { listen 80; server_name panel.example; location / { proxy_pass http://127.0.0.1:7001; } }\n'
        environment = self.environment({'/fixture/panel.conf': text})
        selected = install.site_preflight(environment, self.manager)
        self.assertEqual(selected, {'site_config': '/fixture/panel.conf', 'site_name': 'panel.example',
            'backend': 'http://127.0.0.1:7001', 'source_file_sha256': hashlib.sha256(text.encode()).hexdigest()})
        self.assertEqual(environment.files['/fixture/panel.conf'], text)
        self.manager.trusted_file.assert_called_once_with('/fixture/panel.conf')

    def test_multiple_proxy_sites_are_not_guessed(self):
        text = 'server { server_name panel.example; location / { proxy_pass http://127.0.0.1:7001; } }'
        with self.assertRaisesRegex(RuntimeError, '唯一反向代理'):
            install.site_preflight(self.environment({'/fixture/a.conf': text, '/fixture/b.conf': text}), self.manager)

    def test_external_or_variable_secondary_proxy_refuses_site(self):
        for target in ('http://remote.example:7001', 'http://$backend', 'http://127.0.0.1:7002'):
            with self.subTest(target=target):
                text = 'server { server_name panel.example; location / { proxy_pass http://127.0.0.1:7001; } location /other { proxy_pass ' + target + '; } }'
                with self.assertRaisesRegex(RuntimeError, '唯一反向代理'):
                    install.site_preflight(self.environment({'/fixture/panel.conf': text}), self.manager)


class CombinedPlanTests(TemporaryPanel):
    def setup_main(self, existing=False, recorded_source='source-fixture'):
        coordinator = SimpleNamespace(home=self.base / 'owned', run_dir=self.base / 'private-logs',
            public_compose=self.app / install.COMPOSE_NAME, marker=None)
        coordinator.home.mkdir()
        if existing:
            self.environment.write_text('INSTALLED=true\nAPP_KEY=synthetic-existing\nDB_CONNECTION=mysql\n')
            coordinator.marker = {'host': '8.8.8.8', 'port': 8443, 'build_source_sha256': recorded_source}
        for name in ('setup_private', 'preflight', 'prepare_runtime', 'prepare_compose', 'save_marker', 'run_application', 'compose'):
            setattr(coordinator, name, Mock())
        self.quick = SimpleNamespace(docker_setup=Mock(), reject_example_host=lambda value: value)
        self.site = {'site_config': '/fixture/panel.conf', 'site_name': 'panel.example',
                     'backend': 'http://127.0.0.1:7001', 'source_file_sha256': 'site-fixture'}
        stack = ExitStack()
        self.addCleanup(stack.close)
        stack.enter_context(patch.object(install.sys, 'platform', 'linux'))
        stack.enter_context(patch.object(install.os, 'geteuid', return_value=0, create=True))
        stack.enter_context(patch.object(install.os, 'umask'))
        self.chown = stack.enter_context(patch.object(install.os, 'chown', create=True))
        stack.enter_context(patch.object(install.os, 'fstat', return_value=SimpleNamespace(st_mode=0o100600, st_uid=0, st_nlink=1)))
        stack.enter_context(patch.object(install, 'application', return_value=self.app))
        stack.enter_context(patch.object(install, 'root_path', side_effect=lambda path: Path(path)))
        stack.enter_context(patch.object(install, 'build_source', return_value='source-fixture'))
        self.native = stack.enter_context(patch.object(install, 'native_module', return_value=SimpleNamespace(root_tools=lambda app: self.quick)))
        stack.enter_context(patch.object(install, 'wait_for_backend'))
        stack.enter_context(patch.object(install, 'site_preflight', return_value=self.site))
        stack.enter_context(patch.object(install, 'Installer', return_value=coordinator))
        stack.enter_context(patch.object(install.sys, 'stdin', SimpleNamespace(isatty=lambda: True)))
        stack.enter_context(patch.object(install.sys, 'stdout', io.StringIO()))
        stack.enter_context(patch.dict(sys.modules, {'fcntl': SimpleNamespace(LOCK_EX=1, LOCK_NB=2, flock=Mock()),
                    'manager': SimpleNamespace(Environment=Mock())}))
        self.coordinator_object = coordinator
        return coordinator

    def arguments(self):
        return ['--app-dir', str(self.app), '--host', '8.8.8.8', '--email', 'admin@example.com', '--nginx', '/fixture/nginx', '--database', 'sqlite']

    def test_default_mysql_is_in_reviewed_plan_without_exposing_password(self):
        coordinator = self.setup_main()
        connection = {'host': 'host.docker.internal', 'port': '3306', 'database': 'panel_test',
                      'username': 'panel_test', 'password': 'must-not-print-this-secret'}
        with patch.object(install, 'mysql_inputs', return_value=connection), patch('builtins.input', return_value='YES') as prompt:
            install.main(self.arguments()[:-2])
        self.assertEqual(prompt.call_count, 1)
        coordinator.run_application.assert_called_once_with(True, 'mysql', 'admin@example.com', connection)
        output = install.sys.stdout.getvalue()
        self.assertIn('host.docker.internal', output)
        self.assertNotIn(connection['password'], output)

    def test_fresh_mysql_without_tty_cannot_fall_back_to_echoed_password_input(self):
        coordinator = self.setup_main()
        with patch.object(install.sys,'stdin',SimpleNamespace(isatty=lambda:False)), \
                patch.object(install,'mysql_inputs') as password_prompt:
            with self.assertRaisesRegex(RuntimeError,'隐藏输入密码'):
                install.main([*self.arguments()[:-2],'--yes'])
        password_prompt.assert_not_called()
        coordinator.prepare_runtime.assert_not_called()
        coordinator.compose.assert_not_called()

    def test_cancel_before_any_application_or_nginx_mutation(self):
        coordinator = self.setup_main()
        with patch('builtins.input', return_value='no'):
            with self.assertRaisesRegex(RuntimeError, '已取消'):
                install.main(self.arguments())
        coordinator.prepare_runtime.assert_not_called()
        coordinator.prepare_compose.assert_not_called()
        coordinator.compose.assert_not_called()
        self.chown.assert_not_called()
        self.quick.docker_setup.assert_not_called()

    def test_one_yes_authorizes_fresh_install_and_exact_preapproved_endpoint(self):
        coordinator = self.setup_main()
        with patch('builtins.input', return_value='YES') as prompt:
            install.main(self.arguments())
        self.assertEqual(prompt.call_count, 1)
        coordinator.prepare_runtime.assert_called_once()
        self.chown.assert_called_once_with(self.app, 0, 0, follow_symlinks=False)
        self.assertEqual(coordinator.compose.call_args_list[0].args[0], ['build', '--pull=false', 'xboard'])
        self.assertEqual(coordinator.compose.call_args_list[-1].args[0], ['up', '-d', '--no-build', '--no-recreate', 'xboard'])
        self.quick.docker_setup.assert_called_once_with(self.app, nginx='/fixture/nginx', host='8.8.8.8', port=8443,
            simple_confirm=True, confirmed=True, expected_backend='http://127.0.0.1:7001', expected_site=self.site, skip_firewall=False)

    def test_same_source_owned_retry_never_builds_stops_or_initializes(self):
        coordinator = self.setup_main(existing=True)
        original = self.environment.read_bytes()
        with patch('builtins.input', return_value='YES'):
            install.main(self.arguments())
        coordinator.prepare_runtime.assert_not_called()
        self.chown.assert_not_called()
        coordinator.compose.assert_called_once_with(['up', '-d', '--no-build', '--no-recreate', 'xboard'], 'start-owned-service', timeout=300)
        coordinator.run_application.assert_called_once_with(False, 'sqlite', None, None)
        self.assertEqual(self.environment.read_bytes(), original)

    def test_runtime_source_change_requires_separate_reviewed_upgrade(self):
        coordinator = self.setup_main(existing=True, recorded_source='different-source')
        with self.assertRaisesRegex(RuntimeError, '升级教程'):
            install.main(self.arguments())
        coordinator.prepare_runtime.assert_not_called()
        coordinator.compose.assert_not_called()
        self.native.assert_not_called()

    def test_env_mutation_during_confirmation_is_refused(self):
        coordinator = self.setup_main(existing=True)

        def answer(prompt):
            self.environment.write_text('INSTALLED=true\nAPP_KEY=synthetic-changed\n')
            return 'YES'

        with patch('builtins.input', side_effect=answer):
            with self.assertRaisesRegex(RuntimeError, '.env'):
                install.main(self.arguments())
        coordinator.compose.assert_not_called()
        self.quick.docker_setup.assert_not_called()

    def test_www_ownership_handoff_rechecks_source_before_build_or_runtime_writes(self):
        coordinator = self.setup_main()
        with patch('builtins.input', return_value='YES'), \
             patch.object(install, 'build_source', side_effect=['source-fixture', 'source-fixture', 'swapped-during-handoff']):
            with self.assertRaisesRegex(RuntimeError, 'Root 权限接管'):
                install.main(self.arguments())
        self.chown.assert_called_once()
        coordinator.prepare_runtime.assert_not_called()
        coordinator.prepare_compose.assert_not_called()
        coordinator.compose.assert_not_called()
        self.quick.docker_setup.assert_not_called()


@unittest.skipUnless(shutil.which('php'), 'PHP CLI is required for the fixed stdin helper fixture')
class FixedPHPHelperTests(TemporaryPanel):
    def helper(self, environment=''):
        (self.app / 'vendor').mkdir()
        (self.app / 'bootstrap').mkdir()
        (self.app / '.env').write_text(environment)
        (self.app / 'vendor/autoload.php').write_text('''<?php
namespace Illuminate\\Support\\Facades;
class Artisan {
    public static function call($command, $args) { echo json_encode(['command'=>$command,'args'=>$args,'email'=>getenv('ADMIN_ACCOUNT')]); return 0; }
    public static function output() { return ''; }
}
''')
        (self.app / 'bootstrap/app.php').write_text('''<?php
return new class { public function make($class) { return new class { public function bootstrap() {} }; } };
''')
        helper = install.APP_INSTALL_HELPER
        identity = "if (!function_exists('posix_geteuid') || posix_geteuid() !== 1000 || getcwd() !== '/www') { exit(91); }"
        self.assertIn(identity, helper)
        # This fixture changes only the Linux identity/path guard and the root
        # directory. It exercises the production parser and Artisan call body.
        helper = helper.replace(identity, '').replace('/www', self.app.as_posix())
        return [shutil.which('php'), '-n', '-d', 'display_errors=0', '-r', helper]

    def test_password_uses_stdin_and_only_artisan_memory_options(self):
        argv = self.helper('APP_KEY=""\nINSTALLED=false\n')
        secret = 'test-only;$(no-shell)"quoted\nvalue'
        payload = {'database': 'mysql', 'email': 'admin@example.com', 'mysql': {'host': 'db.internal', 'port': '3306',
                   'database': 'empty_test', 'username': 'test_user', 'password': secret}}
        self.assertNotIn(secret, repr(argv))
        result = subprocess.run(argv, input=json.dumps(payload).encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(result.returncode, 0, result.stderr.decode(errors='replace'))
        capture = json.loads(result.stdout)
        self.assertEqual(capture['command'], 'xboard:install')
        self.assertTrue(capture['args']['--no-interaction'])
        self.assertEqual(capture['args']['--db-password'], secret)
        self.assertEqual(capture['email'], 'admin@example.com')

    def test_fixed_helper_refuses_existing_key_before_loading_application(self):
        argv = self.helper('APP_KEY=synthetic-existing\nINSTALLED=true\n')
        result = subprocess.run(argv, input=b'{"database":"sqlite","email":"admin@example.com"}', stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(result.returncode, 94)
        self.assertEqual(result.stdout, b'')

    def test_fixed_helper_bounds_untrusted_stdin(self):
        argv = self.helper()
        result = subprocess.run(argv, input=b'x' * 32769, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.assertEqual(result.returncode, 92)


if __name__ == '__main__':
    unittest.main()
