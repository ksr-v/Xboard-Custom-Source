<?php
declare(strict_types=1);

// php tests/RateRoute/synchronization.php (optional XBOARD_TEST_VENDOR override).
// Only an in-memory SQLite database and synthetic identities are used.
$sourceRoot = dirname(__DIR__, 2);
$vendor = getenv('XBOARD_TEST_VENDOR') ?: $sourceRoot . '/vendor/autoload.php';
if (!is_file($vendor)) {
    throw new RuntimeException('Set XBOARD_TEST_VENDOR to a local Composer vendor/autoload.php.');
}
$loader = require $vendor;
$loader->setPsr4('App\\', [$sourceRoot . '/app']);
// A shared optimized vendor may contain App classmap entries from another checkout.
foreach ($loader->getClassMap() as $class => $path) {
    if (str_starts_with($class, 'App\\')) {
        $localPath = $sourceRoot . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($localPath)) {
            $loader->addClassMap([$class => $localPath]);
        }
    }
}

use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Requests\Admin\{PlanSave, UserUpdate};
use App\Models\{Plan, Server, ServerRoute, User};
use App\Observers\{PlanObserver, ServerObserver, ServerRouteObserver, UserObserver};
use App\Services\ServerService;
use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\{Cache, DB, Facade, Redis, Schema, Validator};

final class RecordingRateRouteBus extends Illuminate\Bus\Dispatcher
{
    public array $queued = [];
    public array $synchronous = [];

    public function dispatch($command)
    {
        $this->queued[] = $command;
        return $command;
    }

    public function dispatchSync($command, $handler = null)
    {
        $this->synchronous[] = $command;
        return parent::dispatchSync($command, $handler);
    }
}

final class RecordingRateRouteRedis
{
    public array $messages = [];

    public function publish(string $channel, string $payload): int
    {
        if ($channel !== 'node:push') {
            throw new RuntimeException('Unexpected test publish channel.');
        }
        $this->messages[] = [
            'transaction_level' => DB::transactionLevel(),
            ...json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
        ];
        return 1;
    }
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function scenario(string $name, callable $action): void
{
    global $passed, $redis;
    $redis->messages = [];
    $action();
    $passed++;
    echo "PASS {$name}\n";
}

function saveFixturePlan(Plan $plan, array $changes, bool $force = true): void
{
    $request = PlanSave::create('/fixture/plan', 'POST', [
        'id' => $plan->id, 'name' => $plan->name, 'group_id' => $plan->group_id,
        'transfer_enable' => 2, 'speed_limit' => $plan->speed_limit,
        'device_limit' => $plan->device_limit, 'force_update' => $force,
        ...$changes,
    ]);
    $validator = Validator::make($request->all(), $request->rules());
    verify($validator->passes(), 'Invalid synthetic plan request.');
    $request->setValidator($validator);
    $response = (new PlanController())->save($request);
    verify($response->getStatusCode() === 200 && $response->getData(true)['data'] === true,
        'Plan controller did not accept the fixture update.');
}

$app = new Application($sourceRoot);
$app->instance('config', new Repository([
    'app' => ['env' => 'testing', 'key' => 'base64:' . base64_encode(str_repeat('t', 32))],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
]));
Facade::setFacadeApplication($app);
foreach ([Illuminate\Filesystem\FilesystemServiceProvider::class,
    Illuminate\Database\DatabaseServiceProvider::class, Illuminate\Cache\CacheServiceProvider::class,
    Illuminate\Translation\TranslationServiceProvider::class, Illuminate\Validation\ValidationServiceProvider::class,
    Illuminate\Queue\QueueServiceProvider::class] as $provider) {
    $app->register($provider);
}
$app->boot();
$redis = new RecordingRateRouteRedis();
Redis::swap($redis);
// The controller response factory has no database, queue or network behavior.
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, new class extends Illuminate\Routing\ResponseFactory {
    public function __construct() { /* JSON responses need neither views nor redirects. */ }
});
$bus = new RecordingRateRouteBus($app, fn ($connection = null) => $app['queue']->connection($connection));
$app->instance(Illuminate\Contracts\Bus\Dispatcher::class, $bus);
$app->instance(Illuminate\Contracts\Bus\QueueingDispatcher::class, $bus);

Schema::create('v2_server_route', function (Blueprint $table): void {
    $table->id(); $table->text('match'); $table->string('action'); $table->text('action_value')->nullable();
    $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
});
Schema::create('v2_server', function (Blueprint $table): void {
    $table->id(); $table->string('name'); $table->string('type'); $table->string('host');
    $table->integer('port'); $table->integer('server_port'); $table->text('protocol_settings');
    $table->text('group_ids'); $table->text('route_ids'); $table->boolean('enabled'); $table->boolean('show');
    $table->integer('machine_id')->nullable(); $table->text('custom_outbounds')->nullable();
    $table->text('custom_routes')->nullable(); $table->text('cert_config')->nullable();
    $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
});
Schema::create('v2_user', function (Blueprint $table): void {
    $table->id(); $table->string('email'); $table->string('uuid'); $table->integer('group_id');
    $table->integer('speed_limit')->nullable(); $table->integer('device_limit')->nullable();
    $table->bigInteger('transfer_enable'); $table->bigInteger('u'); $table->bigInteger('d');
    $table->boolean('banned'); $table->integer('expired_at')->nullable(); $table->integer('plan_id')->nullable();
    $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
});
Schema::create('v2_plan', function (Blueprint $table): void {
    $table->id(); $table->string('name'); $table->integer('group_id')->nullable();
    $table->integer('transfer_enable'); $table->integer('speed_limit')->nullable();
    $table->integer('device_limit')->nullable(); $table->integer('reset_traffic_method')->nullable();
    $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
});

$first = ServerRoute::create(['match' => ['full:first.fixture.test'], 'action' => 'direct']);
$second = ServerRoute::create(['match' => ['full:second.fixture.test'], 'action' => 'block']);
$servers = [];
foreach (['visible' => [true, true], 'hidden' => [true, false], 'disabled' => [false, true],
    'unrelated' => [true, true]] as $name => [$enabled, $show]) {
    $servers[$name] = Server::create([
        'name' => $name, 'type' => 'socks', 'host' => '127.0.0.1', 'port' => 12345,
        'server_port' => 12345, 'protocol_settings' => [], 'group_ids' => ['1'],
        'route_ids' => $name === 'unrelated' ? [] : [$second->id, $first->id],
        'enabled' => $enabled, 'show' => $show,
    ]);
    Cache::put('node_ws_alive:' . $servers[$name]->id, true);
}
$user = User::create([
    'email' => 'rate-route@fixture.test', 'uuid' => '00000000-0000-4000-8000-000000000001',
    'group_id' => 1, 'speed_limit' => 0, 'device_limit' => 0, 'transfer_enable' => 1073741824,
    'u' => 0, 'd' => 0, 'banned' => false, 'expired_at' => null, 'plan_id' => 1,
]);
ServerRoute::observe(ServerRouteObserver::class);
Server::observe(ServerObserver::class);
User::observe(UserObserver::class);
Plan::observe(PlanObserver::class);
$passed = 0;

scenario('selected route priority survives SQL order, missing IDs and duplicates', function () use ($first, $second): void {
    $routes = ServerService::getRoutes([(string) $second->id, 999999, $first->id, $second->id]);
    verify($routes->pluck('id')->all() === [$second->id, $first->id], 'Selected route order changed.');
    verify(array_is_list($routes->toArray()), 'Routes must remain a JSON array.');
    verify(ServerService::getRoutes([])->isEmpty(), 'Empty route selection is not empty.');
});

scenario('route save notifies visible and hidden running nodes after commit', function () use ($first, $second, $servers): void {
    global $redis;
    DB::beginTransaction();
    $first->update(['action_value' => 'committed']);
    verify($redis->messages === [], 'Route notification escaped before commit.');
    DB::commit();
    verify(array_column($redis->messages, 'node_id') === [$servers['visible']->id, $servers['hidden']->id],
        'Route update did not target exactly the enabled affected nodes.');
    foreach ($redis->messages as $message) {
        verify($message['event'] === 'sync.config' && $message['transaction_level'] === 0, 'Wrong event or transaction level.');
        verify(array_column($message['data']['config']['routes'], 'id') === [$second->id, $first->id], 'Pushed route priority changed.');
        verify($message['data']['config']['routes'][1]['action_value'] === 'committed', 'Uncommitted/stale route pushed.');
    }
});

scenario('route rollback publishes nothing', function () use ($first): void {
    global $redis;
    DB::beginTransaction();
    $first->update(['action_value' => 'rolled-back']);
    DB::rollBack();
    verify($redis->messages === [], 'Rolled-back route was published.');
    $first->refresh();
});

scenario('node route reordering publishes the new priority before save returns', function () use ($first, $second, $servers): void {
    global $redis;
    $servers['hidden']->update(['route_ids' => [$first->id, $second->id]]);
    verify(count($redis->messages) === 1 && $redis->messages[0]['node_id'] === $servers['hidden']->id,
        'Hidden node route selection was not pushed synchronously.');
    verify(array_column($redis->messages[0]['data']['config']['routes'], 'id') === [$first->id, $second->id],
        'Reordered priority was not pushed.');
});

scenario('negative speed is rejected; zero, positive and null remain valid', function (): void {
    $request = new UserUpdate();
    $rules = array_intersect_key($request->rules(), array_flip(['id', 'speed_limit']));
    foreach ([-1 => false, 0 => true, 8 => true] as $value => $valid) {
        $validator = Validator::make(['id' => 1, 'speed_limit' => $value], $rules);
        verify($validator->passes() === $valid, 'Unexpected limit validation: ' . $value);
    }
    verify(Validator::make(['id' => 1, 'speed_limit' => null], $rules)->passes(), 'Null limit must remain supported.');
    verify(!Validator::make(['id' => 1, 'speed_limit' => 1.5], $rules)->passes(), 'Fractional limit accepted.');
});

scenario('limit enable, change and disable push committed users without a queue worker', function () use ($user): void {
    global $redis, $bus;
    foreach ([8, 16, 0, null] as $value) {
        $redis->messages = [];
        $before = count($bus->synchronous);
        DB::beginTransaction();
        $user->update(['speed_limit' => $value]);
        verify($redis->messages === [], 'Limit notification escaped before commit.');
        DB::commit();
        verify(count($bus->synchronous) === $before + 1 && $bus->queued === [], 'Limit change waited in a queue.');
        verify(count($redis->messages) > 0, 'Limit change did not publish before commit returned.');
        foreach ($redis->messages as $message) {
            verify($message['event'] === 'sync.user.delta' && $message['transaction_level'] === 0, 'Wrong user event or transaction level.');
            verify($message['data']['users'][0]['speed_limit'] === $value, 'Stale speed limit pushed.');
        }
    }
});

scenario('limit rollback publishes and dispatches nothing', function () use ($user): void {
    global $redis, $bus;
    $before = count($bus->synchronous);
    DB::beginTransaction();
    $user->update(['speed_limit' => 32]);
    DB::rollBack();
    verify($redis->messages === [] && count($bus->synchronous) === $before, 'Rolled-back limit was dispatched.');
    $user->refresh();
});

scenario('device limit is immediate while other user changes retain the async path', function () use ($user): void {
    global $redis, $bus;
    $before = count($bus->synchronous);
    $user->update(['device_limit' => 2]);
    verify(count($bus->synchronous) === $before + 1 && count($redis->messages) > 0, 'Device limit was not immediate.');
    $redis->messages = [];
    $user->update(['u' => 100]);
    verify(count($bus->queued) === 1 && $redis->messages === [], 'Ordinary usage update changed its async behavior.');
});

scenario('route deletion synchronously removes its config on running nodes', function () use ($first, $servers): void {
    global $redis;
    $first->delete();
    verify(array_column($redis->messages, 'node_id') === [$servers['visible']->id, $servers['hidden']->id],
        'Route deletion omitted enabled hidden nodes.');
    foreach ($redis->messages as $message) {
        verify(!in_array($first->id, array_column($message['data']['config']['routes'], 'id'), true), 'Deleted route remains in config.');
    }
});

Plan::create(['name' => 'existing unrelated fixture', 'group_id' => 1,
    'transfer_enable' => 1, 'speed_limit' => 0, 'device_limit' => 0]);
$plan = Plan::create(['name' => 'force-update fixture', 'group_id' => 20,
    'transfer_enable' => 2, 'speed_limit' => 0, 'device_limit' => 0]);
$planUsers = [];
foreach ([1, 2] as $number) {
    $planUsers[] = User::withoutEvents(fn () => User::create([
        'email' => "force-update-{$number}@fixture.test",
        'uuid' => sprintf('00000000-0000-4000-8000-%012d', 10 + $number),
        'group_id' => 20, 'speed_limit' => 0, 'device_limit' => 0,
        'transfer_enable' => 1073741824, 'u' => 0, 'd' => 0,
        'banned' => false, 'expired_at' => null, 'plan_id' => $plan->id,
    ]));
}
$planNodes = [];
foreach ([20, 21, 22] as $groupId) {
    $planNodes[$groupId] = Server::withoutEvents(fn () => Server::create([
        'name' => "plan-group-{$groupId}", 'type' => 'socks', 'host' => '127.0.0.1',
        'port' => 12345, 'server_port' => 12345, 'protocol_settings' => [],
        'group_ids' => [(string) $groupId], 'route_ids' => [], 'enabled' => true, 'show' => false,
    ]));
    Cache::put('node_ws_alive:' . $planNodes[$groupId]->id, true);
}

scenario('plan force update publishes every changed user before returning without a queue worker',
    function () use ($plan, $planNodes, $planUsers): void {
        global $redis, $bus;
        $before = count($bus->queued);
        saveFixturePlan($plan, ['speed_limit' => 8, 'device_limit' => 2]);
        verify(count($bus->queued) === $before, 'Plan update waited in a queue.');
        verify(count($redis->messages) === 1 && $redis->messages[0]['node_id'] === $planNodes[20]->id,
            'Plan limit update did not target its running hidden node.');
        $message = $redis->messages[0];
        verify($message['event'] === 'sync.users' && $message['transaction_level'] === 0,
            'Plan limits published before commit or with an incorrect event.');
        verify(array_column($message['data']['users'], 'id') === array_column($planUsers, 'id'),
            'Plan update omitted affected users.');
        foreach ($message['data']['users'] as $pushedUser) {
            verify($pushedUser['speed_limit'] === 8 && $pushedUser['device_limit'] === 2,
                'Plan update pushed stale limits.');
        }
        $plan->refresh();
    });

scenario('plan force update synchronizes both old and new permission groups',
    function () use ($plan, $planNodes, $planUsers): void {
        global $redis;
        saveFixturePlan($plan, ['group_id' => 21, 'speed_limit' => 16]);
        verify(array_column($redis->messages, 'node_id') === [$planNodes[20]->id, $planNodes[21]->id],
            'Plan group migration omitted old or new nodes, or targeted an unrelated group.');
        verify($redis->messages[0]['data']['users'] === [], 'Moved users remain on the old permission group.');
        verify(array_column($redis->messages[1]['data']['users'], 'id') === array_column($planUsers, 'id'),
            'Moved users are absent from the new permission group.');
        foreach ($redis->messages as $message) {
            verify($message['event'] === 'sync.users' && $message['transaction_level'] === 0,
                'Plan group migration was published before commit.');
        }
        $plan->refresh();
    });

scenario('nested plan force update waits for the outer transaction and rollback publishes nothing',
    function () use ($plan, $planUsers): void {
        global $redis;
        DB::beginTransaction();
        saveFixturePlan($plan, ['group_id' => 20, 'speed_limit' => 32]);
        verify($redis->messages === [], 'Plan update escaped before the outer commit.');
        DB::rollBack();
        verify($redis->messages === [] && $plan->fresh()->group_id === 21,
            'Rolled-back plan update published or persisted a group change.');
        foreach ($planUsers as $planUser) {
            $planUser->refresh();
            verify($planUser->group_id === 21 && $planUser->speed_limit === 16,
                'Rolled-back plan update persisted user changes.');
        }
        $plan->refresh();
    });

scenario('plan save without force update leaves existing users and node pushes unchanged',
    function () use ($plan, $planUsers): void {
        global $redis;
        saveFixturePlan($plan, ['group_id' => 20, 'speed_limit' => 32], false);
        verify($redis->messages === [], 'Non-forced plan save pushed user changes.');
        foreach ($planUsers as $planUser) {
            $planUser->refresh();
            verify($planUser->group_id === 21 && $planUser->speed_limit === 16,
                'Non-forced plan save changed existing users.');
        }
    });

echo "PASS {$passed} rate/route synchronization scenarios\n";
