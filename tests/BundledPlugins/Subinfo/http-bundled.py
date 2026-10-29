#!/usr/bin/env python3
"""Exercise real Nginx -> PHP-FPM -> Laravel endpoints on the isolated VM."""
import argparse
import base64
import hashlib
import json
import pathlib
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--base', required=True)
parser.add_argument('--app', required=True)
parser.add_argument('--php', default='/www/server/php/83/bin/php')
parser.add_argument('--fixtures', default='/tmp/subinfo-fixtures.json')
parser.add_argument('--output', default='/tmp/subinfo-http-results.json')
parser.add_argument('--api-interval', type=float, default=1.1,
                    help='Minimum seconds between plugin private API calls; preserve the real 60/min limit.')
args = parser.parse_args()
fixtures = json.loads(pathlib.Path(args.fixtures).read_text())
results = []
api = '/api/v2/subinfo-customizer'
manager = '/api/v2/' + fixtures['secure_path'] + '/plugin/'
last_plugin_request = 0.0
real_names = ['真实测试节点 α', '真实测试节点 β']

def cli(action, *extra):
    data = subprocess.check_output([args.php, str(pathlib.Path(__file__).with_name('fixtures.php')), args.app, action, *extra], text=True)
    return json.loads(data)

def req(path, data=None, auth='admin', method=None, raw=None, content_type=None):
    global last_plugin_request
    if path.startswith(api + '/') and not path.endswith('/status') and '/assets/' not in path:
        delay = max(0.0, args.api_interval - (time.monotonic() - last_plugin_request))
        if delay:
            time.sleep(delay)
        last_plugin_request = time.monotonic()
    headers = {'Accept': 'application/json', 'User-Agent': 'SubInfoIntegration/1.0'}
    if auth:
        headers['Authorization'] = fixtures[auth]['auth']
    if raw is not None:
        payload = raw
        headers['Content-Type'] = content_type or 'application/json'
    elif data is not None:
        payload = json.dumps(data, ensure_ascii=False).encode()
        headers['Content-Type'] = 'application/json'
    else:
        payload = None
    request = urllib.request.Request(args.base.rstrip('/') + path, data=payload, headers=headers, method=method)
    try:
        response = urllib.request.urlopen(request, timeout=40)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read()
    return response.code, body, dict(response.headers)

def jreq(path, data=None, **kw):
    status, body, headers = req(path, data, **kw)
    try:
        payload = json.loads(body)
    except (ValueError, UnicodeDecodeError):
        payload = {'unparseable_response': body[:200].decode(errors='replace')}
    return status, payload

def check(name, condition, detail=None):
    item = {'name': name, 'passed': bool(condition)}
    if detail is not None:
        item['detail'] = detail
    results.append(item)
    print(('PASS ' if condition else 'FAIL ') + name, flush=True)
    pathlib.Path(args.output).write_text(json.dumps(results, ensure_ascii=False, indent=2))
    if not condition:
        raise AssertionError(name + ': ' + str(detail))

def op(action, expected=200):
    status, payload = jreq(manager + action, {'code': 'subinfo_customizer'})
    check('lifecycle ' + action, status == expected, {'status': status, 'message': payload.get('message')})
    plugins = jreq(manager + 'getPlugins')[1].get('data', [])
    defaults = {p['code']: p for p in plugins if p['code'] in ['node_endpoint', 'smart_expiry', 'qrcodeextend', 'flowscope']}
    check('four defaults enabled after ' + action, len(defaults) == 4 and all(p['is_enabled'] for p in defaults.values()))

def subscription(kind='lifetime', query=''):
    url = '/' + fixtures['subscribe_path'] + '/' + fixtures[kind]['subscription_token'] + '?flag=general' + query
    status, body, headers = req(url, auth=None)
    check('subscription HTTP ' + kind, status == 200, {'status': status})
    decoded = base64.b64decode(body, validate=True).decode()
    lines = decoded.splitlines()
    names = [urllib.parse.unquote(line.rsplit('#', 1)[1]) for line in lines]
    return lines, names, headers

def save(settings):
    status, payload = jreq(api + '/config', settings)
    check('save settings', status == 200, {'status': status, 'message': payload.get('message')})
    status, loaded = jreq(api + '/config')
    check('persistent reload equals saved settings', status == 200 and loaded.get('data', {}).get('settings') == settings)

def protocol(flag):
    path = '/' + fixtures['subscribe_path'] + '/' + fixtures['lifetime']['subscription_token'] + '?flag=' + urllib.parse.quote(flag)
    code, body, _ = req(path, auth=None)
    check('protocol HTTP ' + flag, code == 200, {'status': code})
    return body

def coexistence(stage):
    code, data = jreq('/api/v1/node-endpoint/settings')
    check('NodeEndpoint settings readable ' + stage, code == 200 and 'settings' in data.get('data', {}))
    node_settings = data['data']['settings']
    code, data = jreq('/api/v2/flowscope/status', auth=None)
    check('FlowScope compatibility enabled ' + stage,
          code == 200 and data.get('enabled') is True and data.get('compatibility', {}).get('supported') is True)
    code, data = jreq('/api/v2/flowscope/machines')
    check('FlowScope machine settings readable ' + stage,
          code == 200 and isinstance(data.get('data', {}).get('machines'), list) and len(data['data']['machines']) >= 1)
    code, data = jreq('/api/v2/flowscope/machine/' + str(fixtures['machine_id']))
    check('FlowScope fixture machine detail readable ' + stage, code == 200 and 'settings' in data.get('data', {}))
    code, script, _ = req('/plugins/qrcodeextend/qrcodeextend.js', auth=None)
    check('QRCodeExtend public runtime preserved ' + stage, code == 200 and b'Qrcodeextend' in script)
    return {'node_settings_sha256': hashlib.sha256(json.dumps(node_settings, sort_keys=True).encode()).hexdigest(),
            'qrcode_runtime_sha256': hashlib.sha256(script).hexdigest()}

try:
    status = cli('status')
    check('PHP 8.3 and MySQL', status['php'].startswith('8.3.') and status['database'] == 'mysql', status)
    initial = cli('snapshot')
    check('four bundled plugins baseline enabled', len(initial['plugins']) == 4 and all(p['enabled'] for p in initial['plugins'].values()), initial['plugins'])
    check('all eight default-plugin tables exist', len(initial['default_tables']) == 8 and all(table['exists'] for table in initial['default_tables'].values()))
    status_plugins = {p['code']: p for p in status['plugins']}
    check('new install includes enabled fifth bundled plugin', status_plugins.get('subinfo_customizer', {}).get('is_enabled') is True)
    op('disable')
    native, native_names, _ = subscription()
    check('baseline native info plus two real nodes', native_names[-2:] == ['真实测试节点 α', '真实测试节点 β'] and len(native) > 2)
    real = native[-2:]
    import yaml
    native_clash = yaml.safe_load(protocol('clash'))
    native_clash_real = {p['name']: p for p in native_clash['proxies'] if p['name'] in real_names}
    check('baseline Clash contains two real proxy definitions', len(native_clash_real) == 2)
    native_singbox = json.loads(protocol('sing-box'))
    native_singbox_real = {p['tag']: p for p in native_singbox['outbounds'] if p.get('tag') in real_names}
    check('baseline sing-box contains two real outbounds', len(native_singbox_real) == 2)
    baseline_coexistence = coexistence('before plugin')
    status, admin_html, _ = req('/' + fixtures['secure_path'], auth=None)
    check('native Admin page HTTP', status == 200)
    check('native composer chain includes NodeEndpoint and FlowScope',
          b'data-ne-install-loader' in admin_html and b'data-flowscope-loader' in admin_html)
    # Native fresh install already deployed the fifth bundled plugin.
    sync = subprocess.run([args.php, str(pathlib.Path(args.app) / 'artisan'), 'xboard:bundled-plugins'], cwd=args.app, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    check('repeat bundle synchronization succeeds', sync.returncode == 0)
    check('sync preserves explicit disabled state', not {p['code']:p for p in cli('status')['plugins']}['subinfo_customizer']['is_enabled'])
    op('enable')
    code, data = jreq(api + '/config')
    check('admin config accessible', code == 200 and 'settings' in data.get('data', {}))
    defaults = data['data']['defaults']
    for auth in [None, 'lifetime']:
        code, payload = jreq(api + '/config', auth=auth)
        check('non-admin denied ' + str(auth), code == 403)
    for count in range(5):
        settings = {'date_format': 'MM-DD', 'rows': [{'enabled': True, 'template': '信息' + str(i) + '|{uid}'} for i in range(count)]}
        save(settings)
        lines, names, _ = subscription()
        check(str(count) + ' custom rows with exact real nodes', len(lines) == count + 2 and lines[-2:] == real)
        filtered, names, _ = subscription(query='&filter=' + urllib.parse.quote('α'))
        check('filter prompt suppressed at ' + str(count) + ' rows', len(filtered) == count + 1 and filtered[-1] == real[0])
    templates = [
        '{current_date}|{uid}|{remaining_traffic}|{total_traffic}',
        '{used_traffic}|{upload_traffic}|{download_traffic}',
        '{expire_date}|{reset_days}',
    ]
    for date_format in ['MM-DD', 'YYYY-MM-DD']:
        save({'date_format': date_format, 'rows': [{'enabled': True, 'template': t} for t in templates]})
        oracle = cli('oracle', date_format)
        for kind in ['lifetime', 'dated', 'overused']:
            lines, names, headers = subscription(kind)
            values = oracle[str(fixtures[kind]['id'])]
            for name, byte_count in values.pop('raw_traffic').items():
                unit = 0
                amount = max(0, byte_count)
                while amount >= 1024 and unit < 6:
                    amount /= 1024
                    unit += 1
                values[name] = ('%.2f' % amount).rstrip('0').rstrip('.') + ['B','K','M','G','T','P','E'][unit]
            expected = []
            for template in templates:
                for key, value in values.items():
                    template = template.replace('{' + key + '}', str(value))
                expected.append(template)
            check('nine actual variables ' + kind + ' ' + date_format, names[:3] == expected, {'expected': expected, 'actual': names[:3]})
            check('real nodes preserved for ' + kind, names[-2:] == ['真实测试节点 α', '真实测试节点 β'])
    save({'date_format':'MM-DD','rows':[{'enabled':True,'template':'后排'},{'enabled':False,'template':'已禁用'},{'enabled':True,'template':'前排'}]})
    check('order and per-row disable', subscription()[1][:2] == ['后排', '前排'])
    invalid = [
        {'date_format':'MM-DD','rows':[{'enabled':True,'template':'line'}] * 5},
        *({'date_format':'MM-DD','rows':[{'enabled':True,'template':t}]} for t in ['{unknown}', '{{uid}}', '<?php phpinfo(); ?>', '<script>alert(1)</script>', 'bad\nline', 'bad\x00line', 'x' * 1000]),
        {'date_format':'d/m/Y','rows':[]},
    ]
    for index, payload in enumerate(invalid):
        code, body = jreq(api + '/config', payload)
        check('unsafe/invalid template rejected ' + str(index), code == 422, {'status':code})
    code, body = jreq(api + '/config', raw=b'{"date_format":"MM-DD","rows":[{"enabled":true,"template":"\xff"}]}')
    check('invalid UTF-8 rejected', code in [400, 422])
    # Independent YAML parser validates actual Clash output.
    token = fixtures['lifetime']['subscription_token']
    code, body, _ = req('/' + fixtures['subscribe_path'] + '/' + token + '?flag=clash', auth=None)
    clash = yaml.safe_load(body)
    check('Clash parses and preserves exact real proxy connection fields',
          code == 200 and {p['name']: p for p in clash['proxies'] if p['name'] in real_names} == native_clash_real)
    check('Clash custom information count and order', [p['name'] for p in clash['proxies']][:2] == ['后排', '前排'] and len(clash['proxies']) == 4)
    singbox = json.loads(protocol('sing-box'))
    check('sing-box real outbounds remain unchanged',
          {p['tag']: p for p in singbox['outbounds'] if p.get('tag') in real_names} == native_singbox_real)
    saved = jreq(api + '/config')[1]['data']['settings']
    op('disable')
    check('disable restores exact native subscription', subscription()[0] == native)
    code, _ = jreq(api + '/config')
    check('disabled API inaccessible', code in [403,404])
    op('disable')
    op('enable')
    check('disable/re-enable retains settings', jreq(api + '/config')[1]['data']['settings'] == saved)
    cli('downgrade-fixture')
    op('upgrade')
    check('upgrade retains settings', jreq(api + '/config')[1]['data']['settings'] == saved)
    save(defaults)
    check('restore defaults applies next subscription', len(subscription()[0]) == 5)
    check('four-plugin HTTP coexistence settings and runtime unchanged', coexistence('with plugin') == baseline_coexistence)
    code, html, _ = req('/' + fixtures['secure_path'], auth=None)
    check('Admin composer chain has exactly one loader per dynamic plugin', code == 200 and
          html.count(b'data-subinfo-customizer-loader') == 1 and html.count(b'data-ne-install-loader') == 1 and html.count(b'data-flowscope-loader') == 1)
    # Keep plugin enabled for visual testing. -- final uninstall is performed separately.
    final = cli('snapshot')
    check('four plugin records/config untouched', initial['plugins'] == final['plugins'])
    check('all default-plugin table rows untouched', initial['default_tables'] == final['default_tables'])
    check('compiled Admin resources untouched', initial['admin_assets'] == final['admin_assets'])
    check('users and real node database data untouched', initial['users_sha256'] == final['users_sha256'] and initial['servers_sha256'] == final['servers_sha256'])
    check('machine identity and token database data untouched', initial['machines_sha256'] == final['machines_sha256'])
    pathlib.Path(args.output + '.baseline').write_text(json.dumps(initial, ensure_ascii=False, indent=2))
except Exception as error:
    print(type(error).__name__ + ': ' + str(error), flush=True)
    raise
print('ALL HTTP CHECKS PASSED (' + str(len(results)) + ')', flush=True)
