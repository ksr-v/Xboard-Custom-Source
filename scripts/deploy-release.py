#!/usr/bin/env python3
"""Deploy a fixed local release as Root; preserve .env, runtime data and plugins."""
import argparse
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path, PurePosixPath
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile

PROTECTED = ('.git', '.env', 'storage', 'vendor', 'plugins', 'bootstrap/cache', 'public/storage', '.docker/.data')


def require(value, message):
    if not value:
        raise RuntimeError(message)


def digest(path):
    value = hashlib.sha256()
    with Path(path).open('rb') as handle:
        for block in iter(lambda: handle.read(1048576), b''):
            value.update(block)
    return value.hexdigest()


def protected(name):
    return any(name == prefix or name.startswith(prefix + '/') for prefix in PROTECTED)


def archive_members(path):
    """Validate the complete archive before creating or replacing any app file."""
    result = []
    seen = set()
    total = 0
    with tarfile.open(path, 'r:gz') as archive:
        for member in archive:
            name = member.name
            while name.startswith('./'):
                name = name[2:]
            name = name.rstrip('/')
            if name in ('', '.') and member.isdir():
                continue
            parts = PurePosixPath(name).parts
            require(name and not name.startswith('/') and '\\' not in name and ':' not in name
                    and '..' not in parts and PurePosixPath(name).as_posix() == name,
                    'Unsafe release archive path')
            require(name not in seen and (member.isfile() or member.isdir()), 'Duplicate, linked or special archive entry')
            require(not protected(name) and not any(p in ('.runtime', '.audit', '__pycache__', 'node_modules') for p in parts),
                    'Release contains runtime/private paths')
            require(not (name.startswith('.env') and name != '.env.example'), 'Release contains a private environment file')
            require(not member.mode & 0o7000 and member.size <= 32 * 1024 * 1024, 'Unsupported release entry mode/size')
            seen.add(name)
            total += member.size
            require(total <= 512 * 1024 * 1024 and len(seen) <= 25000, 'Release unpack limit exceeded')
            result.append((name, member.isfile(), member.mode))
    names = {name for name, is_file, _ in result if is_file}
    require({'artisan', 'composer.json', 'composer.lock', 'bundled-plugins/manifest.json',
             'scripts/install-panel.py'}.issubset(names), 'Incomplete independent Xboard release')
    return result


def destination(app, relative):
    target = app / relative
    for part in (target, *target.parents):
        require(not part.is_symlink(), 'Refusing a linked application destination')
        if part == app:
            break
    require(not target.exists() or (target.is_file() and target.stat().st_nlink == 1), 'Application target is not a single regular file')
    return target


def extract(path, stage):
    with tarfile.open(path, 'r:gz') as archive:
        for member in archive:
            name = member.name
            while name.startswith('./'):
                name = name[2:]
            name = name.rstrip('/')
            if name in ('', '.'):
                continue
            target = stage / name
            if member.isdir():
                target.mkdir(mode=0o700, parents=True, exist_ok=True)
            else:
                target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                with archive.extractfile(member) as source, target.open('xb') as output:
                    shutil.copyfileobj(source, output)
                target.chmod(0o700 if member.mode & 0o111 else 0o600)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--archive', type=Path, required=True)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--app', type=Path, required=True)
    parser.add_argument('--app-user', required=True)
    parser.add_argument('--php', required=True)
    parser.add_argument('--composer', required=True)
    parser.add_argument('--database-backup', type=Path, required=True)
    parser.add_argument('--skip-endpoint', action='store_true')
    parser.add_argument('--host')
    parser.add_argument('--port', type=int)
    parser.add_argument('--nginx')
    args = parser.parse_args()
    require(sys.platform == 'linux' and os.geteuid() == 0, 'Run as Root on the panel host')
    os.umask(0o077)
    require(re.fullmatch('[a-f0-9]{64}', args.sha256), 'Use the independently verified release SHA-256')
    archive = args.archive.resolve(strict=True)
    require(archive.is_file() and archive.stat().st_size <= 262144000 and digest(archive) == args.sha256,
            'Release SHA-256/size verification failed')
    members = archive_members(archive)
    app = args.app.absolute()
    require(not app.is_symlink() and app.resolve(strict=True) == app and len(app.parts) >= 3, 'Use an absolute dedicated application directory')
    backup_input = args.database_backup
    require(backup_input.is_file() and not backup_input.is_symlink() and backup_input.stat().st_nlink == 1
            and backup_input.stat().st_size > 0 and not backup_input.stat().st_mode & 0o077,
            'Provide an existing protected 0600 database backup; its recoverability must be checked separately')
    require((app / '.env').is_file() and not (app / '.env').is_symlink() and (app / '.env').stat().st_nlink == 1, 'Unsafe installed .env')
    original_env = (app / '.env').read_bytes()
    changed, created = [], []
    attempted_application_update = False
    recovery = None
    with tempfile.TemporaryDirectory(prefix='xboard-verified-release-', dir='/root') as work:
        stage = Path(work) / 'source'
        stage.mkdir(mode=0o700)
        extract(archive, stage)
        for name in ('composer.json', 'composer.lock'):
            require((stage / name).read_bytes() == (app / name).read_bytes(), 'Dependency files differ; prepare a separately tested dependency upgrade')
        spec = importlib.util.spec_from_file_location('verified_xboard_install', stage / 'scripts/install-panel.py')
        coordinator = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(coordinator)
        coordinator.plain_path(app)
        user = pwd.getpwnam(args.app_user)
        require(user.pw_uid != 0 and user.pw_gid != 0, 'Use the non-Root PHP-FPM account')
        php = coordinator.executable(args.php, [])
        flags = coordinator.env_values(app / '.env')
        require(flags.get('INSTALLED', '').lower() in ('true', '1') and bool(flags.get('APP_KEY')), 'Target is not an installed panel')
        files = [(name, mode) for name, is_file, mode in members if is_file]
        for name, _ in files:
            destination(app, name)
        parent = coordinator.root_path('/var/backups/xboard-upgrades')
        parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        parent.chmod(0o700)
        recovery = Path(tempfile.mkdtemp(prefix=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S-'), dir=parent))
        shutil.copyfile(backup_input, recovery / 'database-backup')
        (recovery / 'database-backup').chmod(0o600)
        manifest = {'schema_version': 1, 'app': str(app), 'archive_sha256': args.sha256,
                    'database_backup_sha256': digest(recovery / 'database-backup'), 'status': 'preparing', 'changed': []}
        def record():
            (recovery / 'recovery.json').write_text(json.dumps(manifest, indent=2) + '\n')
            (recovery / 'recovery.json').chmod(0o600)
        record()
        coordinator.as_user(user, app, [php, 'artisan', 'down', '--retry=60'])
        try:
            for name, mode in files:
                target = destination(app, name)
                # Source members were validated as a whole. Prepare their exact
                # new parent directories without recursing into runtime data.
                parents = []
                parent = target.parent
                while not parent.exists():
                    parents.append(parent)
                    parent = parent.parent
                for parent in reversed(parents):
                    parent.mkdir(mode=0o755)
                    os.chown(parent, user.pw_uid, user.pw_gid)
                    parent.chmod(0o755)
                if target.exists():
                    info = target.stat()
                    backup = recovery / 'code' / name
                    backup.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                    shutil.copyfile(target, backup)
                    backup.chmod(0o600)
                    changed.append((target, backup, info))
                    manifest['changed'].append({'path': name, 'sha256': digest(backup), 'mode': stat.S_IMODE(info.st_mode),
                                                 'uid': info.st_uid, 'gid': info.st_gid})
                else:
                    created.append(target)
                    manifest['changed'].append({'path': name, 'new': True})
                shutil.copyfile(stage / name, target)
                os.chown(target, user.pw_uid, user.pw_gid)
                target.chmod(0o755 if mode & 0o111 else 0o644)
            manifest['status'] = 'source_applied'
            record()
            command = [sys.executable, '-I', str(stage / 'scripts/install-panel.py'), 'upgrade', '--deployed-code',
                       '--app-dir', str(app), '--app-user', user.pw_name, '--php', php, '--composer', args.composer]
            for option in ('host', 'port', 'nginx'):
                if getattr(args, option) is not None:
                    command += ['--' + option, str(getattr(args, option))]
            if args.skip_endpoint:
                command.append('--skip-endpoint')
            attempted_application_update = True
            result = subprocess.run(command, timeout=1800)
            require(result.returncode == 0, 'Application/Root update did not complete; maintenance mode and protected recovery evidence are retained')
            require((app / '.env').read_bytes() == original_env, '.env changed unexpectedly; recovery evidence retained')
            coordinator.as_user(user, app, [php, 'artisan', 'up'])
            manifest['status'] = 'complete'
            record()
            print('Upgrade complete. Protected source/database recovery: ' + str(recovery))
        except BaseException:
            if not attempted_application_update:
                for target in reversed(created):
                    if target.is_file() and not target.is_symlink():
                        target.unlink()
                for target, backup, info in reversed(changed):
                    shutil.copyfile(backup, target)
                    os.chown(target, info.st_uid, info.st_gid)
                    target.chmod(stat.S_IMODE(info.st_mode))
                    os.utime(target, ns=(info.st_atime_ns, info.st_mtime_ns))
                coordinator.as_user(user, app, [php, 'artisan', 'up'])
                manifest['status'] = 'source_rolled_back_before_application_update'
            else:
                manifest['status'] = 'application_update_failed_review_database_and_source_backup_before_recovery'
            record()
            print('Protected recovery evidence: ' + str(recovery), file=sys.stderr)
            raise


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError, subprocess.SubprocessError, EOFError, KeyboardInterrupt) as error:
        print('Stopped: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
