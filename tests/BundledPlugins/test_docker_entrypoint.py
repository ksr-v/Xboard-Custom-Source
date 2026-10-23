"""Run the real entrypoint with isolated paths and explicit service/UID doubles.

This checks shell control flow and argv preservation; actual container users,
mount ownership and native plugin installation still require Docker smoke tests.
"""
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
BASH = shutil.which('bash') or ('C:/Program Files/Git/bin/bash.exe' if os.name == 'nt' else None)


@unittest.skipUnless(BASH and Path(BASH).is_file(), 'A POSIX shell is required')
class DockerEntrypointTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='entrypoint-test-', dir=ROOT.parent)
        self.base = Path(self.temporary.name)
        self.app = self.base / 'app'
        self.app.mkdir()
        self.trace = self.base / 'trace.jsonl'
        self.bin = self.base / 'bin'
        self.bin.mkdir()
        self.recorder = self.base / 'record.py'
        self.recorder.write_text('''import json, os, pathlib, sys
kind, args = sys.argv[1], sys.argv[2:]
app = pathlib.Path(os.environ['FIXTURE_APP'])
record = {'kind': kind, 'args': args, 'uid': os.environ.get('FIXTURE_UID'),
          'drivers': {key: os.environ.get(key) for key in ('CACHE_DRIVER', 'QUEUE_CONNECTION', 'SESSION_DRIVER')},
          'paths_ready': all((app / name).is_dir() for name in ('bootstrap/cache', 'plugins', 'storage/app/private', 'storage/app/node_endpoint', 'storage/framework/views'))}
with open(os.environ['FIXTURE_TRACE'], 'a', encoding='utf-8') as target:
    target.write(json.dumps(record) + '\\n')
if kind == 'php':
    if record['uid'] != '1000' or not record['paths_ready']:
        sys.exit(93)
    if 'xboard:update' in args:
        sys.exit(int(os.environ.get('FIXTURE_UPDATE_EXIT', '0')))
''', encoding='utf-8', newline='\n')
        command = shlex.quote(Path(sys.executable).as_posix()) + ' ' + shlex.quote(self.recorder.as_posix())
        stubs = {
            'id': 'printf "%s\\n" "${FIXTURE_UID:-0}"\n',
            'awk': 'printf "512\\n"\n',
            'chown': command + ' chown "$@"\n',
            'su-exec': command + ' su-exec "$@"\n[ "$1" = "www:www" ] || exit 92\nshift\nexport FIXTURE_UID=1000\nexec "$@"\n',
            'php': command + ' php "$@"\n',
            'fixture-supervisor': command + ' supervisor "$@"\n',
            'redis-cli': '[ "${FIXTURE_REDIS:-false}" = "true" ] || exit 1\nprintf "PONG\\n"\n',
        }
        for name, body in stubs.items():
            path = self.bin / name
            path.write_text('#!/bin/sh\n' + body, encoding='utf-8', newline='\n')
            path.chmod(0o700)
        text = (ROOT / '.docker/entrypoint.sh').read_text(encoding='utf-8')
        text = text.replace('/www', self.app.as_posix())
        text = re.sub(r'(?<![\w/])/data(?=[\s/";]|$)', (self.base / 'redis').as_posix(), text)
        shell_bin = self.bin.as_posix()
        if os.name == 'nt':
            shell_bin = '/' + shell_bin[0].lower() + shell_bin[2:]
        text = '#!/bin/sh\nPATH=' + shlex.quote(shell_bin) + ':"$PATH"\n' + text
        self.entrypoint = self.base / 'entrypoint.sh'
        self.entrypoint.write_text(text, encoding='utf-8', newline='\n')

    def tearDown(self):
        self.temporary.cleanup()

    def run_entrypoint(self, arguments, installed=False, **environment):
        if not (self.app / '.env').is_dir():
            (self.app / '.env').write_text('INSTALLED=true\nREDIS_HOST=127.0.0.1\n' if installed else '', encoding='utf-8')
        env = {**os.environ, 'PATH': self.bin.as_posix() + os.pathsep + os.environ.get('PATH', ''),
               'FIXTURE_APP': self.app.as_posix(), 'FIXTURE_TRACE': self.trace.as_posix(),
               'FIXTURE_UID': '0', 'MEM_FALLBACK_MIB': '512', **environment}
        result = subprocess.run([BASH, self.entrypoint.as_posix(), *arguments], cwd=self.app,
                                env=env, capture_output=True, text=True, timeout=30)
        records = [json.loads(line) for line in self.trace.read_text().splitlines()] if self.trace.exists() else []
        return result, records

    def test_fresh_install_runs_as_app_and_keeps_each_argument_literal(self):
        literal = 'name with spaces;$(touch NEVER_EXECUTE);"quote"'
        result, records = self.run_entrypoint(['php', 'artisan', 'xboard:install', '--db-name=' + literal])
        self.assertEqual(result.returncode, 0, result.stderr)
        php = [record for record in records if record['kind'] == 'php']
        self.assertEqual(len(php), 1)
        self.assertEqual(php[0]['uid'], '1000')
        self.assertEqual(php[0]['args'], ['artisan', 'xboard:install', '--db-name=' + literal])
        self.assertTrue(php[0]['paths_ready'])
        self.assertFalse((self.app / 'NEVER_EXECUTE').exists())

    def test_startup_prepares_permissions_before_nonroot_update_and_keeps_supervisor_root(self):
        result, records = self.run_entrypoint(['fixture-supervisor'], installed=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        php = next(record for record in records if record['kind'] == 'php')
        self.assertEqual(php['uid'], '1000')
        self.assertEqual(php['drivers'], {'CACHE_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync', 'SESSION_DRIVER': 'array'})
        self.assertLess(next(i for i, row in enumerate(records) if row['kind'] == 'chown'), records.index(php))
        self.assertEqual(records[-1]['kind'], 'supervisor')
        self.assertEqual(records[-1]['uid'], '0')

    def test_reachable_redis_retains_real_drivers(self):
        result, records = self.run_entrypoint(['fixture-supervisor'], installed=True, FIXTURE_REDIS='true',
                                              CACHE_DRIVER='redis', QUEUE_CONNECTION='redis', SESSION_DRIVER='file')
        self.assertEqual(result.returncode, 0, result.stderr)
        php = next(record for record in records if record['kind'] == 'php')
        self.assertEqual(php['uid'], '1000')
        self.assertEqual(php['drivers'], {'CACHE_DRIVER': 'redis', 'QUEUE_CONNECTION': 'redis', 'SESSION_DRIVER': 'file'})

    def test_failed_update_stops_before_starting_services(self):
        result, records = self.run_entrypoint(['fixture-supervisor'], installed=True, FIXTURE_UPDATE_EXIT='7')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('services were not started', result.stderr)
        self.assertFalse(any(record['kind'] == 'supervisor' for record in records))

    def test_nonroot_one_shot_does_not_require_root_or_another_uid_switch(self):
        result, records = self.run_entrypoint(['php', 'artisan', 'xboard:install'], FIXTURE_UID='1000')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(any(record['kind'] in ('chown', 'su-exec') for record in records))
        self.assertEqual(records[-1]['kind'], 'php')
        self.assertEqual(records[-1]['uid'], '1000')

    def test_missing_host_env_bind_reported_before_php_runs(self):
        (self.app / '.env').mkdir()
        result, records = self.run_entrypoint(['php', 'artisan', 'xboard:install'])
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Create an empty .env file', result.stderr)
        self.assertEqual(records, [])


if __name__ == '__main__':
    unittest.main(verbosity=2)
