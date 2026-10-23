#!/usr/bin/env python3
"""Build a deterministic, local independent release from a clean committed HEAD."""
import argparse
import ast
import datetime as dt
import gzip
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import tempfile

EXCLUDED = ('.git', '.env', 'storage', 'vendor', 'plugins', 'public/storage',
            'bootstrap/cache', '.docker/.data')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def git(root, *args):
    return subprocess.check_output(['git', '-C', str(root), *args])


def allowed(name):
    parts = PurePosixPath(name).parts
    return not (any(name == p or name.startswith(p + '/') for p in EXCLUDED)
                or (name.startswith('.env') and name != '.env.example')
                or any(p in ('.runtime', '.audit', '__pycache__', 'node_modules') for p in parts))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--commit', default='HEAD')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    commit = git(root, 'rev-parse', '--verify', args.commit + '^{commit}').decode().strip()
    require(commit == git(root, 'rev-parse', 'HEAD').decode().strip(), 'Check out the intended release HEAD first')
    require(not git(root, 'status', '--porcelain=v1', '--untracked-files=all').strip(), 'Commit all source changes before building')
    timestamp = int(git(root, 'show', '-s', '--format=%ct', commit))
    published = dt.datetime.fromtimestamp(timestamp, dt.timezone.utc)
    version = published.strftime('%Y%m%d') + '-' + commit[:7]
    output = args.output.absolute()
    require(not output.is_relative_to(root), 'Release output must be outside the source checkout')
    for part in (output, *output.parents):
        require(not part.is_symlink(), 'Release output must not contain symbolic links')
    destination = output / 'releases' / version
    require(not destination.exists(), 'Release already exists: ' + str(destination))
    source = git(root, 'archive', '--format=tar', commit)
    files = {}
    with tarfile.open(fileobj=io.BytesIO(source), mode='r:') as tree:
        for member in tree:
            name = member.name.rstrip('/')
            require(not member.issym() and not member.islnk(), 'Source contains a link: ' + name)
            if member.isdir() or not allowed(name):
                continue
            require(member.isfile() and not member.mode & 0o7000, 'Source contains a special entry')
            data = tree.extractfile(member).read()
            require(not re.search(rb'(?m)^-----BEGIN (?:[A-Z ]+ )?PRIVATE KEY-----\r?\n[A-Za-z0-9+/=\r\n]{40,}-----END ', data),
                    'Private key material in source: ' + name)
            files[name] = (data, 0o755 if member.mode & 0o111 else 0o644)
    require({'artisan', 'composer.json', 'composer.lock', 'bundled-plugins/manifest.json',
             'scripts/install-panel.py', 'scripts/deploy-release.py'}.issubset(files), 'Incomplete independent source')
    bundle = json.loads(files['bundled-plugins/manifest.json'][0])
    for plugin in bundle['plugins']:
        prefix = 'bundled-plugins/' + plugin['directory'] + '/'
        actual = {n[len(prefix):]: hashlib.sha256(data).hexdigest()
                  for n, (data, _) in files.items() if n.startswith(prefix)}
        require(actual == plugin['files'], 'Plugin source differs from its locked manifest: ' + plugin['code'])
    constants = {}
    for node in ast.parse(files['scripts/install-panel.py'][0]).body:
        if isinstance(node, ast.Assign) and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name):
            if node.targets[0].id.endswith('_SHA256'):
                constants[node.targets[0].id] = ast.literal_eval(node.value)
    for name, key in [('quick_install.py', 'BOOTSTRAP_SHA256'),
                      ('node-endpoint-tools-1.0.6.zip', 'TOOLS_SHA256'),
                      ('firewall.py', 'FIREWALL_PATCH_SHA256')]:
        require(hashlib.sha256(files['root-assets/' + name][0]).hexdigest() == constants[key],
                'Root asset differs from its executable pin: ' + name)
    build = {'version': version, 'commit': commit, 'bundle_version': bundle['bundle_version']}
    files['build.json'] = ((json.dumps(build, indent=2) + '\n').encode(), 0o644)
    with tempfile.TemporaryDirectory(prefix='xboard-release-') as temporary:
        archive = Path(temporary) / 'xboard.tar.gz'
        with archive.open('wb') as raw, gzip.GzipFile(filename='', fileobj=raw, mode='wb', mtime=timestamp) as compressed:
            with tarfile.open(fileobj=compressed, mode='w', format=tarfile.USTAR_FORMAT) as package:
                for name, (data, mode) in sorted(files.items()):
                    info = tarfile.TarInfo(name)
                    info.size, info.mode, info.mtime = len(data), mode, timestamp
                    info.uid = info.gid = 0
                    info.uname = info.gname = ''
                    package.addfile(info, io.BytesIO(data))
        require(0 < archive.stat().st_size <= 262144000, 'Archive exceeds updater size limit')
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        manifest = {**build, 'sha256': digest, 'size': archive.stat().st_size,
                    'published_at': published.isoformat(),
                    'author': git(root, 'show', '-s', '--format=%an', commit).decode().strip(),
                    'message': git(root, 'show', '-s', '--format=%s', commit).decode().strip()}
        destination.mkdir(parents=True)
        (destination / 'xboard.tar.gz').write_bytes(archive.read_bytes())
        encoded = (json.dumps(manifest, ensure_ascii=False, indent=2) + '\n').encode()
        (destination / 'latest.json').write_bytes(encoded)
        (destination / 'SHA256SUMS').write_text(digest + '  xboard.tar.gz\n', encoding='utf-8')
        (destination / 'plugin-source-lock.json').write_bytes(files['bundled-plugins/manifest.json'][0])
        report = 'docs/installation/bundled-validation.zh-CN.md'
        if report in files:
            (destination / 'VALIDATION.md').write_bytes(files[report][0])
        current = output / 'latest.json'
        if current.exists():
            (output / 'latest.json.previous').write_bytes(current.read_bytes())
        pending = output / 'latest.json.tmp'
        pending.write_bytes(encoded)
        pending.replace(current)
    print(json.dumps({'version': version, 'commit': commit, 'archive': str(destination / 'xboard.tar.gz'),
                      'sha256': digest, 'files': len(files)}, ensure_ascii=False))


if __name__ == '__main__':
    main()
