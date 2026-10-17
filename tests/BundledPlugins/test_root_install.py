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
