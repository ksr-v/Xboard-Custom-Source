<?php
declare(strict_types=1);

// Offline behavior regression. No Laravel bootstrap, Redis, or child processes.
// php tests/Queue/fixed-data-pipeline.php (optional XBOARD_TEST_VENDOR override).
$sourceRoot = dirname(__DIR__, 2);
$vendor = getenv('XBOARD_TEST_VENDOR') ?: $sourceRoot . '/vendor/autoload.php';
if (!is_file($vendor)) {
    throw new RuntimeException('Set XBOARD_TEST_VENDOR to a local Composer vendor/autoload.php.');
}

// Only configuration loading uses this deterministic test environment.
$queueTestEnvironment = [];
function env($key, $default = null)
{
    return $GLOBALS['queueTestEnvironment'][$key] ?? $default;
}
// A shared dependency checkout may refer to another application's helper file.
// This test needs vendor code only; never include project-level autoload files.
$vendorDirectory = str_replace('\\', '/', dirname((string) realpath($vendor))) . '/';
foreach (require dirname($vendor) . '/composer/autoload_files.php' as $identifier => $file) {
    if (!str_starts_with(str_replace('\\', '/', $file), $vendorDirectory)) {
        $GLOBALS['__composer_autoload_files'][$identifier] = true;
    }
}
require $vendor;

use Illuminate\Support\Collection;
use Laravel\Horizon\{AutoScaler, ProcessPool, Supervisor, SupervisorOptions, WorkerProcess};

function queueCheck(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

final class QueueTestProcess
{
    public bool $running = true;
    public function isRunning(): bool { return $this->running; }
}

final class QueueTestWorker extends WorkerProcess
{
    public function __construct(public readonly int $identity)
    {
        parent::__construct(new QueueTestProcess());
    }
    public function terminate(): void { $this->process->running = false; }
    public function stop(): void { $this->process->running = false; }
}

// Only the OS process boundary is replaced. Scale-up/down and FIFO selection
// remain the installed Horizon ProcessPool implementation.
final class QueueTestPool extends ProcessPool
{
    public static int $created = 0;
    protected function createProcess(): QueueTestWorker
    {
        return new QueueTestWorker(++self::$created);
    }
}

final class QueueTestConnection
{
    public array $sizes = [];
    public function readyNow(string $queue): int { return $this->sizes[$queue] ?? 0; }
}

final class QueueTestFactory
{
    public function __construct(public readonly QueueTestConnection $queue) {}
    public function connection($name): QueueTestConnection { return $this->queue; }
}

final class QueueTestMetrics
{
    public function runtimeForQueue($queue): int { return 20; }
}

function queueConfiguration(array $environment = []): array
{
    $GLOBALS['queueTestEnvironment'] = $environment;
    return (require $GLOBALS['sourceRoot'] . '/config/horizon.php')['environments']['production']['data-pipeline'];
}

function queueFixture(array $configuration): array
{
    $options = new SupervisorOptions(
        name: 'offline:data-pipeline',
        connection: $configuration['connection'],
        queue: implode(',', $configuration['queue']),
        balance: $configuration['balance'],
        maxProcesses: $configuration['maxProcesses'],
        minProcesses: $configuration['minProcesses'],
        autoScalingStrategy: $configuration['autoScalingStrategy'] ?? 'time',
    );
    // Avoid Supervisor's constructor, which flushes its Redis command queue.
    $supervisor = (new ReflectionClass(Supervisor::class))->newInstanceWithoutConstructor();
    $supervisor->name = $options->name;
    $supervisor->options = $options;
    $supervisor->processPools = new Collection(array_map(
        static fn (string $queue): QueueTestPool => new QueueTestPool($options->withQueue($queue)),
        $configuration['queue'],
    ));
    foreach ($supervisor->processPools as $pool) { $pool->scale(1); }

    // AutoScaler's algorithms run unchanged; only queue/metric I/O is in memory.
    $connection = new QueueTestConnection();
    $scaler = (new ReflectionClass(AutoScaler::class))->newInstanceWithoutConstructor();
    $scaler->queue = new QueueTestFactory($connection);
    $scaler->metrics = new QueueTestMetrics();
    return [$supervisor, $scaler, $connection];
}

function queueIdentities(Supervisor $supervisor): array
{
    $identities = [];
    foreach ($supervisor->processPools as $pool) {
        $identities[$pool->queue()] = array_map(
            static fn (QueueTestWorker $worker): int => $worker->identity,
            $pool->processes()->all(),
        );
    }
    return $identities;
}

function exerciseQueueBursts(Supervisor $supervisor, AutoScaler $scaler, QueueTestConnection $connection): void
{
    $warmIdentities = queueIdentities($supervisor);
    $createdBefore = QueueTestPool::$created;
    $bursts = [[], ['traffic_fetch' => 1000], [], ['stat' => 2000], [],
        ['traffic_fetch' => 500, 'stat' => 700, 'user_alive_sync' => 100], []];
    foreach ($bursts as $sizes) {
        $connection->sizes = $sizes;
        for ($cycle = 0; $cycle < 4; $cycle++) { $scaler->scale($supervisor); }
        queueCheck(queueIdentities($supervisor) === $warmIdentities, 'A queue burst replaced warm workers.');
    }
    queueCheck(QueueTestPool::$created === $createdBefore, 'A queue burst created extra workers.');
}

[$supervisor, $scaler, $connection] = queueFixture(queueConfiguration());
exerciseQueueBursts($supervisor, $scaler, $connection);
queueCheck(array_map('count', queueIdentities($supervisor)) === [
    'traffic_fetch' => 1, 'stat' => 1, 'user_alive_sync' => 1,
], 'The default must keep one warm worker for every data-pipeline queue.');
echo "PASS default configuration keeps all three workers across idle/busy cycles\n";

[$supervisor, $scaler, $connection] = queueFixture(queueConfiguration(['HORIZON_DATA_PIPELINE_MAX' => '6']));
for ($cycle = 0; $cycle < 4; $cycle++) { $scaler->scale($supervisor); }
queueCheck(array_map('count', queueIdentities($supervisor)) === [
    'traffic_fetch' => 2, 'stat' => 2, 'user_alive_sync' => 2,
], 'An explicit limit of six must allocate two workers per queue.');
exerciseQueueBursts($supervisor, $scaler, $connection);
echo "PASS explicit limit six keeps two workers per queue across idle/busy cycles\n";

// Positive control: the old auto/time policy really churns these same fake OS
// processes, so a broken simulation cannot make the fixed-policy cases pass.
$oldPolicy = queueConfiguration();
$oldPolicy['balance'] = 'auto';
$oldPolicy['autoScalingStrategy'] = 'time';
$oldPolicy['maxProcesses'] = 8;
[$supervisor, $scaler, $connection] = queueFixture($oldPolicy);
$oldIdentities = queueIdentities($supervisor);
$createdBefore = QueueTestPool::$created;
$connection->sizes = ['traffic_fetch' => 1000, 'stat' => 1000];
for ($cycle = 0; $cycle < 5; $cycle++) { $scaler->scale($supervisor); }
$connection->sizes = [];
for ($cycle = 0; $cycle < 8; $cycle++) { $scaler->scale($supervisor); }
queueCheck(QueueTestPool::$created > $createdBefore, 'Positive control did not create workers.');
queueCheck(queueIdentities($supervisor) !== $oldIdentities, 'Positive control did not replace the oldest workers.');
echo "PASS old auto policy reproduces worker creation and replacement\n";
echo "PASS 3 offline Horizon allocation scenarios; no Redis or child processes\n";
