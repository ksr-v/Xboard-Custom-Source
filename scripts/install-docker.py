#!/usr/bin/env python3
"""Root coordinator for one owned, locally built Docker/aaPanel installation.

Application PHP runs as container UID 1000. The installer never edits an
existing Nginx site, runs a discovered Compose file, or forwards credentials in
command arguments or process environments. It does not install system tools.
"""
import argparse
import getpass
import hashlib
import http.client
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
import sys
import time
sys.dont_write_bytecode = True

SYSTEM_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
PRIVATE_ROOT = Path('/var/lib/xboard-docker-installer')
LOCAL_DAEMON = 'unix:///var/run/docker.sock'
OWNER_LABEL = 'io.xboard.docker-installer'
COMPOSE_NAME = 'compose.node-endpoint.yaml'
APP_UID = APP_GID = 1000
RUNTIME_DIRS = ('storage/app', 'storage/logs', 'storage/theme', '.docker/.data', 'plugins')
SOURCE_FILES = ('Dockerfile', '.docker/entrypoint.sh', '.dockerignore', 'composer.lock', 'bundled-plugins/manifest.json')
BUILD_PATHS = ('Dockerfile', '.dockerignore', '.docker', 'composer.json', 'composer.lock', '.env.example',
               'artisan', 'app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'public',
               'plugins-core', 'theme', 'bundled-plugins')
BUILD_EXCLUDED = ('.docker/.data', 'bootstrap/cache', 'public/plugins', 'public/storage')
TOOL = 'Xboard Docker Root installer'


def require(value, reason):
    if not value:
        raise RuntimeError(reason)


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def plain_path(value):
    path = Path(value).absolute()
    for part in (path, *path.parents):
        require(not part.is_symlink(), '路径不能包含符号链接：' + str(path))
    return path


def root_path(value):
    path = plain_path(value)
    for part in (path, *path.parents):
        if part.exists():
            info = part.stat()
            require(info.st_uid == 0 and not info.st_mode & 0o022, 'Root 私有路径属主或权限异常')
    return path


def write_private(path, data):
    path = Path(path)
    require(not path.is_symlink(), '拒绝覆盖符号链接')
    temporary = path.with_name(path.name + '.' + os.urandom(8).hex() + '.new')
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    with os.fdopen(descriptor, 'wb') as handle:
        handle.write(data)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary, path)


def trusted_source(path):
    """Snapshot an immutable Root file before importing it or approving a build."""
    descriptor = os.open(plain_path(path), os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0))
    with os.fdopen(descriptor, 'rb') as handle:
        info = os.fstat(handle.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022
                and info.st_nlink == 1, '源码必须由 Root 重新取得且不可由应用用户写入：' + str(path))
        return handle.read()


def build_source(app):
    """Hash runtime image inputs, excluding mutable mounts and Root-tool-only updates."""
    hashes = {}
    for name in BUILD_PATHS:
        origin = app / name
        require(origin.exists() and not origin.is_symlink(), '缺少可信构建源码：' + name)
        if origin.is_dir():
            info = origin.stat()
            require(info.st_uid == 0 and not info.st_mode & 0o022, '构建源码目录必须由 Root 控制：' + name)
        for path in ([origin] if origin.is_file() else sorted(origin.rglob('*'))):
            relative = path.relative_to(app).as_posix()
            if any(relative == item or relative.startswith(item + '/') for item in BUILD_EXCLUDED):
                continue
            require(not path.is_symlink(), '构建源码不能含符号链接：' + relative)
            if path.is_dir():
                info = path.stat()
                require(info.st_uid == 0 and not info.st_mode & 0o022, '构建源码目录必须由 Root 控制：' + relative)
            else:
                hashes[relative] = hashlib.sha256(trusted_source(path)).hexdigest()
    return hashlib.sha256(json.dumps(hashes, sort_keys=True).encode()).hexdigest()


def env_flags(path):
    """Read only installation guards; never return database credentials."""
    result = {}
    if path.exists():
        require(path.is_file() and not path.is_symlink() and path.stat().st_nlink == 1
                and path.stat().st_size <= 1048576, '.env 文件形态异常')
        for line in path.read_text(encoding='utf-8').splitlines():
            match = re.match(r'^\s*(APP_KEY|INSTALLED)\s*=\s*(.*?)\s*$', line)
            if match:
                require(match[1] not in result, '.env 安装标记重复，请先核对')
                result[match[1]] = match[2].strip('\"\'')
    return result


def installed(flags):
    marked = flags.get('INSTALLED', '').lower() in ('1', 'true', 'yes', 'on')
    require(not marked or bool(flags.get('APP_KEY')), '已有安装缺少 APP_KEY，拒绝初始化或生成新密钥')
    require(not flags.get('APP_KEY') or marked,
            '已有 APP_KEY 但安装未完成；已保留数据。请先核对 Root 日志并恢复，禁止重新初始化')
    return marked


def application(value):
    app = plain_path(value).resolve(strict=True)
    require(app.is_dir() and len(app.parts) >= 3 and str(app) not in ('/var/www', '/www/wwwroot', '/opt'), '需要专用独立源码目录')
    require(not any(x in str(app) for x in ('\n', '\r', '\x00')), '应用路径包含控制字符')
    for name in ('artisan', 'composer.json', *SOURCE_FILES, 'scripts/install-panel.py'):
        path = plain_path(app / name)
        require(path.is_file() and path.stat().st_nlink == 1, '缺少独立发行源码：' + name)
        trusted_source(path)
    root_path(app.parent)
    info = app.stat()
    if os.name != 'nt' and info.st_uid != 0:
        import pwd
        try:
            owner_is_www = pwd.getpwuid(info.st_uid).pw_name == 'www'
        except KeyError:
            owner_is_www = False
        require(owner_is_www, '专用站点目录必须由 Root 或宝塔 www 拥有')
    require(not info.st_mode & 0o022, '专用站点目录不能允许组或其他用户写入')
    return app


def native_module(app, private):
    # aaPanel may own only the empty site directory; snapshot before importing,
    # so a rename of that parent cannot replace an executable after validation.
    target = private / 'verified-native-loader.py'
    write_private(target, trusted_source(app / 'scripts/install-panel.py'))
    spec = importlib.util.spec_from_file_location('xboard_docker_native_root', target)
    require(spec is not None and spec.loader is not None, '无法加载独立 Root 资产校验器')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def choose_host(quick, explicit=None, current=None):
    if explicit or current:
        return quick.reject_example_host(explicit or current)
    candidate = None
    try:
        candidate = quick.detect_public_ip()
    except (ValueError, OSError, RuntimeError):
        pass
    prompt = '节点通讯 IP / 域名'
    if candidate:
        prompt += '（自动检测 ' + candidate + '，回车接受）'
    else:
        print('公网 IPv4 自动检测未完成，请手工填写实际地址。')
    answer = input(prompt + '：').strip() or candidate
    require(bool(answer), '必须填写实际通讯地址')
    return quick.reject_example_host(answer)


def email_value(value=None):
    value = (value if value is not None else input('管理员邮箱：')).strip()
    require(len(value) <= 254 and re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', value), '管理员邮箱格式不正确')
    return value


def mysql_inputs():
    print('已选择 MySQL：需额外填写现有空数据库；不会安装 MySQL、创建账号或清空已有数据库。')
    result = {}
    for key, prompt, default in (('host', '容器可访问的 MySQL 地址', 'host.docker.internal'), ('port', 'MySQL 端口', '3306'),
                                 ('database', '空数据库名', ''), ('username', '独立数据库用户名', '')):
        value = input(prompt + (' [' + default + ']' if default else '') + '：').strip() or default
        require(value and len(value) <= 255 and not any(c in value for c in ('\x00', '\n', '\r')), '数据库参数无效')
        result[key] = value
    require(result['port'].isdigit() and 1 <= int(result['port']) <= 65535, '数据库端口无效')
    result['password'] = getpass.getpass('MySQL 密码（隐藏输入，仅通过 stdin 传递）：')
    require(len(result['password']) <= 4096 and '\x00' not in result['password'], '数据库密码长度或格式异常')
    return result


def wait_for_backend(site_name, timeout=90):
    """Do not start PKI work while the new container's normal entrypoint boots."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        connection = http.client.HTTPConnection('127.0.0.1', 7001, timeout=3)
        try:
            connection.request('GET', '/api/v2/server/handshake', headers={'Host': site_name, 'Connection': 'close'})
            if connection.getresponse().status in (200, 400, 401, 403, 405, 422):
                return
        except (OSError, http.client.HTTPException):
            pass
        finally:
            connection.close()
        time.sleep(0.25)
    raise RuntimeError('Docker 后端尚未就绪；已保留应用和密钥，请核对 Root 私有日志后重跑同一命令')


def site_preflight(environment, manager):
    """Require one actual native Nginx proxy site; never modify that site."""
    from nginx import expanded, literal_loopback_backend, parse, values, walk
    upstreams = {node.words[1]: node.children for node in walk(environment.nodes) if node.words[0] == 'upstream'}
    owned = manager.Manager(Path('/var/lib/node-endpoint'), None).installed
    excluded = owned.get('config_path') if owned else None
    candidates = []
    for filename, text in environment.files.items():
        if filename == excluded:
            continue
        for server in parse(text, filename):
            if server.words != ['server']:
                continue
            children = expanded(server.children, environment.files, environment.prefix)
            targets = set()
            unsupported_target = False
            for node in walk(children):
                if node.words[0] != 'proxy_pass':
                    continue
                try:
                    value = node.words[1]
                    alias = value[7:] if value.startswith('http://') else value
                    targets.add(literal_loopback_backend(alias if alias in upstreams else value, upstreams)[0])
                except (ValueError, RuntimeError):
                    unsupported_target = True
            names = [name for row in values(children, 'server_name') for name in row]
            if not unsupported_target and targets == {'http://127.0.0.1:7001'} and names:
                manager.trusted_file(filename)
                candidates.append({'site_config': filename, 'site_name': names[0],
                    'backend': 'http://127.0.0.1:7001', 'source_file_sha256': hashlib.sha256(text.encode()).hexdigest()})
    require(len(candidates) == 1, '请先在宝塔为此面板配置唯一反向代理站点到 http://127.0.0.1:7001，并通过 Nginx 检查；未改原站')
    return candidates[0]


def compose_document(app, key):
    def escaped(path):
        return str(path).replace('$', '$$')
    binds = [{'type': 'bind', 'source': escaped(app / name), 'target': '/www/' + name}
             for name in ('.env', *RUNTIME_DIRS)]
    binds.append({'type': 'volume', 'source': 'redis-data', 'target': '/data'})
    return {'services': {'xboard': {'image': 'xboard-nodeendpoint:' + key,
        'build': {'context': escaped(app), 'dockerfile': 'Dockerfile', 'labels': {OWNER_LABEL: key}},
        'restart': 'unless-stopped', 'ports': ['127.0.0.1:7001:7001'], 'volumes': binds,
        'extra_hosts': ['host.docker.internal:host-gateway'],
        'labels': {OWNER_LABEL: key}, 'environment': {'RESOURCE_PROFILE': 'balanced',
            'ENABLE_HORIZON': 'true', 'docker': 'true'}}},
        'volumes': {'redis-data': {'labels': {OWNER_LABEL: key}}},
        'networks': {'default': {'labels': {OWNER_LABEL: key}}}}


APP_INSTALL_HELPER = r'''
if (!function_exists('posix_geteuid') || posix_geteuid() !== 1000 || getcwd() !== '/www') { exit(91); }
$raw = stream_get_contents(STDIN, 32769);
if (strlen($raw) > 32768) { exit(92); }
$v = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
if (!is_array($v) || !in_array($v['database'] ?? '', ['sqlite','mysql'], true)
    || !is_string($v['email'] ?? null) || !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { exit(93); }
$environment = is_file('/www/.env') ? file_get_contents('/www/.env') : '';
preg_match_all('/^[\t ]*(APP_KEY|INSTALLED)[\t ]*=[\t ]*([^\r\n]*)/m', $environment, $guards, PREG_SET_ORDER);
foreach ($guards as $guard) {
    $value = trim($guard[2], " \t\"'");
    if (($guard[1] === 'APP_KEY' && $value !== '')
        || ($guard[1] === 'INSTALLED' && in_array(strtolower($value), ['1','true','yes','on'], true))) { exit(94); }
}
require '/www/vendor/autoload.php';
$app = require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
putenv('ADMIN_ACCOUNT=' . $v['email']);
$arguments = ['--database'=>$v['database'], '--no-interaction'=>true];
if ($v['database'] === 'mysql') {
    $m = $v['mysql'] ?? [];
    foreach (['host','port','database','username','password'] as $key) {
        if (!isset($m[$key]) || !is_string($m[$key]) || str_contains($m[$key], "\0")) { exit(95); }
    }
    $arguments += ['--db-host'=>$m['host'], '--db-port'=>$m['port'], '--db-name'=>$m['database'],
        '--db-user'=>$m['username'], '--db-password'=>$m['password']];
}
$exit = Illuminate\Support\Facades\Artisan::call('xboard:install', $arguments);
fwrite(STDOUT, Illuminate\Support\Facades\Artisan::output());
exit($exit);
'''


class Installer:
    def __init__(self, app):
        self.app = app
        self.key = hashlib.sha256(str(app).encode()).hexdigest()[:24]
        self.project = 'xboard-ne-' + self.key[:12]
        self.home = PRIVATE_ROOT / self.key
        self.marker_path = self.home / 'ownership.json'
        self.private_compose = self.home / COMPOSE_NAME
        self.public_compose = app / COMPOSE_NAME
        self.marker = None
        self.docker = None
        self.log_number = 0

    def setup_private(self):
        root_path(PRIVATE_ROOT).mkdir(mode=0o700, parents=True, exist_ok=True)
        root_path(self.home).mkdir(mode=0o700, exist_ok=True)
        self.home.chmod(0o700)
        for name in ('logs', 'docker-config'):
            root_path(self.home / name).mkdir(mode=0o700, exist_ok=True)
            (self.home / name).chmod(0o700)
        if not (self.home / 'compose-empty.env').exists():
            write_private(self.home / 'compose-empty.env', b'')
        self.run_dir = self.home / 'logs' / (time.strftime('%Y%m%d-%H%M%S') + '-' + os.urandom(4).hex())
        self.run_dir.mkdir(mode=0o700)
        self.environment = {'PATH': SYSTEM_PATH, 'HOME': str(self.home), 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}
        self.docker = str(root_path(shutil.which('docker', path=SYSTEM_PATH) or '/usr/bin/docker'))
        require(os.access(self.docker, os.X_OK), '请先安装可信 Docker 和 Compose v2；本工具不会代装')

    def command(self, arguments, label, *, data=None, check=True, timeout=120):
        self.log_number += 1
        stem = self.run_dir / (str(self.log_number).zfill(3) + '-' + label)
        out_fd = os.open(stem.with_suffix('.out'), os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
        err_fd = os.open(stem.with_suffix('.err'), os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
        with os.fdopen(out_fd, 'wb') as out, os.fdopen(err_fd, 'wb') as err:
            result = subprocess.run([self.docker, '--host', LOCAL_DAEMON, '--config', str(self.home / 'docker-config'),
                *arguments], cwd=self.home, env=self.environment, input=data, stdout=out, stderr=err, timeout=timeout)
        if check:
            require(result.returncode == 0, label + ' 未完成；私有诊断目录：' + str(self.run_dir))
        size = stem.with_suffix('.out').stat().st_size
        require(size <= 2 * 1024 * 1024 or label == 'build', 'Docker 元数据响应过大；诊断仅保存在 Root 私有目录')
        return result.returncode, stem.with_suffix('.out').read_bytes() if size <= 2 * 1024 * 1024 else b''

    def compose(self, arguments, label, **kwargs):
        self.assert_compose()
        return self.command(['compose', '--project-name', self.project, '--project-directory', str(self.app),
            '--env-file', str(self.home / 'compose-empty.env'), '--file', str(self.private_compose), *arguments], label, **kwargs)

    def assert_compose(self):
        require(self.marker is not None and self.marker['compose_sha256'] == digest(root_path(self.private_compose)), 'Root Compose 摘要不一致')
        require(self.public_compose.is_file() and not self.public_compose.is_symlink()
                and self.public_compose.stat().st_nlink == 1 and digest(self.public_compose) == self.marker['compose_sha256'], '应用中的专有 Compose 已改变；未执行其他 Compose')

    def preflight(self, flags):
        self.command(['version', '--format', '{{.Server.Version}}'], 'docker-version')
        self.command(['compose', 'version', '--short'], 'compose-version')
        require(Path('/var/run/docker.sock').is_socket(), '需要本机标准 Docker daemon，不使用远程 context')
        if self.marker_path.exists():
            self.marker = json.loads(root_path(self.marker_path).read_text())
            require(self.marker.get('tool') == TOOL and self.marker.get('schema') == 1
                    and self.marker.get('app') == str(self.app) and self.marker.get('key') == self.key
                    and self.marker.get('project') == self.project, 'Root 安装记录不匹配，拒绝认领')
            self.assert_compose()
            if self.marker.get('app_key_sha256'):
                require(installed(flags) and hashlib.sha256(flags['APP_KEY'].encode()).hexdigest() == self.marker['app_key_sha256'],
                        '已有安装的 APP_KEY 或安装标记改变；拒绝重新初始化或认领新身份')
        else:
            require(not self.public_compose.exists() and not self.private_compose.exists(), '发现无归属的同名 Compose，拒绝覆盖')
            require(not installed(flags), '此目录已有面板但没有本工具归属记录；已保留 APP_KEY，请先单独迁移既有 Docker 部署')
        _, output = self.command(['ps', '-aq', '--filter', 'label=com.docker.compose.project=' + self.project, '--no-trunc'], 'owned-containers')
        self.old_containers = output.decode().split()
        owns_backend = False
        for identity in self.old_containers:
            require(re.fullmatch('[a-f0-9]{64}', identity), 'Docker CID 无效')
            _, labels = self.command(['inspect', '--format', '{{json .Config.Labels}}', identity], 'container-owner')
            require(self.marker is not None and json.loads(labels).get(OWNER_LABEL) == self.key, '同项目存在外来容器，拒绝操作')
            _, ports = self.command(['inspect', '--format', '{{json .NetworkSettings.Ports}}', identity], 'container-backend')
            owns_backend |= any(binding.get('HostPort') == '7001' and binding.get('HostIp') == '127.0.0.1'
                                for bindings in (json.loads(ports) or {}).values() if bindings for binding in bindings)
        for kind, name, template in (('volume', self.project + '_redis-data', '{{json .Labels}}'),
                                     ('network', self.project + '_default', '{{json .Labels}}'),
                                     ('image', 'xboard-nodeendpoint:' + self.key, '{{json .Config.Labels}}')):
            code, labels = self.command([kind, 'inspect', '--format', template, name], 'check-' + kind + '-owner', check=False)
            if code == 0:
                require(self.marker is not None and (json.loads(labels) or {}).get(OWNER_LABEL) == self.key,
                        '发现无归属的同名 Docker ' + kind + '，拒绝复用')
        if not owns_backend:
            with socket.socket() as listener:
                try:
                    listener.bind(('127.0.0.1', 7001))
                except OSError as error:
                    raise RuntimeError('127.0.0.1:7001 已被其他服务占用；未停止或覆盖') from error
        for name in ('.docker/.data/database.sqlite', 'storage/app/private/database.sqlite'):
            database = self.app / name
            require(installed(flags) or not database.exists() or database.stat().st_size == 0,
                    '发现已有 SQLite 数据但缺少完整安装标记，拒绝初始化或清空')

    def prepare_runtime(self):
        for name in RUNTIME_DIRS:
            path = plain_path(self.app / name)
            path.mkdir(parents=True, exist_ok=True, mode=0o755)
            for folder, directories, files in os.walk(path, followlinks=False):
                for entry in [Path(folder), *(Path(folder) / item for item in [*directories, *files])]:
                    info = entry.lstat()
                    require(not stat.S_ISLNK(info.st_mode) and (entry.is_dir() or entry.is_file())
                            and (entry.is_dir() or info.st_nlink == 1), '运行目录含链接或特殊文件，拒绝准备权限')
                    require(info.st_uid in (0, APP_UID), '运行目录存在其他用户文件，拒绝改写归属')
                    os.chown(entry, APP_UID, APP_GID, follow_symlinks=False)
            path.chmod(0o700 if name == 'storage/app' else 0o755)
        env = plain_path(self.app / '.env')
        if not env.exists():
            descriptor = os.open(env, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            os.close(descriptor)
        require(env.is_file() and env.stat().st_nlink == 1 and env.stat().st_uid in (0, APP_UID), '.env 属主或文件形态异常')
        os.chown(env, APP_UID, APP_GID, follow_symlinks=False)
        env.chmod(0o600)
        for name in ('storage/app/private', 'storage/app/node_endpoint', 'storage/app/public', 'storage/logs/supervisor'):
            child = plain_path(self.app / name)
            child.mkdir(mode=0o700, parents=True, exist_ok=True)
            os.chown(child, APP_UID, APP_GID, follow_symlinks=False)
            child.chmod(0o700 if name.endswith(('private', 'node_endpoint')) else 0o755)

    def save_marker(self, phase, **extra):
        self.marker.update({'phase': phase, **extra})
        write_private(self.marker_path, (json.dumps(self.marker, indent=2) + '\n').encode())

    def prepare_compose(self, host, port, site, source_sha256):
        content = (json.dumps(compose_document(self.app, self.key), indent=2) + '\n').encode()
        if self.marker:
            require(self.private_compose.read_bytes() == content, '本版本生成的 Compose 与已有记录不同；请单独审阅迁移')
        else:
            write_private(self.private_compose, content)
            write_private(self.public_compose, content)
            self.marker = {'tool': TOOL, 'schema': 1, 'app': str(self.app), 'key': self.key,
                'project': self.project, 'compose_sha256': hashlib.sha256(content).hexdigest()}
        self.save_marker('prepared', host=host, port=port, expected_site=site,
                         source_sha256={name: digest(self.app / name) for name in SOURCE_FILES},
                         build_source_sha256=source_sha256)

    def run_application(self, fresh, database, email, mysql=None):
        if fresh:
            payload = {'database': database, 'email': email}
            if database == 'mysql':
                payload['mysql'] = mysql
            self.compose(['run', '--rm', '--no-deps', '-T', '--user', '1000:1000', '--entrypoint', '/usr/local/bin/php',
                'xboard', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', APP_INSTALL_HELPER],
                'application-install', data=json.dumps(payload).encode(), timeout=300)
        flags = env_flags(self.app / '.env')
        require(installed(flags), '应用未返回完整安装标记；已保留数据库和原密钥，请查 Root 日志')
        self.save_marker('application_ready', app_key_sha256=hashlib.sha256(flags['APP_KEY'].encode()).hexdigest())


def parser():
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument('--app-dir', default=str(Path(__file__).resolve().parents[1]))
    result.add_argument('--nginx')
    result.add_argument('--host')
    result.add_argument('--port', type=int, default=8443)
    result.add_argument('--email')
    result.add_argument('--database', choices=('sqlite', 'mysql'), default='mysql', help='New installs default to existing aaPanel MySQL/MariaDB; use --database sqlite for SQLite')
    result.add_argument('--yes', action='store_true', help='Approve the combined plan; fresh MySQL still requires a TTY for the hidden password, fresh SQLite requires --email and --host without a TTY')
    result.add_argument('--skip-firewall', action='store_true')
    return result


def main(argv=None):
    args = parser().parse_args(argv)
    require(sys.platform == 'linux' and os.geteuid() == 0 and sys.version_info >= (3, 10), '请在 Linux 面板服务器以 Root 执行，需要 Python 3.10+')
    os.umask(0o077)
    require(args.port not in (80, 443) and 1 <= args.port <= 65535, '独立 HTTPS 端口无效')
    app = application(args.app_dir)
    flags = env_flags(app / '.env')
    is_installed = installed(flags)
    original_env = digest(app / '.env') if (app / '.env').exists() else None
    coordinator = Installer(app)
    coordinator.setup_private()
    import fcntl
    lock = root_path(coordinator.home / 'installer.lock')
    descriptor = os.open(lock, os.O_WRONLY | os.O_CREAT | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    with os.fdopen(descriptor, 'w') as locked:
        lock_info = os.fstat(locked.fileno())
        require(stat.S_ISREG(lock_info.st_mode) and lock_info.st_uid == 0 and lock_info.st_nlink == 1
                and stat.S_IMODE(lock_info.st_mode) == 0o600, 'Root 锁文件形态异常')
        fcntl.flock(locked, fcntl.LOCK_EX | fcntl.LOCK_NB)
        coordinator.preflight(flags)
        source_sha256 = build_source(app)
        require(not coordinator.marker or coordinator.marker.get('build_source_sha256') == source_sha256,
                '运行源码已改变：此安装器只支持新装与同版本安全重试，请先按独立 Docker 升级教程备份并升级；未停止服务')
        native = native_module(app, coordinator.home)
        quick = native.root_tools(app)
        require(callable(getattr(quick, 'docker_setup', None)), '当前固定 Root 工具不含 Docker 自动入口，请使用配套独立发行版')
        import manager
        nginx = args.nginx or ('/www/server/nginx/sbin/nginx' if Path('/www/server/nginx/sbin/nginx').is_file() else None)
        require(nginx, '请先安装并配置实际 aaPanel Nginx，或通过 --nginx 指定；本工具不会代装')
        environment = manager.Environment(nginx)
        site = site_preflight(environment, manager)
        current = coordinator.marker.get('host') if coordinator.marker else None
        host = choose_host(quick, args.host, current)
        require(not coordinator.marker or (host == coordinator.marker['host'] and args.port == coordinator.marker['port']),
                '已有入口地址或端口不同，请先单独执行 Root 修改流程；不会在此重签证书')
        if not sys.stdin.isatty():
            require(args.yes and (is_installed or (args.database == 'sqlite' and args.email and args.host)),
                    '首次 MySQL 安装需要交互 SSH 以隐藏输入密码；SQLite 可显式 --database sqlite --email --host --yes')
        email = None if is_installed else email_value(args.email)
        mysql = mysql_inputs() if not is_installed and args.database == 'mysql' else None
        plan = {'action': 'preserve-and-start' if is_installed else 'fresh-install', 'app': str(app),
            'database': '保留原数据库' if is_installed else args.database, 'admin_email': email,
            'database_connection': {key: value for key, value in mysql.items() if key != 'password'} if mysql else None,
            'backend': 'http://127.0.0.1:7001', 'endpoint': 'https://' + ('[' + host + ']' if ':' in host else host) + ':' + str(args.port),
            'source_site': site, 'compose': str(coordinator.public_compose), 'root_logs': str(coordinator.run_dir),
            'existing_data': '保留 .env / APP_KEY / 数据库 / 插件；同源码重试不重建、不迁移、不重启运行中的容器',
            'root_steps': ('保留现有镜像与应用，仅补齐启动和入口' if is_installed else '专用站点顶层改 Root:Root 0755、构建本地镜像、www 首次安装')
                + '；私有 HTTPS、CA 托管/公共证书同步' + ('；跳过防火墙' if args.skip_firewall else '；处理本机入口 TCP 防火墙')}
        print(json.dumps(plan, ensure_ascii=False, indent=2))
        require(args.yes or input('核对以上完整计划，输入 YES 开始（其他输入取消）：').strip() == 'YES', '已取消，未构建或初始化应用')
        before_env = original_env if is_installed else None
        current_env = digest(app / '.env') if (app / '.env').exists() else None
        require(build_source(app) == source_sha256 and current_env == original_env, '确认后源码或 .env 发生变化；未开始应用操作')
        if not is_installed:
            # Only the dedicated top directory is adopted. Runtime bind children
            # are prepared separately and remain writable by container www.
            os.chown(app, 0, 0, follow_symlinks=False)
            app.chmod(0o755)
        root_path(app)
        current_env = digest(app / '.env') if (app / '.env').exists() else None
        require(build_source(app) == source_sha256 and current_env == original_env,
                '站点 Root 权限接管后源码或 .env 与审核计划不一致；未执行构建或初始化')
        if not is_installed:
            coordinator.prepare_runtime()
        coordinator.prepare_compose(host, args.port, site, source_sha256)
        print('正在配置；所有应用输出与初始管理员信息仅保存在 Root 私有日志。')
        if not is_installed:
            coordinator.compose(['build', '--pull=false', 'xboard'], 'build', timeout=3600)
            coordinator.save_marker('image_built')
        coordinator.run_application(not is_installed, args.database, email, mysql)
        coordinator.compose(['up', '-d', '--no-build', '--no-recreate', 'xboard'], 'start-owned-service', timeout=300)
        coordinator.save_marker('running')
        wait_for_backend(site['site_name'])
        quick.docker_setup(app, nginx=nginx, host=host, port=args.port, simple_confirm=True, confirmed=True,
            expected_backend='http://127.0.0.1:7001', expected_site=site, skip_firewall=args.skip_firewall)
        if before_env:
            require(digest(app / '.env') == before_env, '已有 .env 内容发生变化，请查 Root 日志；未重新初始化')
        coordinator.save_marker('complete')
        print('Docker 面板与独立 HTTPS 入口已完成。管理员初始化信息和诊断仅保存在：' + str(coordinator.run_dir))
        print('未修改原 Nginx 站点；云安全组 / 服务商防火墙请按实际入口端口核对。')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, EOFError, KeyboardInterrupt, subprocess.SubprocessError) as error:
        print('停止：' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__) + '；未自动回滚数据库。', file=sys.stderr)
        raise SystemExit(1)
