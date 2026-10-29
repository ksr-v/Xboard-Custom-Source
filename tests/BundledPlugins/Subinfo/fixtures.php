<?php
declare(strict_types=1);

// Run only against the isolated aaPanel test site. Never place this in public/.
$root = $argv[1] ?? '';
if (!is_file($root . '/artisan')) { throw new RuntimeException('Pass the test application path.'); }
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\Plugin;
use App\Services\UserService;
use App\Services\AuthService;
use App\Utils\Helper;

$action = $argv[2] ?? 'status';
$output = [];
if ($action === 'seed') {
    // ServerGroup retains Eloquent's default mass-assignment guard in this
    // exact upstream. Match by query and explicitly fill our isolated fixture.
    $group = ServerGroup::where('name', 'SubInfo integration fixtures')->first() ?? new ServerGroup();
    $group->forceFill(['name' => 'SubInfo integration fixtures'])->save();
    $password = bin2hex(random_bytes(16));
    $plans = [];
    foreach (['never' => 2, 'monthly' => 1] as $kind => $method) {
        $plans[$kind] = Plan::updateOrCreate(['name' => 'SubInfo fixture ' . $kind], [
            'group_id' => $group->id, 'transfer_enable' => 100000,
            'reset_traffic_method' => $method, 'prices' => [], 'show' => false,
        ]);
    }
    $fixtures = [];
    foreach (['admin', 'lifetime', 'dated', 'overused'] as $kind) {
        $u = User::firstOrNew(['email' => 'subinfo-' . $kind . '@example.test']);
        $u->forceFill([
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'uuid' => Helper::guid(true), 'token' => Helper::guid(),
            'is_admin' => $kind === 'admin', 'banned' => false,
            'group_id' => $group->id,
            'plan_id' => $plans[$kind === 'dated' ? 'monthly' : 'never']->id,
            'transfer_enable' => $kind === 'lifetime' ? 107374182400000 : 107374182400,
            'u' => $kind === 'overused' ? 214748364800 : 1073741824,
            'd' => $kind === 'lifetime' ? 149249114112 : 2147483648,
            'expired_at' => $kind === 'lifetime' ? null : time() + 86400 * 65,
            'next_reset_at' => null, 'last_reset_at' => null,
        ])->save();
        $fixtures[$kind] = ['id' => $u->id, 'subscription_token' => $u->token,
            'auth' => (new AuthService($u))->generateAuthData()['auth_data'],
            'email' => $u->email];
    }
    $machine = ServerMachine::firstOrCreate(['name' => 'SubInfo isolated fixture machine'], ['token' => ServerMachine::generateToken(), 'is_active' => true]);
    $fixtures['machine_id'] = $machine->id;
    foreach (['shadowsocks' => '真实测试节点 α', 'trojan' => '真实测试节点 β'] as $type => $name) {
        Server::updateOrCreate(['name' => $name], [
            'type' => $type, 'host' => '127.0.0.1', 'port' => $type === 'trojan' ? 24443 : 24444,
            'server_port' => $type === 'trojan' ? 24443 : 24444,
            'group_ids' => [(string) $group->id], 'show' => true, 'enabled' => true, 'machine_id' => $machine->id,
            'sort' => $type === 'trojan' ? 2 : 1, 'rate' => 1,
            'protocol_settings' => $type === 'trojan' ? ['tls' => 1, 'tls_settings' => ['server_name' => 'test.example.test'], 'utls' => ['enabled' => true, 'fingerprint' => 'chrome']] : ['cipher' => 'aes-128-gcm'],
        ]);
    }
    admin_setting(['show_info_to_server_enable' => 1, 'show_protocol_to_server_enable' => 0]);
    $fixtures['password'] = $password;
    $fixtures['secure_path'] = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
    $fixtures['subscribe_path'] = admin_setting('subscribe_path', 's');
    $file = $argv[3] ?? '/tmp/subinfo-fixtures.json';
    file_put_contents($file, json_encode($fixtures, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    chmod($file, 0600);
    $output = ['seeded' => true, 'fixture_file' => $file, 'users' => array_keys(array_filter($fixtures, 'is_array'))];
} elseif ($action === 'oracle') {
    foreach (User::where('email', 'like', 'subinfo-%@example.test')->get() as $user) {
        $output[$user->id] = [
            'current_date' => now(config('app.timezone'))->format(($argv[3] ?? 'MM-DD') === 'YYYY-MM-DD' ? 'Y-m-d' : 'm-d'),
            'uid' => (string) $user->id,
            'remaining_traffic' => Helper::trafficConvert($user->getRemainingTraffic()),
            'total_traffic' => Helper::trafficConvert($user->transfer_enable),
            'used_traffic' => Helper::trafficConvert($user->getTotalUsedTraffic()),
            'upload_traffic' => Helper::trafficConvert($user->u),
            'download_traffic' => Helper::trafficConvert($user->d),
            'expire_date' => $user->expired_at === null ? '长期有效' : \Carbon\Carbon::createFromTimestamp($user->expired_at, config('app.timezone'))->format(($argv[3] ?? 'MM-DD') === 'YYYY-MM-DD' ? 'Y-m-d' : 'm-d'),
            'reset_days' => (new UserService())->getResetDay($user) === null ? '无' : (string) (new UserService())->getResetDay($user),
            'raw_traffic' => ['remaining_traffic' => $user->getRemainingTraffic(), 'total_traffic' => $user->transfer_enable,
                'used_traffic' => $user->getTotalUsedTraffic(), 'upload_traffic' => $user->u, 'download_traffic' => $user->d],
        ];
    }
} elseif ($action === 'snapshot') {
    $codes = ['node_endpoint', 'smart_expiry', 'qrcodeextend', 'flowscope'];
    foreach (Plugin::whereIn('code', $codes)->orderBy('code')->get() as $p) {
        $output['plugins'][$p->code] = ['id' => $p->id, 'version' => $p->version, 'enabled' => $p->is_enabled, 'config_sha256' => hash('sha256', $p->config ?? '')];
    }
    $adminRoot = $root . '/public/assets/admin/';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($adminRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $output['admin_assets'][substr($file->getPathname(), strlen($adminRoot))] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($output['admin_assets']);
    // Compare complete foreign-plugin tables without exporting their secrets.
    // Sorting canonical row JSON also supports tables with composite keys.
    foreach (['node_endpoint_settings', 'node_endpoint_ca_credentials',
        'flowscope_server_settings', 'flowscope_machine_settings', 'flowscope_collector_cursors',
        'flowscope_machine_daily', 'flowscope_install_tickets', 'flowscope_machine_addresses'] as $table) {
        if (!Illuminate\Support\Facades\Schema::hasTable($table)) {
            $output['default_tables'][$table] = ['exists' => false];
            continue;
        }
        $rows = [];
        foreach (Illuminate\Support\Facades\DB::table($table)->get() as $row) {
            $attributes = (array) $row;
            ksort($attributes);
            $rows[] = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        sort($rows, SORT_STRING);
        $output['default_tables'][$table] = ['exists' => true, 'count' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
    }
    // All user/node fields should remain untouched by the plugin lifecycle.
    $output['users_sha256'] = hash('sha256', json_encode(User::orderBy('id')->get()->map(fn ($u) => array_diff_key($u->getAttributes(), array_flip(['last_login_at', 'last_login_ip', 'updated_at'])))->all()));
    $output['servers_sha256'] = hash('sha256', json_encode(Server::orderBy('id')->get()->map(fn ($s) => $s->getAttributes())->all()));
    $output['machines_sha256'] = hash('sha256', json_encode(ServerMachine::orderBy('id')->get()->map(fn ($m) => $m->getAttributes())->all()));
} elseif ($action === 'browser-access') {
    $fixtureData = json_decode(file_get_contents('/tmp/subinfo-fixtures.json'), true, 16, JSON_THROW_ON_ERROR);
    $output = ['secure_path' => $fixtureData['secure_path'], 'email' => $fixtureData['admin']['email'], 'password' => $fixtureData['password']];
} elseif ($action === 'downgrade-fixture') {
    Plugin::where('code', 'subinfo_customizer')->update(['version' => '0.9.0']);
    $output = ['version' => '0.9.0', 'note' => 'Database version only; invokes real PluginManager upgrade to 1.0.0.'];
} else {
    $output = ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'timezone' => config('app.timezone'),
        'database' => config('database.default'), 'plugins' => Plugin::all(['code', 'version', 'is_enabled'])->toArray()];
}
echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
