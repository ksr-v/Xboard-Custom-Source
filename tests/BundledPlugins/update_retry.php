<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Services\{PrivateArchiveUpdateService, UpdateService};
use Illuminate\Support\Facades\{Cache, File, Http, Process};

$root = fixtureRoot('archive-retry');
$app = bootFixture($root);
config()->set('orphan.xboard_update_mode', 'archive');
config()->set('orphan.xboard_update_base_url', 'https://updates.example.test/xboard');
config()->set('orphan.xboard_update_token', '');
$marker = $root . '/storage/app/private/xboard-build.json';
$oldMarker = json_encode(['commit' => str_repeat('1', 40), 'version' => '20261017-1111111']);
file_put_contents($marker, $oldMarker);
chmod($marker, 0600);
Cache::forever(UpdateService::CACHE_VERSION, '1111111');
Cache::forever(UpdateService::CACHE_VERSION_DATE, '20261017');
Cache::put(UpdateService::CACHE_UPDATE_INFO, ['has_update' => true, 'fixture' => 'old cached view'], 3600);
Cache::put(UpdateService::CACHE_LAST_CHECK, 123, 3600);
Http::fake(['updates.example.test/xboard/latest.json' => Http::response([
    'version' => '20261018-2222222', 'commit' => str_repeat('2', 40), 'sha256' => str_repeat('a', 64),
    'size' => 120, 'published_at' => '2026-10-18T00:00:00Z', 'author' => 'Fixture maintainer',
    'message' => 'Verified independent fixture release',
])]);

// Replace only archive byte delivery. Real update discovery, orchestration,
// marker writes and cache handling remain under test.
$archive = new class extends PrivateArchiveUpdateService {
    public array $markerFlags = [];
    public function applyUpdate(array $info, bool $writeBuildMarker = true): void {
        $this->markerFlags[] = $writeBuildMarker;
        if ($writeBuildMarker) { $this->completeUpdate($info); }
    }
};
$app->instance(PrivateArchiveUpdateService::class, $archive);
$updater = new class extends UpdateService {
    public bool $fail = true;
    public ?string $markerAtChildStart = null;
    protected function backupDatabase(): void {}
    protected function runMigrations(): void {
        $this->markerAtChildStart = json_decode(File::get(storage_path('app/private/xboard-build.json')), true)['commit'];
        // A bundled-plugin CLI can write build.json's candidate marker before
        // a later application step fails. The parent must restore this too.
        app(PrivateArchiveUpdateService::class)->completeUpdate([
            'archive_commit' => str_repeat('2', 40), 'archive_version' => '20261018-2222222',
        ]);
        Cache::forever(self::CACHE_VERSION, '2222222');
        Cache::forever(self::CACHE_VERSION_DATE, '20261018');
        if ($this->fail) { throw new RuntimeException('fixture plugin synchronization failure'); }
    }
    protected function clearCache(): void { Cache::flush(); }
    protected function createUpdateFlag(): void {}
    protected function restartOctane(): void {}
};

test('failed child activation restores old marker and version cache and remains retryable', function () use ($updater, $archive, $marker, $oldMarker): void {
    $result = $updater->executeUpdate();
    check($result['success'] === false, 'Activation failure was reported as successful');
    check($archive->markerFlags === [false], 'Web archive delivery committed its version before activation');
    check($updater->markerAtChildStart === str_repeat('1', 40), 'Candidate marker was visible before the child activation');
    check(file_get_contents($marker) === $oldMarker, 'Failed activation kept the candidate build marker');
    check(Cache::get(UpdateService::CACHE_VERSION) === '1111111', 'Candidate version cache survived failure');
    check(Cache::get(UpdateService::CACHE_VERSION_DATE) === '20261017', 'Candidate version date survived failure');
    check(Cache::get(UpdateService::CACHE_UPDATE_INFO)['fixture'] === 'old cached view', 'Previous update view was not restored');
    check(Cache::get(UpdateService::CACHE_LAST_CHECK) === 123, 'Previous check state was not restored');
    $discovery = $updater->checkForUpdates();
    check($discovery['has_update'] && $discovery['current_version'] === '1111111', 'Failed release cannot be retried through the update API');
    check(!Cache::has(UpdateService::CACHE_UPDATE_LOCK), 'Failure left the update locked');
});

test('retry commits candidate marker and archive version cache only after activation succeeds', function () use ($updater, $archive, $marker): void {
    $updater->fail = false;
    $result = $updater->executeUpdate();
    check($result['success'] === true, 'Retry did not finish');
    check($archive->markerFlags === [false, false], 'Retry wrote an early archive marker');
    check(json_decode(file_get_contents($marker), true)['commit'] === str_repeat('2', 40), 'Successful retry did not commit marker');
    check(Cache::get(UpdateService::CACHE_VERSION) === '2222222', 'Archive cache was replaced by the checkout Git version');
    check(Cache::get(UpdateService::CACHE_VERSION_DATE) === '20261018', 'Archive release date was lost');
    check($updater->checkForUpdates()['has_update'] === false, 'Completed release still appears pending');
});

test('invalid completion metadata cannot overwrite the committed marker', function () use ($archive, $marker): void {
    $before = file_get_contents($marker);
    expectFailure(fn () => $archive->completeUpdate(['archive_commit' => 'invalid', 'archive_version' => '../release']), 'metadata is invalid');
    check(file_get_contents($marker) === $before, 'Invalid metadata overwrote the build marker');
});

test('nonzero fresh xboard:update child still rejects the Web migration phase', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;
        return Process::result('', 'fixture child failed', 19);
    });
    $probe = new class extends UpdateService {
        public function migrate(): void { parent::runMigrations(); }
    };
    expectFailure(fn () => $probe->migrate(), 'update.migration_failed');
    check(count($commands) === 1 && is_array($commands[0]) && in_array('xboard:update', $commands[0], true)
        && in_array('--no-interaction', $commands[0], true), 'Upgrade did not use the checked fresh application command');
});

test('failed database backup aborts before any source update is attempted', function (): void {
    Process::fake(fn () => Process::result('', 'fixture backup failed', 23));
    $probe = new class extends UpdateService {
        public bool $sourceAttempted = false;
        public function checkForUpdates(): array {
            return ['has_update' => true, 'latest_version' => '3333333', 'is_local_newer' => false];
        }
        protected function pullLatestCode(array $info = []): array {
            $this->sourceAttempted = true;
            return ['success' => true];
        }
    };
    $result = $probe->executeUpdate();
    check($result['success'] === false, 'Failed database backup was accepted');
    check(!$probe->sourceAttempted, 'Source update began despite backup failure');
    check(!Cache::has(UpdateService::CACHE_UPDATE_LOCK), 'Backup failure left the upgrade locked');
});

echo json_encode(['result' => 'PASS', 'checks' => $passed, 'scope' => 'Isolated archive version-state recovery and checked application upgrade; no network or production']) . "\n";
(new Illuminate\Filesystem\Filesystem())->deleteDirectory($work);
