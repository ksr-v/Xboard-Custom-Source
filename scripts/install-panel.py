#!/usr/bin/env python3
"""Root coordinator. Composer, Artisan and plugin lifecycle always run as site UID."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import ssl
import stat
import subprocess
import sys
import urllib.parse
import urllib.request

BOOTSTRAP_SHA256 = '384b9087c6a938f34b23a461e6388a8a1f83ebb3c042d7ae8b868ed941caf384'
TOOLS_SHA256 = '45f82dff04ef90dbf0f67cba0bc04ed59f96307ed840742d12aa733d853dfee5'
FIREWALL_PATCH_SHA256 = 'c230d11ec268c1754b26d032108e3d2ce2372af2674c9dfad021e1e98439adcb'
BOOTSTRAP_URL = 'https://github.com/ksr-v/Xboard-Independent-NodeEndpoint/releases/download/root-installer-v1.0.5/quick_install.py'
SYSTEM_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
CODE_DIRS = ('app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'public', 'plugins',
             'plugins-core', 'bundled-plugins', 'root-assets', 'scripts', 'theme', 'vendor', '.git', 'storage')
SOURCE_DIRS = ('docs', 'tests', '.github', '.docker')
SOURCE_FILES = ('.dockerignore', '.editorconfig', 'Dockerfile', 'package.json', 'phpstan.neon',
                'compose.1panel.sample.yaml', 'compose.host.sample.yaml', 'compose.sample.yaml',
                'compose.split.sample.yaml', 'build.json')
RUNTIME_DIRS = ('storage', 'storage/app', 'storage/framework', 'bootstrap/cache', 'plugins', 'public/plugins', 'public/theme',
                'storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions',
                'storage/logs', 'storage/app/private', 'storage/app/node_endpoint')
ROOT_STATE = Path('/var/lib/node-endpoint')


def require(value, reason):
    if not value:
        raise RuntimeError(reason)


def plain_path(path):
    path = Path(path).absolute()
    for part in (path, *path.parents):
        require(not part.is_symlink(), '路径不能含符号链接：' + str(path))
    return path


def root_path(path):
    path = plain_path(path)
    for part in (path, *path.parents):
        if part.exists():
            info = part.stat()
            require(info.st_uid == 0 and not info.st_mode & 0o022,
                    'Root 工具路径必须归 Root 且不可被其他用户写入：' + str(path))
    return path


def env_values(path):
    """Only flags needed by guards; never return or log credentials."""
    result = {}
    if path.is_file():
        require(path.stat().st_size <= 1048576, '.env 文件异常过大')
        for line in path.read_text(encoding='utf-8').splitlines():
            match = re.match(r'^\s*(INSTALLED|APP_KEY)\s*=\s*(.*?)\s*$', line)
            if match:
                result[match[1]] = match[2].strip('"\'')
    return result


def application(args):
    app = plain_path(args.app_dir).resolve(strict=True)
    require(app.is_dir() and len(app.parts) >= 3 and str(app) not in ('/var/www', '/www/wwwroot', '/opt'), '需要专用 Xboard 目录')
    require(Path('/root') not in app.parents, '应用应放在可由站点用户访问的专用网站目录，不放在 /root 下')
    require(ROOT_STATE != app and app not in ROOT_STATE.parents, 'Root PKI 必须位于整个应用目录之外')
    plain_path(app / '.env')
    for name in ('artisan', 'composer.json', 'composer.lock', 'bundled-plugins/manifest.json'):
        require((app / name).is_file() and not (app / name).is_symlink(), '缺少独立发行文件：' + name)
    flags = env_values(app / '.env')
    installed = flags.get('INSTALLED', '').lower() in ('true', '1', 'yes', 'on')
    if args.action == 'install':
        require(not installed and not flags.get('APP_KEY'), '已有 APP_KEY / 已安装面板，禁止全新安装。请备份后使用 update.sh --deployed-code。')
    else:
        require(installed and bool(flags.get('APP_KEY')), '升级要求现有 INSTALLED=true 和原 APP_KEY；不会初始化或生成密钥')
        require(args.deployed_code, '请先部署已校验的固定发行源码，再使用 --deployed-code；不拉取浮动分支')
    return app


def site_user(args, app):
    name = args.app_user or os.environ.get('XBOARD_APP_USER')
    if not name and args.action == 'upgrade':
        owners = {p.stat().st_uid for p in (app / '.env', app / 'storage') if p.exists() and p.stat().st_uid != 0}
        if len(owners) == 1:
            name = pwd.getpwuid(owners.pop()).pw_name
    if not name and not args.non_interactive:
        name = input('Xboard 运行用户（例如宝塔 www，不能是 root）：').strip()
    require(name, '请指定与 PHP-FPM / 队列一致的 --app-user')
    user = pwd.getpwnam(name)
    require(user.pw_uid != 0 and user.pw_gid != 0, '应用不能使用 Root 用户或组')
    return user


def executable(value, candidates):
    for candidate in ([value] if value else candidates):
        selected = shutil.which(candidate, path=SYSTEM_PATH) if candidate else None
        if selected:
            original = Path(selected).absolute()
            for part in (original, *original.parents):
                info = part.lstat()
                require(info.st_uid == 0 and (part.is_symlink() or not info.st_mode & 0o022), '命令路径可被非 Root 修改')
            path = root_path(original.resolve(strict=True))
            require(path.is_file() and os.access(path, os.X_OK), '命令不可执行：' + str(path))
            return str(path)
    raise RuntimeError('缺少依赖，请先安装并指定路径：' + ', '.join(candidates))


def as_user(user, app, argv, extra=None, capture=False):
    environment = {'PATH': SYSTEM_PATH, 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8', 'HOME': user.pw_dir,
                   'USER': user.pw_name, 'LOGNAME': user.pw_name, 'COMPOSER_ALLOW_SUPERUSER': '0'}
    for key in ('ADMIN_ACCOUNT', 'ENABLE_SQLITE', 'ENABLE_REDIS', 'DB_TYPE', 'DB_HOST', 'DB_PORT',
                'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'):
        if key in os.environ:
            environment[key] = os.environ[key]
    for suffix, target in (('DRIVER', 'DB_TYPE'), ('HOST', 'DB_HOST'), ('PORT', 'DB_PORT'),
                           ('NAME', 'DB_DATABASE'), ('USER', 'DB_USERNAME'), ('PASSWORD', 'DB_PASSWORD')):
        if 'XBOARD_DB_' + suffix in os.environ:
            environment[target] = os.environ['XBOARD_DB_' + suffix]
    if extra:
        environment.update(extra)
    result = subprocess.run([executable(None, ['runuser']), '-u', user.pw_name, '--', *map(str, argv)],
                            cwd=app, env=environment, stdout=subprocess.PIPE if capture else None,
                            stderr=subprocess.PIPE if capture else None, timeout=900)
    require(result.returncode == 0, '应用用户命令未完成：' + Path(str(argv[0])).name + '；请核对本机输出，未自动回滚数据库')
    return result.stdout.decode('utf-8', errors='replace') if capture else None


def check_composer(user, app, php, composer, cache):
    hint = '请预先准备可信的新 Composer PHAR，并加 --composer /绝对路径/composer.phar；不会自动升级系统 Composer 或修改锁文件。'
    try:
        output = as_user(user, app, [php, composer, '--no-ansi', '--version'],
                         {'COMPOSER_CACHE_DIR': cache, 'COMPOSER_HOME': cache + '/home'}, capture=True)
    except RuntimeError as error:
        raise RuntimeError('无法以网站用户检查 Composer 版本，请核对 PHP / PHAR 路径及读取权限。' + hint) from error
    match = re.search(r'(?m)^Composer(?: version)?\s+(\d+)\.(\d+)\.(\d+)(?=[\s+-]|$)', output)
    require(match, '无法识别 Composer 版本，需要 Composer >= 2.2。' + hint)
    version = tuple(map(int, match.groups()))
    require(version >= (2, 2, 0), '当前 Composer ' + '.'.join(match.groups()) + ' 过旧，需要 Composer >= 2.2。' + hint)
    print('Composer ' + '.'.join(match.groups()) + ' 版本检查通过；应用安装以网站用户执行。')


def check_php(user, app, php, storage_link=False):
    probe = ('echo "\\nXBOARD_PHP_PREFLIGHT=" . json_encode(['
             '"version_id" => PHP_VERSION_ID, "symlink" => function_exists("symlink"), '
             '"ini" => php_ini_loaded_file() ?: "(none)"]) . "\\n";')
    output = as_user(user, app, [php, '-r', probe], capture=True)
    match = re.search(r'(?m)^XBOARD_PHP_PREFLIGHT=(.+)$', output)
    require(match, '无法读取 PHP CLI 环境检查结果；请核对 --php 指定的程序和配置')
    result = json.loads(match.group(1))
    require(isinstance(result, dict) and isinstance(result.get('version_id'), int)
            and result['version_id'] >= 80200, 'PHP CLI 需要 8.2 或更新版本')
    if storage_link:
        require(result.get('symlink') is True,
                'PHP CLI 的 symlink 函数不可用，已在 Composer / 数据库安装前停止。'
                '请在宝塔对应 PHP 版本的禁用函数中移除 symlink，确认 CLI 配置生效后重试；'
                '此步骤无需开放 exec。当前 PHP：' + php + '；php.ini：' + str(result.get('ini')))


def permission_paths(app):
    device = app.stat().st_dev
    result = [app]
    for name in CODE_DIRS + SOURCE_DIRS:
        start = app / name
        if not start.exists() and not start.is_symlink():
            continue
        require(start.is_dir() and not start.is_symlink(), '不支持链接工作树或代码目录：' + name)
        for current, directories, files in os.walk(start, followlinks=False):
            parent = Path(current)
            if parent == app / '.docker' and '.data' in directories:
                # Existing Docker volumes / legacy SQLite data are not source.
                directories.remove('.data')
            require(parent.stat().st_dev == device, '请单独审计挂载的数据目录：' + str(parent))
            result.append(parent)
            for child in list(directories) + files:
                path = parent / child
                if path.is_symlink():
                    # Laravel storage:link may point to an as-yet empty public
                    # storage directory. Internal dangling targets are safe to
                    # preserve; an external target is rejected even if absent.
                    require(path.resolve(strict=False).is_relative_to(app), '拒绝应用目录之外的链接：' + str(path))
                    if child in directories:
                        directories.remove(child)
                    continue
                info = path.lstat()
                require(stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode), '拒绝特殊文件：' + str(path))
                require(info.st_dev == device and (path.is_dir() or info.st_nlink == 1), '拒绝额外挂载或硬链接：' + str(path))
                if path.is_file():
                    result.append(path)
    for name in ('artisan', 'composer.json', 'composer.lock', 'composer.phar', '.env', '.env.example',
                 'init.sh', 'update.sh', 'README.md', 'LICENSE', '.gitignore', '.gitattributes', *SOURCE_FILES):
        path = app / name
        if path.exists():
            require(not path.is_symlink() and path.is_file() and path.stat().st_nlink == 1, '不安全的应用文件：' + name)
            result.append(path)
    return list(dict.fromkeys(result))


def prepare_permissions(app, user):
    paths = permission_paths(app)  # Preflight all selected paths before changing any.
    env = plain_path(app / '.env')
    if not env.exists():
        template = plain_path(app / '.env.example')
        require(template.is_file() and template.stat().st_size <= 1048576, '缺少 .env.example')
        with env.open('xb') as handle:
            handle.write(template.read_bytes())
        os.chown(env, user.pw_uid, user.pw_gid)
        env.chmod(0o600)
    print('准备权限：应用代码由站点用户维护以兼容后台归档更新；Root 工具、监听和 PKI 保持外置。')
    for path in paths:
        os.chown(path, user.pw_uid, user.pw_gid)
        relative = path.relative_to(app).as_posix()
        private = relative == '.env' or relative.startswith(('storage/app/private', 'storage/app/node_endpoint'))
        mode = (0o700 if path.is_dir() else 0o600) if private else (0o755 if path.is_dir() or path.stat().st_mode & 0o111 else 0o644)
        path.chmod(mode)
    for name in RUNTIME_DIRS:
        path = plain_path(app / name)
        path.mkdir(parents=True, exist_ok=True, mode=0o755)
        os.chown(path, user.pw_uid, user.pw_gid)
        path.chmod(0o700 if name in ('storage/app/private', 'storage/app/node_endpoint') else 0o755)
    parent = root_path('/var/cache/xboard-composer')
    parent.mkdir(mode=0o755, parents=True, exist_ok=True)
    # main() uses umask 077. Explicitly allow site users to traverse the
    # Root-owned parent; each UID's actual cache remains private below it.
    parent.chmod(0o755)
    cache = parent / str(user.pw_uid)
    require(not cache.is_symlink() and (not cache.exists() or cache.stat().st_uid in (0, user.pw_uid)), 'Composer 缓存归属异常')
    cache.mkdir(mode=0o700, exist_ok=True)
    os.chown(cache, user.pw_uid, user.pw_gid)
    cache.chmod(0o700)
    return str(cache)


class HTTPSOnly(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        require(urllib.parse.urlsplit(newurl).scheme == 'https', '拒绝非 HTTPS 重定向')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def download(url, limit):
    context = ssl.create_default_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    opener = urllib.request.build_opener(HTTPSOnly(), urllib.request.HTTPSHandler(context=context))
    with opener.open(url, timeout=60) as response:
        data = response.read(limit + 1)
    require(len(data) <= limit, '下载文件超过大小限制')
    return data


def install_firewall_patch(app, tools, directory):
    """Verify public asset bytes, then import only their private Root copy.

    The released bootstrap/ZIP pins remain unchanged. The application-owned
    asset is data until its independent pin is checked; it is never imported.
    A single-module cache cannot shadow manager/pki with an extra Python file.
    """
    source = plain_path(app / 'root-assets/firewall.py')
    require(source.is_file() and source.stat().st_nlink == 1 and source.stat().st_size <= 65536,
            '缺少独立防火墙补丁，或补丁文件类型/大小异常')
    data = source.read_bytes()
    require(hashlib.sha256(data).hexdigest() == FIREWALL_PATCH_SHA256,
            '独立防火墙补丁摘要不一致，尚未执行')
    panel = root_path(Path(tools) / 'tools/panel')
    cache = root_path(Path(directory) / 'firewall-patches' / FIREWALL_PATCH_SHA256)
    resolved_app = app.resolve()
    for path in (panel, cache):
        require(not path.resolve().is_relative_to(resolved_app)
                and not resolved_app.is_relative_to(path.resolve()),
                'Root 补丁和工具缓存必须在应用目录之外')
    root_path(cache.parent).mkdir(mode=0o700, exist_ok=True)
    cache.parent.chmod(0o700)
    cache.mkdir(mode=0o700, exist_ok=True)
    cache.chmod(0o700)
    require(all(path.name == 'firewall.py' for path in cache.iterdir()),
            '独立防火墙补丁缓存含未知文件，拒绝导入')
    target = root_path(cache / 'firewall.py')
    if target.exists():
        require(target.is_file() and target.stat().st_nlink == 1 and target.stat().st_size <= 65536,
                '独立防火墙补丁缓存文件异常')
        require(hashlib.sha256(target.read_bytes()).hexdigest() == FIREWALL_PATCH_SHA256,
                '独立防火墙补丁缓存摘要不一致，拒绝覆盖或执行')
    else:
        descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
    for name in ('manager', 'pki', 'nginx', 'panel_ca', 'panel_passphrase', 'firewall'):
        existing = sys.modules.get(name)
        expected = target if name == 'firewall' else panel / (name + '.py')
        if existing is not None:
            origin = getattr(existing, '__file__', None)
            require(origin is not None and Path(origin).resolve() == expected.resolve(),
                    'Root 进程已加载非校验缓存模块，拒绝继续：' + name)
    sys.dont_write_bytecode = True
    sys.path.insert(0, str(cache))
    spec = importlib.util.spec_from_file_location('firewall', target)
    require(spec is not None and spec.loader is not None, '无法加载已校验防火墙补丁')
    module = importlib.util.module_from_spec(spec)
    sys.modules['firewall'] = module
    try:
        spec.loader.exec_module(module)
    except BaseException:
        if sys.modules.get('firewall') is module:
            del sys.modules['firewall']
        raise
    return module


def root_tools(app):
    directory = root_path('/opt/xboard-root-tools')
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    directory.chmod(0o700)
    cache = root_path(directory / BOOTSTRAP_SHA256)
    cache.mkdir(mode=0o700, exist_ok=True)
    cache.chmod(0o700)
    target = root_path(cache / 'quick_install.py')
    if target.exists():
        require(target.is_file() and target.stat().st_nlink == 1, 'Root 缓存异常')
        data = target.read_bytes()
    else:
        bundled = plain_path(app / 'root-assets/quick_install.py')
        require(not bundled.exists() or bundled.stat().st_size <= 262144, 'Root 引导文件异常过大')
        data = bundled.read_bytes() if bundled.is_file() else download(BOOTSTRAP_URL, 262144)
    require(hashlib.sha256(data).hexdigest() == BOOTSTRAP_SHA256, 'Root 引导摘要不一致，尚未执行')
    if not target.exists():
        with target.open('xb') as handle:
            handle.write(data)
        target.chmod(0o600)
    spec = importlib.util.spec_from_file_location('xboard_verified_quick', target)
    quick = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(quick)
    require(quick.TOOLS_SHA256 == TOOLS_SHA256, '工具包版本不一致')
    package = plain_path(app / 'root-assets/node-endpoint-tools-1.0.4.zip')
    require(not package.exists() or package.stat().st_size <= 16777216, 'Root 工具包异常过大')
    data = package.read_bytes() if package.is_file() else quick.download()
    tools = quick.install_cache(quick.verified_members(data))
    sys.dont_write_bytecode = True
    sys.path.insert(0, str(tools / 'tools/panel'))
    install_firewall_patch(app, tools, directory)
    return quick


def endpoint_plan(args, app, quick):
    import manager
    from pki import validate_host
    if (ROOT_STATE / 'installation.json').exists():
        state = manager.safe_state(ROOT_STATE)
        manager.trusted_file(state / 'installation.json')
        installed = manager.Manager(state, None).assert_owned()
        plan = installed['plan']
        require(Path(plan['app']).resolve() == app and plan.get('https'), '现有 Root 入口属于其他面板或不是 HTTPS，拒绝覆盖')
        host = validate_host(args.host)[0] if args.host else plan['host']
        require(host == plan['host'] and (args.port is None or args.port == plan['port']), '现有入口地址/端口不同，请单独审计 modify；不会重签 CA')
        return plan, True
    require(not args.non_interactive, '首次独立入口需要 SSH 交互确认；新 CA 口令自动加密托管，可显式 --skip-endpoint 延期')
    host = validate_host(args.host or input('节点通讯 IP / 域名（不含协议，建议公网 IP）：').strip())[0]
    port = args.port or 8443
    require(port not in (80, 443), '独立入口不能占用网站 80/443')
    nginx = args.nginx or ('/www/server/nginx/sbin/nginx' if Path('/www/server/nginx/sbin/nginx').is_file() else None)
    environment = manager.Environment(nginx)
    sites = [item for item in quick.discover(environment, manager) if Path(item['app']).resolve() == app]
    require(sites, '未发现此面板实际运行的 Nginx/PHP-FPM 站点。请先完成宝塔网站配置；或显式 --skip-endpoint')
    site = quick.select(sites, 'Xboard 站点')
    require(site['include_dir'], '需要明确的 Nginx HTTP include 目录，请用独立 Root 工具检测')
    return {'host': host, 'port': port, 'app': str(app), 'site': site, 'nginx': nginx,
            'ws_backend': site['ws_backend']}, False


def execute_endpoint_install(manager, args):
    """Improve only this install prompt; retain the manager's exact-value guard.

    The manager prints its final reviewable plan before calling confirmation.
    Wrong interactive input can be corrected before any PKI/Nginx transaction.
    Explicit noninteractive values retain the original strict failure behavior.
    """
    original = manager.confirmation
    install_phrase = 'INSTALL ' + args.host + ':' + str(args.port)

    def confirm(expected, provided=None):
        if expected != install_phrase or provided is not None:
            return original(expected, provided)
        print('请核对上方安装计划。以下是确认内容，请在提示处完整输入；输入 CANCEL 取消。')
        print(expected)
        while True:
            try:
                answer = input('请完整输入 "' + expected + '"：').strip()
            except (EOFError, KeyboardInterrupt) as error:
                raise RuntimeError('已取消入口安装确认；未进入证书签发或 Nginx 配置事务。'
                                   '已安装的面板请用 update.sh --deployed-code 继续。') from error
            if answer.upper() == 'CANCEL':
                raise RuntimeError('已取消入口安装确认；未进入证书签发或 Nginx 配置事务。'
                                   '已安装的面板请用 update.sh --deployed-code 继续。')
            if answer == expected:
                return original(expected, answer)
            print('输入不匹配，尚未确认。需要完整输入（包含地址和端口）：' + expected)

    manager.confirmation = confirm
    try:
        return manager.execute(args)
    finally:
        manager.confirmation = original


def complete_endpoint(app, user, php, quick, plan, existing):
    import manager
    from panel_ca import sync_panel_ca
    mode = 'rewrite' if plan.get('ws_backend') else 'rest'
    as_user(user, app, [php, 'artisan', 'xboard:configure-endpoint', '--host=' + plan['host'],
                       '--port=' + str(plan['port']), '--ws-mode=' + mode])
    if not existing:
        site = plan['site']
        argv = (['--nginx', plan['nginx']] if plan['nginx'] else []) + ['install', '--host', plan['host'],
            '--port', str(plan['port']), '--site-config', site['site_config'], '--site-name', site['site_name'],
            '--include-dir', site['include_dir'], '--app', str(app), '--app-user', user.pw_name,
            '--php', php, '--deployment', 'fpm', '--backend', site['backend']]
        if plan['ws_backend']:
            argv += ['--enable-ws', '--ws-backend', plan['ws_backend']]
        execute_endpoint_install(manager, manager.parser().parse_args(argv))
    failures = []
    try:
        result = quick.configure_firewall()
        print('本机防火墙：' + json.dumps(result, ensure_ascii=False))
        if result['status'] not in ('ensured', 'saved_inactive'):
            failures.append('firewall')
    except (RuntimeError, OSError, ValueError) as error:
        print('防火墙步骤未完成：' + str(error), file=sys.stderr)
        failures.append('firewall')
    try:
        print('公共 CA：' + json.dumps(sync_panel_ca(), ensure_ascii=False))
    except (RuntimeError, OSError, ValueError) as error:
        print('CA 同步未完成：' + str(error), file=sys.stderr)
        failures.append('ca')
    require(not failures, '入口已保留，待完成步骤：' + ','.join(failures) + '；使用固定 Root 命令 --configure-firewall / --sync-ca 单独重试')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('install', 'upgrade'))
    parser.add_argument('--app-dir', default=str(Path(__file__).resolve().parents[1]))
    parser.add_argument('--app-user')
    parser.add_argument('--php', default=os.environ.get('XBOARD_PHP_BIN'))
    parser.add_argument('--composer', default=os.environ.get('XBOARD_COMPOSER_BIN'))
    parser.add_argument('--host')
    parser.add_argument('--port', type=int)
    parser.add_argument('--nginx')
    parser.add_argument('--skip-endpoint', action='store_true')
    parser.add_argument('--non-interactive', action='store_true')
    parser.add_argument('--deployed-code', action='store_true')
    parser.add_argument('--prepare-only', action='store_true')
    parser.add_argument('--enable-all', action='store_true')
    args = parser.parse_args()
    require(sys.platform == 'linux' and os.geteuid() == 0, '请在面板服务器以 Root 执行；应用子进程会降权')
    require(sys.version_info >= (3, 10), '需要 Python 3.10+')
    os.umask(0o077)
    require(args.port is None or 1 <= args.port <= 65535, '端口应为 1..65535')
    app = application(args)  # Existing APP_KEY is guarded before any mutation.
    user = site_user(args, app)
    php = executable(args.php, ['/www/server/php/83/bin/php', '/www/server/php/82/bin/php', 'php8.3', 'php8.2', 'php'])
    composer = args.composer or shutil.which('composer', path=SYSTEM_PATH)
    if not composer and (app / 'composer.phar').is_file():
        composer = str(app / 'composer.phar')
    require(args.prepare_only or (composer and Path(composer).is_file()), '请预先安装可信 Composer >= 2.2，并用 --composer 指定路径；不在线自更新 Composer')
    if composer and not str(composer).startswith(str(app) + '/'):
        root_path(composer)
    quick = None if args.skip_endpoint or args.prepare_only else root_tools(app)
    plan, existing = endpoint_plan(args, app, quick) if quick else (None, False)
    if existing:
        require(plan.get('app_user') == user.pw_name and Path(plan.get('php', '')).resolve() == Path(php),
                '现有 Root 入口的应用用户 / PHP 与本次不同，请先单独审计；未改动配置')
    check_php(user, app, php, storage_link=args.action == 'install' and not args.prepare_only)
    cache = prepare_permissions(app, user)
    if args.prepare_only:
        print('Root 权限准备完成；未安装应用、修改数据库或创建独立入口。')
        return
    check_composer(user, app, php, composer, cache)
    as_user(user, app, [php, composer, 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'],
            {'COMPOSER_CACHE_DIR': cache, 'COMPOSER_HOME': cache + '/home'})
    as_user(user, app, [php, 'artisan', 'xboard:bundled-plugins', '--stage-only'])
    if args.action == 'install':
        as_user(user, app, [php, 'artisan', 'xboard:install', *(['--no-interaction'] if args.non_interactive else [])])
        as_user(user, app, [php, 'artisan', 'storage:link'])
    else:
        as_user(user, app, [php, 'artisan', 'xboard:update', '--no-interaction'])
    if args.enable_all:
        as_user(user, app, [php, 'artisan', 'xboard:bundled-plugins', '--enable-all'])
    if quick:
        complete_endpoint(app, user, php, quick, plan, existing)
    else:
        print('已显式跳过独立入口；NodeEndpoint 插件已安装，IP 入口仍需 Root 配置和公共 CA 导入。')
    for command in ('config:clear', 'route:clear', 'view:clear'):
        as_user(user, app, [php, 'artisan', command])
    print('Xboard 与内置插件安装/升级完成。云安全组需单独放行；FlowScope 采集器在对应节点部署。')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError, KeyError, EOFError, KeyboardInterrupt, subprocess.SubprocessError) as error:
        print('停止：' + str(error), file=sys.stderr)
        raise SystemExit(1)
