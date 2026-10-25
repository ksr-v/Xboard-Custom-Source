<?php
declare(strict_types=1);

// Only synthetic in-memory SQLite data and recorded Redis publications are used.
$sourceRoot = dirname(__DIR__, 2);
$vendor = getenv('XBOARD_TEST_VENDOR') ?: $sourceRoot . '/vendor/autoload.php';
if (!is_file($vendor)) {
    throw new RuntimeException('Set XBOARD_TEST_VENDOR to a local Composer vendor/autoload.php.');
}
$loader = require $vendor;
$loader->setPsr4('App\\', [$sourceRoot . '/app']);
foreach ($loader->getClassMap() as $class => $path) {
    if (str_starts_with($class, 'App\\')) {
        $local = $sourceRoot . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($local)) $loader->addClassMap([$class => $local]);
    }
}

use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Observers\ServerObserver;
use App\Services\{DeviceStateService, NodeSyncService, ServerService};
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\{Redirector, RouteCollection, UrlGenerator};
use Illuminate\Support\Facades\{Cache, DB, Facade, Redis, Schema};
use Illuminate\Validation\ValidationException;

final class Hysteria2RecordingRedis
{
    public array $messages = [];
    public function publish(string $channel, string $payload): int
    {
        check($channel === 'node:push', 'Unexpected fixture publication channel.');
        $this->messages[] = ['transaction_level' => DB::transactionLevel(),
            ...json_decode($payload, true, 512, JSON_THROW_ON_ERROR)];
        return 1;
    }
}
function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function scenario(string $name, callable $action): void
{
    global $passed, $redis;
    $redis->messages = [];
    $action();
    $passed++;
    echo "PASS {$name}\n";
}
function payload(array $changes = []): array
{
    return array_replace_recursive([
        'type' => 'hysteria', 'name' => 'HY2 forwarding fixture', 'host' => '127.0.0.1',
        'port' => '42450-42453', 'server_port' => 42443, 'rate' => 1,
        'group_ids' => ['1'], 'route_ids' => [], 'show' => true, 'enabled' => true,
        'protocol_settings' => ['version' => 2, 'hop_interval' => 5,
            'tls' => ['server_name' => 'hy2.fixture.test', 'allow_insecure' => false],
            'bandwidth' => ['up' => 50, 'down' => 100], 'obfs' => ['open' => false]],
    ], $changes);
}
function validatedRequest(array $data): ServerSave
{
    global $app;
    $request = ServerSave::create('http://fixture.test/admin/server/save', 'POST', $data);
    $request->setContainer($app);
    $request->setRedirector(new Redirector(new UrlGenerator(new RouteCollection(), Request::create('http://fixture.test/'))));
    $request->validateResolved(); // Includes prepareForValidation, just like an HTTP FormRequest.
    return $request;
}
function saveFixture(array $data): Server
{
    $response = (new ManageController())->save(validatedRequest($data));
    check($response->getStatusCode() === 200 && $response->getData(true)['data'] === true, 'Fixture controller save failed.');
    return isset($data['id']) ? Server::findOrFail($data['id']) : Server::orderByDesc('id')->firstOrFail();
}
function expectRejected(array $data, string $field): void
{
    try {
        validatedRequest($data);
    } catch (ValidationException $error) {
        check(isset($error->errors()[$field]), 'Expected field validation error was absent.');
        return;
    }
    throw new RuntimeException('Malformed Hysteria2 input was accepted.');
}
function getConfig(Server $node, ?string $etag = null): Illuminate\Http\Response
{
    $request = Request::create('http://fixture.test/api/v1/server/UniProxy/config', 'GET');
    $request->attributes->set('node_info', $node);
    if ($etag !== null) $request->headers->set('If-None-Match', $etag);
    return (new UniProxyController(new DeviceStateService()))->config($request);
}

$app = new Application($sourceRoot);
$app->instance('config', new Repository([
    'app' => ['env' => 'testing', 'key' => 'base64:' . base64_encode(str_repeat('t', 32))],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]]],
    'cache' => ['default' => 'array', 'stores' => [
        'array' => ['driver' => 'array'], 'redis' => ['driver' => 'array'],
    ]],
]));
Facade::setFacadeApplication($app);
foreach ([Illuminate\Filesystem\FilesystemServiceProvider::class,
    Illuminate\Database\DatabaseServiceProvider::class,
    Illuminate\Cache\CacheServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class,
    Illuminate\Validation\ValidationServiceProvider::class] as $provider) $app->register($provider);
$app->boot();
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
    public function __construct() {}
});
$redis = new Hysteria2RecordingRedis();
Redis::swap($redis);
Cache::store('redis')->forever('admin_settings', ['server_push_interval' => 60, 'server_pull_interval' => 60]);
Schema::create('v2_server', function (Blueprint $table): void {
    $table->id(); $table->string('name'); $table->string('type'); $table->string('host');
    $table->string('port'); $table->string('server_port'); $table->text('protocol_settings');
    $table->text('group_ids'); $table->text('route_ids'); $table->boolean('enabled'); $table->boolean('show');
    $table->float('rate'); $table->integer('machine_id')->nullable();
    $table->text('custom_outbounds')->nullable(); $table->text('custom_routes')->nullable();
    $table->text('cert_config')->nullable(); $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
});
Server::observe(ServerObserver::class);
$passed = 0;

scenario('saved HY2 range exposes typed optional Node API contract', function (): void {
    global $node;
    $node = saveFixture(payload());
    check($node->port === '42450-42453', 'The advertised range was not preserved.');
    $config = ServerService::buildNodeConfig($node);
    check($config['port_hopping'] === ['start' => 42450, 'end' => 42453], 'Managed range payload differs.');
    check($config['server_port'] === 42443, 'Single listener differs.');
    check($node->protocol_settings['hop_interval'] === 5, 'Positive client interval changed.');
});

scenario('single-port HY2 and Hysteria1 omit managed forwarding', function (): void {
    foreach ([payload(['port' => 443]), payload(['protocol_settings' => ['version' => 1]])] as $data) {
        check(!array_key_exists('port_hopping', ServerService::buildNodeConfig(new Server($data))), 'Unexpected optional forwarding payload.');
    }
});

scenario('port boundaries and a degenerate ascending range are safe integers', function (): void {
    foreach ([['1-65535', 65535, 1, 65535], ['00443-00444', '00443', 443, 444], ['443-443', 443, 443, 443]] as [$range, $listen, $start, $end]) {
        $request = validatedRequest(payload(['port' => $range, 'server_port' => $listen]));
        $config = ServerService::buildNodeConfig(new Server($request->validated()));
        check($config['port_hopping'] === ['start' => $start, 'end' => $end], 'Port boundary normalization differs.');
    }
});

scenario('malformed, reverse, out-of-range and list client ports are rejected', function (): void {
    foreach ([0, 65536, -1, true, ['443'], '1-65536', '0-443', '42453-42450',
        '42450-42451,42453', '42450,42453', ' 443', '443 ', '443\n', '1-2-3', '443;touch /tmp/nope'] as $port) {
        expectRejected(payload(['port' => $port]), 'port');
    }
});

scenario('listener must be exactly one valid integer port', function (): void {
    foreach ([0, 65536, -1, true, ['443'], '443-444', '443,444', '443.5', '443foo', ' 443'] as $port) {
        expectRejected(payload(['server_port' => $port]), 'server_port');
    }
});

scenario('interval must be a positive integer or null', function (): void {
    foreach ([-1, 'bad', '1.5', true, []] as $interval) {
        expectRejected(payload(['protocol_settings' => ['hop_interval' => $interval]]), 'protocol_settings.hop_interval');
    }
    check(validatedRequest(payload(['protocol_settings' => ['hop_interval' => null]]))->validated()['protocol_settings']['hop_interval'] === null,
        'Null interval was not retained.');
});

scenario('legacy zero interval forms save as null without altering other settings', function (): void {
    foreach ([0, '0'] as $zero) {
        $saved = saveFixture(payload(['protocol_settings' => ['hop_interval' => $zero]]));
        check($saved->protocol_settings['hop_interval'] === null, 'Default zero did not normalize to null.');
        check($saved->protocol_settings['tls']['server_name'] === 'hy2.fixture.test', 'Normalization discarded nested TLS settings.');
    }
});

scenario('legacy malformed database values never produce managed forwarding', function (): void {
    foreach (['0-443', '443-65536', '443-442', '443,444', '443-444,445', '443;evil-444', ['443-444']] as $port) {
        check(!array_key_exists('port_hopping', ServerService::buildNodeConfig(new Server(payload(['port' => $port])))),
            'Unsafe legacy range produced privileged rule input.');
    }
    foreach (['443-444', 0, 65536, '443foo'] as $listen) {
        check(!array_key_exists('port_hopping', ServerService::buildNodeConfig(new Server(payload(['server_port' => $listen])))),
            'Unsafe legacy listener produced privileged rule input.');
    }
});

scenario('GET config and WebSocket sync use identical managed range', function (): void {
    global $node, $redis;
    Cache::put('node_ws_alive:' . $node->id, true);
    $response = getConfig($node);
    check($response->getStatusCode() === 200, 'GET config failed.');
    $get = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $builder = ServerService::buildNodeConfig($node);
    unset($get['base_config']);
    check($get === $builder, 'GET config differs from shared builder.');
    NodeSyncService::notifyConfigUpdated($node->id);
    check(count($redis->messages) === 1 && $redis->messages[0]['event'] === 'sync.config', 'WS configuration publication absent.');
    check($redis->messages[0]['data']['config'] === $builder, 'WS config differs from GET/shared builder.');
    check(getConfig($node, $response->headers->get('ETag'))->getStatusCode() === 304, 'Unchanged GET config did not return 304.');
});

scenario('changing only HY2 port range pushes after commit and invalidates GET ETag', function (): void {
    global $node, $redis;
    $etag = getConfig($node)->headers->get('ETag');
    DB::beginTransaction();
    $node->update(['port' => '42500-42503']);
    check($redis->messages === [], 'Forwarding configuration escaped before commit.');
    DB::commit();
    check(count($redis->messages) === 1 && $redis->messages[0]['transaction_level'] === 0, 'Committed port change was not pushed.');
    check($redis->messages[0]['data']['config']['port_hopping'] === ['start' => 42500, 'end' => 42503], 'WS contains stale range.');
    $response = getConfig($node, $etag);
    check($response->getStatusCode() === 200 && $response->headers->get('ETag') !== $etag, 'GET kept the previous range ETag.');
});

scenario('clearing range pushes field absence and rollback publishes nothing', function (): void {
    global $node, $redis;
    DB::beginTransaction();
    $node->update(['port' => '42600-42603']);
    DB::rollBack();
    check($redis->messages === [], 'Rolled-back range was published.');
    $node->refresh();
    $node->update(['port' => '42443']);
    check(count($redis->messages) === 1 && !array_key_exists('port_hopping', $redis->messages[0]['data']['config']),
        'Disabling the range did not publish field absence.');
});

scenario('other protocols retain their validation and observer behavior', function (): void {
    global $redis;
    $hy1 = payload(['protocol_settings' => ['version' => 1, 'hop_interval' => -1], 'port' => '443,444']);
    check(validatedRequest($hy1)->validated()['protocol_settings']['hop_interval'] === -1, 'Hysteria1 rules changed.');
    $socks = payload(['type' => 'socks', 'port' => '443,444', 'server_port' => '443-444', 'protocol_settings' => ['tls' => 0]]);
    $other = saveFixture($socks);
    check(!array_key_exists('port_hopping', ServerService::buildNodeConfig($other)), 'Another protocol received forwarding data.');
    Cache::put('node_ws_alive:' . $other->id, true);
    $other->update(['port' => '444,445']);
    check($redis->messages === [], 'An unrelated protocol port change gained a notification.');
});

echo "PASS {$passed} Hysteria2 forwarding scenarios\n";
