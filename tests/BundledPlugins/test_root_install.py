"""Portable guards/command tests; real ownership and FPM checks run on Linux separately."""
import importlib.util
import io
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
if os.name == 'nt':
    sys.modules.setdefault('pwd', SimpleNamespace())


def load(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'scripts' / (name.replace('_', '-') + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


install = load('install_panel')
deploy = load('deploy_release')


class InstallerGuards(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='xboard-root-unit-')
        self.addCleanup(self.temp.cleanup)
        self.app = Path(self.temp.name) / 'site'
        self.app.mkdir()
        self.app = self.app.resolve()
        (self.app / 'bundled-plugins').mkdir()
        for name in ('artisan', 'composer.json', 'composer.lock', 'bundled-plugins/manifest.json'):
            (self.app / name).write_text('{}')
        self.args = SimpleNamespace(app_dir=str(self.app), action='install', deployed_code=False)

    def test_fresh_accepts_empty_site_but_existing_key_is_never_replaced(self):
        self.assertEqual(install.application(self.args), self.app)
        path = self.app / '.env'
        for value in ('APP_KEY=base64:synthetic-preserve\n', 'INSTALLED=true\nAPP_KEY=base64:synthetic-preserve\n'):
            path.write_text(value)
            before = path.read_bytes()
            with self.assertRaisesRegex(RuntimeError, 'APP_KEY'):
                install.application(self.args)
            self.assertEqual(path.read_bytes(), before)

    def test_upgrade_requires_existing_install_and_explicit_deployed_code(self):
        self.args.action = 'upgrade'
        with self.assertRaises(RuntimeError):
            install.application(self.args)
        (self.app / '.env').write_text('INSTALLED=true\nAPP_KEY=synthetic-key\n')
        with self.assertRaisesRegex(RuntimeError, 'deployed-code'):
            install.application(self.args)
        self.args.deployed_code = True
        self.assertEqual(install.application(self.args), self.app)

    def test_symlink_escape_is_refused_before_ownership_changes(self):
        if os.name == 'nt':
            self.skipTest('Native symlink permissions are checked on Linux')
        (self.app / 'app').mkdir()
        outside = Path(self.temp.name) / 'outside'
        outside.write_text('preserve')
        (self.app / 'app' / 'escape').symlink_to(outside)
        with patch.object(install.os, 'chown') as chown:
            with self.assertRaisesRegex(RuntimeError, '链接'):
                install.prepare_permissions(self.app, SimpleNamespace(pw_uid=1234, pw_gid=1234))
            chown.assert_not_called()
        self.assertEqual(outside.read_text(), 'preserve')

    def test_root_php_is_never_executed_and_password_is_not_an_argument(self):
        user = SimpleNamespace(pw_name='www', pw_dir='/var/www')
        with patch.object(install, 'executable', return_value='/usr/sbin/runuser'), \
             patch.object(install.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=b'OK')) as run, \
             patch.dict(os.environ, {'XBOARD_DB_PASSWORD': 'synthetic-test-secret', 'UNRELATED_SECRET': 'not-forwarded'}):
            result = install.as_user(user, self.app, ['/usr/bin/php', 'artisan', 'xboard:install'], capture=True)
        self.assertEqual(result, 'OK')
        argv = run.call_args.args[0]
        self.assertEqual(argv[:4], ['/usr/sbin/runuser', '-u', 'www', '--'])
        self.assertNotIn('synthetic-test-secret', repr(argv))
        environment = run.call_args.kwargs['env']
        self.assertEqual(environment['DB_PASSWORD'], 'synthetic-test-secret')
        self.assertNotIn('UNRELATED_SECRET', environment)

    def test_nonzero_application_step_does_not_report_success(self):
        user = SimpleNamespace(pw_name='www', pw_dir='/var/www')
        with patch.object(install, 'executable', return_value='/usr/sbin/runuser'), \
             patch.object(install.subprocess, 'run', return_value=SimpleNamespace(returncode=7)):
            with self.assertRaisesRegex(RuntimeError, '未完成'):
                install.as_user(user, self.app, ['/usr/bin/php', 'artisan', 'xboard:update'])

    def composer_fixture(self, output):
        """Run a real version-output process, replacing only the runuser launcher."""
        fixture = self.app / 'composer-version-fixture.py'
        fixture.write_text('import sys\nassert sys.argv[1:] == ["--no-ansi", "--version"]\nprint(' + repr(output) + ')\n', encoding='utf-8')
        user = SimpleNamespace(pw_name='fixture-site-user', pw_dir=str(self.app))
        real_run = subprocess.run

        def launch(argv, **kwargs):
            self.assertEqual(argv[:4], ['/fixture/runuser', '-u', user.pw_name, '--'])
            self.assertEqual(kwargs['env']['COMPOSER_CACHE_DIR'], str(self.app / 'cache'))
            self.assertEqual(kwargs['env']['COMPOSER_HOME'], str(self.app / 'cache') + '/home')
            return real_run(argv[4:], **kwargs)

        with patch.object(install, 'executable', return_value='/fixture/runuser'), \
             patch.object(install.subprocess, 'run', side_effect=launch), \
             patch('sys.stdout', new_callable=io.StringIO):
            install.check_composer(user, self.app, sys.executable, str(fixture), str(self.app / 'cache'))

    def test_old_composer_version_is_rejected_with_the_explicit_phar_recovery(self):
        for version in ('1.10.27', '2.0.13', '2.1.14'):
            with self.subTest(version=version):
                with self.assertRaisesRegex(RuntimeError, 'Composer ' + version + r'.*2\.2.*--composer .*composer\.phar'):
                    self.composer_fixture('Composer version ' + version + ' 2021-04-27 13:11:08')

    def test_composer_22_and_newer_are_accepted_from_real_version_processes(self):
        for output in ('Composer version 2.2.0 2021-12-22 11:02:10',
                       'Composer version 2.2.25 2024-12-11 11:25:54',
                       'Composer version 2.8.12 2025-09-19 13:41:59',
                       'Composer 2.10.1 2026-01-01 00:00:00'):
            with self.subTest(output=output):
                self.composer_fixture(output)

    def test_unrecognized_composer_version_is_refused(self):
        with self.assertRaisesRegex(RuntimeError, '无法识别 Composer 版本.*--composer'):
            self.composer_fixture('Composer unknown')

    def test_composer_preflight_failure_explains_site_user_path_and_permission_checks(self):
        with patch.object(install, 'as_user', side_effect=RuntimeError('synthetic inaccessible PHAR')):
            with self.assertRaisesRegex(RuntimeError, '网站用户.*读取权限.*--composer'):
                install.check_composer(SimpleNamespace(), self.app, '/usr/bin/php', '/trusted/composer.phar', '/cache')

    def test_old_composer_stops_main_before_dependency_install_or_artisan(self):
        composer = self.app / 'composer.phar'
        composer.write_text('version fixture')
        argv = ['install-panel.py', 'install', '--skip-endpoint', '--composer', str(composer)]
        with patch.object(install.sys, 'argv', argv), \
             patch.object(install.sys, 'platform', 'linux'), \
             patch.object(install.os, 'geteuid', return_value=0, create=True), \
             patch.object(install.os, 'umask'), \
             patch.object(install, 'application', return_value=self.app), \
             patch.object(install, 'site_user', return_value=SimpleNamespace()), \
             patch.object(install, 'executable', return_value='/usr/bin/php'), \
             patch.object(install, 'root_path', side_effect=Path), \
             patch.object(install, 'prepare_permissions', return_value='/fixture/cache'), \
             patch.object(install, 'as_user', side_effect=['', 'Composer version 2.0.13 2021-04-27 13:11:08']) as run:
            with self.assertRaisesRegex(RuntimeError, r'Composer 2\.0\.13.*2\.2'):
                install.main()
        self.assertEqual(run.call_count, 2)
        commands = [call.args[2] for call in run.call_args_list]
        self.assertEqual(commands[-1][-2:], ['--no-ansi', '--version'])
        self.assertFalse(any('install' in command or 'artisan' in command for command in commands))

    @unittest.skipUnless(sys.platform == 'linux' and os.geteuid() == 0, 'Requires Linux Root and a real site-user boundary')
    def test_root_umask_cache_parent_is_traversable_and_uid_cache_remains_private(self):
        import pwd
        if not install.shutil.which('runuser'):
            self.skipTest('runuser is required for the real UID probe')
        try:
            user = pwd.getpwnam('nobody')
        except KeyError:
            self.skipTest('The nobody fixture account is unavailable')
        self.assertNotEqual(user.pw_uid, 0)
        self.assertNotEqual(user.pw_gid, 0)
        Path(self.temp.name).chmod(0o755)
        (self.app / '.env.example').write_text('APP_KEY=\n')
        parent = Path(self.temp.name) / 'composer-cache'
        original_root_path = install.root_path

        def fixture_cache(path):
            return parent if str(path) == '/var/cache/xboard-composer' else original_root_path(path)

        probe = ('import os, pathlib, sys; cache = pathlib.Path(sys.argv[1]); '
                 '(cache / "probe").write_text("site-user-write"); '
                 'assert not os.access(cache.parent / "other-uid" / "secret", os.R_OK); '
                 'print(os.geteuid())')
        for repair_existing_parent in (False, True):
            with self.subTest(repair_existing_parent=repair_existing_parent):
                if repair_existing_parent:
                    parent.chmod(0o700)  # Reproduce a cache left by the old installer.
                previous_mask = os.umask(0o077)
                try:
                    with patch.object(install, 'root_path', side_effect=fixture_cache), \
                         patch('sys.stdout', new_callable=io.StringIO):
                        cache = Path(install.prepare_permissions(self.app, user))
                finally:
                    os.umask(previous_mask)
                self.assertEqual(parent.stat().st_uid, 0)
                self.assertEqual(parent.stat().st_mode & 0o777, 0o755)
                self.assertEqual(cache.stat().st_uid, user.pw_uid)
                self.assertEqual(cache.stat().st_mode & 0o777, 0o700)
                other = parent / 'other-uid'
                other.mkdir(mode=0o700, exist_ok=True)
                other.chmod(0o700)
                (other / 'secret').write_text('synthetic-private-cache')
                result = install.as_user(user, self.app, [sys.executable, '-c', probe, cache], capture=True)
                self.assertEqual(result.strip(), str(user.pw_uid))
                self.assertEqual((cache / 'probe').read_text(), 'site-user-write')

    def test_runtime_directory_parents_are_prepared_before_private_children(self):
        # Source archives omit empty runtime directories. Root's restrictive
        # umask must not leave an intermediate directory owned by Root.
        positions = {name: index for index, name in enumerate(install.RUNTIME_DIRS)}
        for name in install.RUNTIME_DIRS:
            parent = str(Path(name).parent).replace('\\', '/')
            if parent.startswith('storage'):
                self.assertIn(parent, positions)
                self.assertLess(positions[parent], positions[name])

    def test_release_source_permissions_cover_docs_but_leave_docker_data_alone(self):
        (self.app / 'docs').mkdir()
        (self.app / 'docs' / 'guide.md').write_text('public guide')
        (self.app / 'compose.sample.yaml').write_text('public sample')
        data = self.app / '.docker' / '.data'
        data.mkdir(parents=True)
        database = data / 'existing.sqlite'
        database.write_text('preserve volume')
        paths = install.permission_paths(self.app)
        self.assertIn(self.app / 'docs' / 'guide.md', paths)
        self.assertIn(self.app / 'compose.sample.yaml', paths)
        self.assertNotIn(data, paths)
        self.assertNotIn(database, paths)

    def test_empty_native_storage_link_is_preserved_but_external_dangling_link_is_refused(self):
        if os.name == 'nt':
            self.skipTest('Native storage links are checked on Linux')
        public = self.app / 'public'
        public.mkdir()
        link = public / 'storage'
        link.symlink_to(self.app / 'storage' / 'app' / 'public', target_is_directory=True)
        self.assertNotIn(link, install.permission_paths(self.app))
        link.unlink()
        link.symlink_to(Path(self.temp.name) / 'outside-absent', target_is_directory=True)
        with self.assertRaisesRegex(RuntimeError, '链接'):
            install.permission_paths(self.app)

    def test_only_https_download_redirects_are_accepted(self):
        with self.assertRaisesRegex(RuntimeError, 'HTTPS'):
            install.HTTPSOnly().redirect_request(None, None, 302, '', {}, 'http://example.test/tools')

    def test_root_tool_asset_bytes_match_pins_without_importing_app_code(self):
        import hashlib
        self.assertEqual(hashlib.sha256((ROOT / 'root-assets/quick_install.py').read_bytes()).hexdigest(), install.BOOTSTRAP_SHA256)
        self.assertEqual(hashlib.sha256((ROOT / 'root-assets/node-endpoint-tools-1.0.3.zip').read_bytes()).hexdigest(), install.TOOLS_SHA256)


class ReleaseGuards(unittest.TestCase):
    def archive(self, extra=None):
        handle = tempfile.NamedTemporaryFile(suffix='.tar.gz', delete=False)
        handle.close()
        path = Path(handle.name)
        self.addCleanup(path.unlink)
        names = ['artisan', 'composer.json', 'composer.lock', 'bundled-plugins/manifest.json', 'scripts/install-panel.py']
        with tarfile.open(path, 'w:gz') as output:
            for name in names:
                info = tarfile.TarInfo(name); info.size = 2; info.mode = 0o644
                output.addfile(info, io.BytesIO(b'{}'))
            if extra:
                output.addfile(extra, io.BytesIO(b'x' * extra.size) if extra.isfile() else None)
        return path

    def test_valid_source_release_and_environment_template(self):
        info = tarfile.TarInfo('.env.example'); info.size = 1
        self.assertEqual(len(deploy.archive_members(self.archive(info))), 6)

    def test_traversal_private_runtime_and_duplicate_paths_are_refused(self):
        for name in ('../escape', '/etc/passwd', 'plugins/Other/file.php', 'storage/data', 'vendor/file',
                     '.git/config', '.env', '.env.production', 'bootstrap/cache/config.php', '.docker/.data/database.sqlite', 'artisan'):
            with self.subTest(name=name):
                info = tarfile.TarInfo(name); info.size = 1
                with self.assertRaises(RuntimeError):
                    deploy.archive_members(self.archive(info))

    def test_archive_links_special_files_and_setuid_modes_are_refused(self):
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.CHRTYPE):
            info = tarfile.TarInfo('malicious'); info.type = kind; info.linkname = '/etc/passwd'
            with self.assertRaises(RuntimeError):
                deploy.archive_members(self.archive(info))
        info = tarfile.TarInfo('setuid'); info.size = 1; info.mode = 0o4755
        with self.assertRaises(RuntimeError):
            deploy.archive_members(self.archive(info))


if __name__ == '__main__':
    unittest.main()
