#!/usr/bin/env python3
"""Build the locked plugin source bundle using local Git objects only."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SOURCES = (
    ('node_endpoint', 'NodeEndpoint', '1.0.3', 'ksr-v/Xboard-Independent-NodeEndpoint',
     'dd45b2b8d75ec7a2908f8be2093155ae7504a9f4', 'plugin/NodeEndpoint/'),
    ('smart_expiry', 'SmartExpiry', '1.2.0', 'ksr-v/xb-SmartExpiry',
     '6f22f456512d1a0cdcad9c46e75e06e5793e6bb6', ''),
    ('qrcodeextend', 'Qrcodeextend', '1.2.0', 'ksr-v/xb-qrcode-extend',
     '3b30042a0e16047a67dddb73ffaf85e4a2a54918', 'Qrcodeextend/'),
    ('flowscope', 'Flowscope', '2.4.0', 'ksr-v/xb-flowscope',
     '9ae54a38e695d3b6acc429bbcdfbafba35ccd4d6', 'FlowScope/'),
)
COLLECTOR = ('bootstrap.py', 'flowscope_collector.py', 'install.sh',
             'flowscope-collector.service', 'manage.sh')


def require(condition, reason):
    if not condition:
        raise ValueError(reason)


def git(repository, *args):
    return subprocess.run(['git', '-c', 'safe.directory=' + str(repository), '-C', str(repository), *args],
                          capture_output=True, check=True, timeout=30).stdout


def source_files(repository, commit, prefix, smart=False):
    listing = git(repository, 'ls-tree', '-rz', commit, '--', prefix or '.')
    result = {}
    for entry in listing.split(b'\0'):
        if not entry:
            continue
        identity, encoded = entry.split(b'\t', 1)
        mode, kind, _ = identity.split()
        name = encoded.decode('utf-8')
        if prefix and not name.startswith(prefix):
            continue
        relative = name[len(prefix):]
        if smart and relative not in ('Plugin.php', 'config.json') and not relative.startswith('Services/'):
            continue
        path = PurePosixPath(relative)
        require(mode in (b'100644', b'100755') and kind == b'blob', 'Unsupported source entry: ' + name)
        require(not path.is_absolute() and '..' not in path.parts and '\\' not in relative, 'Unsafe source path')
        require(not any(part in ('.git', 'tests', 'node_modules', '__pycache__', 'work') for part in path.parts),
                'Development data in plugin source: ' + name)
        data = git(repository, 'show', commit + ':' + name)
        require(len(data) <= 8 * 1024 * 1024, 'Source file exceeds limit')
        result[relative] = data
    return result


def hashes(files):
    return {name: hashlib.sha256(data).hexdigest() for name, data in sorted(files.items())}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-map', required=True, type=Path,
                        help='Local JSON mapping plugin code to its Git repository directory')
    args = parser.parse_args()
    mapping = json.loads(args.source_map.read_text(encoding='utf-8'))
    target = ROOT / 'bundled-plugins'
    require(not target.exists() and not target.is_symlink(), 'Bundle already exists; build in a clean source checkout')
    manifest = {'schema_version': 1, 'bundle_version': '2026.10.18.2', 'plugins': []}
    with tempfile.TemporaryDirectory(prefix='.plugin-bundle-build-', dir=ROOT) as work:
        stage = Path(work) / 'bundled-plugins'
        stage.mkdir()
        for code, directory, version, repository_name, commit, prefix in SOURCES:
            repository = Path(mapping[code]).resolve(strict=True)
            files = source_files(repository, commit, prefix, smart=code == 'smart_expiry')
            if code == 'flowscope':
                for name in COLLECTOR:
                    files['collector/' + name] = git(repository, 'show', commit + ':collector/' + name)
            config = json.loads(files['config.json'])
            require(config['code'] == code and config['version'] == version, 'Pinned plugin identity differs')
            previous = []
            if code == 'node_endpoint':
                for tag in ('v1.0.0', 'v1.0.1', 'v1.0.2'):
                    old_commit = git(repository, 'rev-parse', tag + '^{commit}').decode().strip()
                    old = source_files(repository, old_commit, prefix)
                    old_version = json.loads(old['config.json'])['version']
                    previous.append({'version': old_version, 'source_commit': old_commit, 'files': hashes(old)})
            for name, data in sorted(files.items()):
                destination = stage / directory / name
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(data)
            manifest['plugins'].append({'code': code, 'directory': directory, 'version': version,
                                        'source_repository': 'https://github.com/' + repository_name,
                                        'source_commit': commit, 'files': hashes(files),
                                        'accepted_previous': previous})
        (stage / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n',
                                             encoding='utf-8', newline='\n')
        stage.rename(target)
    print(json.dumps({'bundle_version': manifest['bundle_version'],
                      'plugins': [{'code': p['code'], 'version': p['version'], 'files': len(p['files'])}
                                  for p in manifest['plugins']], 'network_access': False}))


if __name__ == '__main__':
    main()
