"""Empty-UFW regression and independently pinned Root-cache import guards."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]
if os.name == 'nt':
    sys.modules.setdefault('pwd', SimpleNamespace())
spec = importlib.util.spec_from_file_location('installer_firewall_review', ROOT / 'scripts/install-panel.py')
install = importlib.util.module_from_spec(spec)
spec.loader.exec_module(install)
asset_spec = importlib.util.spec_from_file_location('empty_ufw_asset_review', ROOT / 'root-assets/firewall.py')
firewall = importlib.util.module_from_spec(asset_spec)
with patch.dict(sys.modules, {'manager': SimpleNamespace(linux_root=lambda: None, trusted_file=lambda p, **kw: p),
                              'pki': SimpleNamespace(SafetyError=RuntimeError)}):
    asset_spec.loader.exec_module(firewall)

EMPTY = "Added user rules (see 'ufw status' for running firewall):\n(None)\n"


class Ufw:
    def __init__(self, active=True, saved=None, rows=None):
        self.active = active
        self.saved = list(saved or [])
        self.rows = list(rows or [])
        self.commands = []
        self.override_before = None
        self.fail_add = False

    def run(self, argv, **kwargs):
        self.commands.append(argv)
        args = argv[1:]
        if args == ['status', 'numbered']:
            output = 'Status: inactive\n' if not self.active else 'Status: active\n' + ''.join(
                '[%2d] %s\n' % (index + 1, row) for index, row in enumerate(self.rows))
        elif args == ['show', 'added']:
            output = ("Added user rules (see 'ufw status' for running firewall):\n" + '\n'.join(self.saved) + '\n') if self.saved else EMPTY
            if self.override_before is not None:
                output = self.override_before
        else:
            insert = args[:2] == ['insert', '1']
            rule = args[2:] if insert else args
            if rule != ['allow', 'in', '19843/tcp', 'comment', 'NodeEndpoint']:
                raise AssertionError('Unexpected mutation: ' + repr(argv))
            # Reproduce real UFW's empty-table failure instead of assuming that
            # insert 1 always works. No fallback may append over unknown policy.
            if (insert and not self.saved) or self.fail_add:
                return subprocess.CompletedProcess(argv, 1, '', "ERROR: Invalid position '1'\n")
            added = "ufw allow in 19843/tcp comment 'NodeEndpoint'"
            if added not in self.saved:
                self.saved.insert(0, added)
                self.rows.insert(0, '19843/tcp ALLOW IN Anywhere # NodeEndpoint')
            output = 'Rule added\n'
        return subprocess.CompletedProcess(argv, 0, output, '')

    def ensure(self):
        with patch.object(firewall, 'linux_root'), \
                patch.object(firewall.shutil, 'which', side_effect=lambda name, **kw: '/usr/sbin/ufw' if name == 'ufw' else None), \
                patch.object(firewall, 'trusted_file', side_effect=lambda p, **kw: p), \
                patch.object(firewall.subprocess, 'run', side_effect=self.run):
            result = firewall.ensure_port(19843)
        json.dumps(result)
        return result


class EmptyUfwRegression(unittest.TestCase):
    def test_exact_empty_active_table_adds_first_narrow_rule_then_reuses_it(self):
        system = Ufw()
        first = system.ensure()
        self.assertEqual(first['status'], 'ensured')
        self.assertTrue(first['persistent'])
        self.assertIn(['ufw', 'allow'], [[Path(cmd[0]).name, cmd[1]] for cmd in system.commands])
        self.assertFalse(any('insert' in cmd for cmd in system.commands))
        saved = list(system.saved)
        count = len(system.commands)
        self.assertFalse(system.ensure()['changed'])
        self.assertEqual(system.saved, saved)
        self.assertTrue(any(cmd[1:3] == ['insert', '1'] for cmd in system.commands[count:]))

    def test_exact_empty_inactive_table_is_saved_without_enabling(self):
        system = Ufw(active=False)
        result = system.ensure()
        self.assertEqual(result['status'], 'saved_inactive')
        self.assertFalse(result['active'])
        self.assertFalse(system.active)
        self.assertIn('没有启用', result['notice'])
        self.assertFalse(any(set(cmd) & {'enable', 'reload', 'reset', 'default'} for cmd in system.commands))

    def test_nonempty_rules_still_insert_before_deny_and_preserve_ssh(self):
        system = Ufw(saved=['ufw allow 22/tcp', 'ufw deny 19843/tcp'],
                     rows=['22/tcp ALLOW IN Anywhere', '19843/tcp DENY IN Anywhere'])
        before = list(system.rows)
        self.assertEqual(system.ensure()['status'], 'ensured')
        self.assertEqual(system.rows[1:], before)
        self.assertTrue(any(cmd[1:3] == ['insert', '1'] for cmd in system.commands))

    def test_duplicate_allow_after_deny_still_requires_manual_review(self):
        system = Ufw(saved=['ufw deny 19843/tcp', "ufw allow in 19843/tcp comment 'NodeEndpoint'"],
                     rows=['19843/tcp DENY IN Anywhere', '19843/tcp ALLOW IN Anywhere'])
        result = system.ensure()
        self.assertEqual(result['status'], 'manual_required')
        self.assertFalse(result['changed'])

    def test_unknown_or_nearly_empty_output_never_falls_back_to_append(self):
        for output in ('', '(None)\n', 'Added user rules:\n(None)\n', EMPTY + 'unknown policy\n'):
            with self.subTest(output=output):
                system = Ufw()
                system.override_before = output
                result = system.ensure()
                self.assertEqual(result['status'], 'error')
                self.assertFalse(any(cmd[1:2] == ['allow'] for cmd in system.commands))

    def test_failed_empty_add_does_not_claim_success_or_retry_broad_commands(self):
        system = Ufw()
        system.fail_add = True
        result = system.ensure()
        self.assertEqual(result['status'], 'error')
        self.assertEqual(result['error']['operation'], 'ufw:add_empty')
        self.assertTrue(result['partial_application_possible'])
        self.assertEqual(system.saved, [])


class PinnedPatchGuards(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='xboard-firewall-pin-')
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.app = self.base / 'app'
        (self.app / 'root-assets').mkdir(parents=True)
        self.source = self.app / 'root-assets/firewall.py'
        self.source.write_bytes((ROOT / 'root-assets/firewall.py').read_bytes())
        self.tools = self.base / 'pinned-tools'
        self.panel = self.tools / 'tools/panel'
        self.panel.mkdir(parents=True)
        (self.panel / 'manager.py').write_text('def linux_root(): pass\ndef trusted_file(path, **kw): return path\n')
        (self.panel / 'pki.py').write_text('class SafetyError(RuntimeError): pass\n')
        self.directory = self.base / 'root-cache'
        self.directory.mkdir()
        self.cache = self.directory / 'firewall-patches' / install.FIREWALL_PATCH_SHA256

    def context(self):
        from contextlib import ExitStack
        stack = ExitStack()
        self.addCleanup(stack.close)
        # Real Root ownership/ancestor checks are tested separately below. Only
        # the portable copy/import tests substitute the trusted temp directory.
        stack.enter_context(patch.object(install, 'root_path', side_effect=lambda path: Path(path)))
        stack.enter_context(patch.object(install.os, 'O_NOFOLLOW', getattr(os, 'O_NOFOLLOW', 0), create=True))
        stack.enter_context(patch.object(sys, 'path', [str(self.panel), *sys.path]))
        stack.enter_context(patch.object(sys, 'dont_write_bytecode', True))
        stack.enter_context(patch.dict(sys.modules))
        for name in ('manager', 'pki', 'nginx', 'panel_ca', 'firewall'):
            sys.modules.pop(name, None)
        return stack

    def test_asset_and_old_published_pins_remain_independent(self):
        self.assertEqual(hashlib.sha256(self.source.read_bytes()).hexdigest(), install.FIREWALL_PATCH_SHA256)
        for file, expected in (('quick_install.py', '384b9087c6a938f34b23a461e6388a8a1f83ebb3c042d7ae8b868ed941caf384'),
                               ('node-endpoint-tools-1.0.3.zip', 'ea46e0ea8fe1275204c9a7f1f3dd21157a3bdf34070917e1aad8e49cda537a93')):
            self.assertEqual(hashlib.sha256((ROOT / 'root-assets' / file).read_bytes()).hexdigest(), expected)

    def test_import_uses_only_pinned_root_copy_and_reuses_unmodified_cache(self):
        self.context()
        module = install.install_firewall_patch(self.app, self.tools, self.directory)
        target = self.cache / 'firewall.py'
        self.assertEqual(Path(module.__file__), target)
        self.assertNotEqual(Path(module.__file__), self.source)
        self.assertEqual(sys.path[0], str(self.cache))
        self.assertEqual(target.read_bytes(), self.source.read_bytes())
        identity = target.stat().st_ino
        install.install_firewall_patch(self.app, self.tools, self.directory)
        self.assertEqual(target.stat().st_ino, identity)

    def test_wrong_application_asset_hash_never_imports(self):
        self.context()
        self.source.write_text("raise RuntimeError('untrusted application source executed')\n")
        with patch.object(install.importlib.util, 'spec_from_file_location') as loader:
            with self.assertRaisesRegex(RuntimeError, '摘要'):
                install.install_firewall_patch(self.app, self.tools, self.directory)
            loader.assert_not_called()
        self.assertFalse(self.cache.exists())

    def test_modified_cache_is_refused_and_not_overwritten(self):
        self.context()
        self.cache.mkdir(parents=True)
        target = self.cache / 'firewall.py'
        target.write_text("raise RuntimeError('wrong cached code')\n")
        before = target.read_bytes()
        with self.assertRaisesRegex(RuntimeError, '缓存摘要'):
            install.install_firewall_patch(self.app, self.tools, self.directory)
        self.assertEqual(target.read_bytes(), before)

    def test_cache_extra_module_cannot_shadow_verified_manager(self):
        self.context()
        self.cache.mkdir(parents=True)
        (self.cache / 'manager.py').write_text("raise RuntimeError('shadowed manager')\n")
        with self.assertRaisesRegex(RuntimeError, '未知文件'):
            install.install_firewall_patch(self.app, self.tools, self.directory)
        self.assertNotIn('manager', sys.modules)

    def test_application_directory_cannot_be_used_as_tools_or_patch_cache(self):
        self.context()
        for tools, directory in ((self.app, self.directory), (self.tools, self.app)):
            with self.subTest(tools=tools, directory=directory), self.assertRaisesRegex(RuntimeError, '应用目录之外'):
                install.install_firewall_patch(self.app, tools, directory)

    def test_preloaded_application_module_is_rejected_instead_of_reused(self):
        self.context()
        for name in ('manager', 'firewall'):
            with self.subTest(name=name):
                sys.modules[name] = SimpleNamespace(__file__=str(self.app / (name + '.py')))
                with self.assertRaisesRegex(RuntimeError, '非校验缓存模块'):
                    install.install_firewall_patch(self.app, self.tools, self.directory)
                sys.modules.pop(name)

    def test_hardlinked_cache_file_is_refused_even_when_bytes_match(self):
        self.context()
        self.cache.mkdir(parents=True)
        target = self.cache / 'firewall.py'
        outside = self.base / 'foreign-file'
        outside.write_bytes(self.source.read_bytes())
        target.hardlink_to(outside)
        with self.assertRaisesRegex(RuntimeError, '缓存文件异常'):
            install.install_firewall_patch(self.app, self.tools, self.directory)

    def test_root_cache_path_rejects_app_owned_file_or_writable_ancestor(self):
        for file_uid, ancestor_mode in ((1001, 0o40700), (0, 0o40777)):
            with self.subTest(file_uid=file_uid, ancestor_mode=ancestor_mode):
                ancestor = Mock()
                ancestor.exists.return_value = True
                ancestor.stat.return_value = SimpleNamespace(st_uid=0, st_mode=ancestor_mode)
                selected = Mock()
                selected.parents = [ancestor]
                selected.exists.return_value = True
                selected.stat.return_value = SimpleNamespace(st_uid=file_uid, st_mode=0o100600)
                with patch.object(install, 'plain_path', return_value=selected):
                    with self.assertRaisesRegex(RuntimeError, 'Root'):
                        install.root_path('/private/cache/firewall.py')


if __name__ == '__main__':
    unittest.main(verbosity=2)
