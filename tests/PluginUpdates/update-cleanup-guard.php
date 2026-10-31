<?php
declare(strict_types=1);
$GLOBALS['__composer_autoload_files']['a03e85e827f2f53ca6b173cf8d947ac6'] = true;
require dirname(__DIR__) . '/BundledPlugins/bootstrap.php';

use App\Models\Plugin;
use App\Services\Plugin\{BundledPluginInstaller, PluginManager, PluginUpdateCleanupGuard};

function removeGuardTree(string $path): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
    rmdir($path);
}
function guardFixture(string $name): array {
    $root = fixtureRoot('cleanup-guard-' . $name);
    $source = $root . '/storage/qrcodeextend'; mkdir($source . '/backups/empty', 0700, true);
    file_put_contents($source . '/operator.json', '{"private":"operator-canary"}');
    file_put_contents($source . '/backups/original.js', 'private original admin asset');
    return [$root, $source, new PluginUpdateCleanupGuard($root)];
}
function guardBackup(string $root): string {
    $snapshots = glob($root . '/storage/app/private/plugin-update-cleanup/*/snapshot.json');
    check(count($snapshots) === 1, 'Exactly one retained private backup was expected');
    return dirname($snapshots[0]) . '/data';
}
test('update cleanup removes old public integration and restores all private QR files and empty directories', function (): void {
    [$root, $source, $guard] = guardFixture('success');
    mkdir($root . '/public/plugins', 0700, true); file_put_contents($root . '/public/plugins/old-qr.js', 'old integration');
    check($guard->run('qrcodeextend', function () use ($source, $root): bool {
        removeGuardTree($source); unlink($root . '/public/plugins/old-qr.js'); return true;
    }) === true, 'Cleanup return value changed');
    check(file_get_contents($source . '/operator.json') === '{"private":"operator-canary"}' && is_dir($source . '/backups/empty'), 'Private storage was lost');
    check(!is_file($root . '/public/plugins/old-qr.js'), 'Old public integration was retained');
    $backup = guardBackup($root);
    check(file_get_contents($backup . '/backups/original.js') === 'private original admin asset', 'Recovery backup was moved/deleted');
    if (DIRECTORY_SEPARATOR === '/') { check((fileperms($backup) & 0777) === 0700 && (fileperms($backup . '/operator.json') & 0777) === 0600, 'Backup permissions are not private'); }
});
test('failed ordinary cleanup still restores private data and retains the original exception', function (): void {
    [$root, $source, $guard] = guardFixture('failure');
    expectFailure(fn () => $guard->run('qrcodeextend', function () use ($source): void { removeGuardTree($source); throw new RuntimeException('fixture cleanup failure'); }), 'fixture cleanup failure');
    check(file_get_contents($source . '/operator.json') === '{"private":"operator-canary"}', 'Failure lost private data'); guardBackup($root);
});
test('concurrent conflicting private data is never overwritten', function (): void {
    [$root, $source, $guard] = guardFixture('conflict');
    expectFailure(fn () => $guard->run('qrcodeextend', function () use ($source): void {
        removeGuardTree($source); mkdir($source, 0700); file_put_contents($source . '/operator.json', 'new concurrent data');
    }), 'conflicting data');
    check(file_get_contents($source . '/operator.json') === 'new concurrent data', 'Concurrent data was overwritten');
    check(file_get_contents(guardBackup($root) . '/operator.json') === '{"private":"operator-canary"}', 'Original conflicting data has no recovery backup');
});
test('nonconflicting private files created by cleanup survive restoration', function (): void {
    [$root, $source, $guard] = guardFixture('merge');
    $guard->run('qrcodeextend', function () use ($source): void { removeGuardTree($source); mkdir($source, 0700); file_put_contents($source . '/new.json', 'new data'); });
    check(file_get_contents($source . '/new.json') === 'new data' && file_get_contents($source . '/backups/original.js') === 'private original admin asset', 'Safe merge lost files');
});
test('QR storage file instead of directory refuses cleanup before flags can change', function (): void {
    $root = fixtureRoot('cleanup-guard-file'); file_put_contents($root . '/storage/qrcodeextend', 'canary'); $called = false;
    expectFailure(fn () => (new PluginUpdateCleanupGuard($root))->run('qrcodeextend', function () use (&$called): void { $called = true; }), 'regular directory');
    check(!$called, 'Unsafe cleanup started');
});
test('other plugin cleanup and empty QR installations retain their ordinary behavior', function (): void {
    $root = fixtureRoot('cleanup-guard-other'); $calls = [];
    $guard = new PluginUpdateCleanupGuard($root);
    $guard->run('node_endpoint', function () use (&$calls): void { $calls[] = 'node'; });
    $guard->run('qrcodeextend', function () use (&$calls): void { $calls[] = 'qr'; });
    check($calls === ['node', 'qr'] && !is_dir($root . '/storage/app/private/plugin-update-cleanup'), 'Ordinary empty cleanup changed');
});
test('PluginManager update-only entry wraps ordinary disable but direct disable remains destructive', function (): void {
    [$root, $source] = guardFixture('manager'); bootFixture($root);
    $manager = new class extends PluginManager {
        public int $calls = 0;
        public function disable(string $code): bool { $this->calls++; removeGuardTree(storage_path('qrcodeextend')); return true; }
    };
    $manager->disableForUpdate('qrcodeextend');
    check($manager->calls === 1 && is_file($source . '/operator.json'), 'Manager update-only entry lost QR data');
    $manager->disable('qrcodeextend');
    check($manager->calls === 2 && !file_exists($source), 'Ordinary disable behavior changed');
});
test('symlink private members are rejected before cleanup when platform permits creating them', function (): void {
    [$root, $source, $guard] = guardFixture('symlink');
    if (!@symlink($source . '/operator.json', $source . '/link.json')) { echo "SKIP symlink creation unavailable\n"; return; }
    $called = false;
    expectFailure(fn () => $guard->run('qrcodeextend', function () use (&$called): void { $called = true; }), 'link or special');
    check(!$called, 'Symlink cleanup started');
});
test('real QR version upgrades preserve private storage, enabled/config choices and disabled-after-update cleanup', function (): void {
    global $sourceRoot;
    [$root, $source] = guardFixture('real-manager');
    copyFixture($sourceRoot . '/public/assets/admin', $root . '/public/assets/admin');
    copy($sourceRoot . '/resources/views/admin.blade.php', $root . '/resources/views/admin.blade.php');
    $installer = new BundledPluginInstaller($root); $installer->stage(); bootFixture($root);
    $manifest = json_decode(file_get_contents($root . '/bundled-plugins/manifest.json'), true);
    foreach ($manifest['plugins'] as $item) {
        Plugin::create(['code' => $item['code'], 'name' => $item['code'],
            'version' => $item['code'] === 'qrcodeextend' ? '1.2.0' : $item['version'],
            'is_enabled' => $item['code'] === 'qrcodeextend', 'config' => '{"operator_setting":"preserve"}']);
    }
    $manager = app(PluginManager::class);
    $manager->update('qrcodeextend');
    check(Plugin::where('code', 'qrcodeextend')->value('version') === '1.3.1'
        && Plugin::where('code', 'qrcodeextend')->value('is_enabled') === true, 'Real enabled upgrade did not retain version/choice');
    check(file_get_contents($source . '/operator.json') === '{"private":"operator-canary"}', 'Real enabled upgrade deleted private data');
    check(str_contains(file_get_contents($root . '/public/assets/admin/assets/index-CEIYH7i8.js'), 'qrcodeextend:start:v5'), 'Real enabled upgrade did not reapply current bridge');
    // Exercise the ordinary bundled disabled-after-upgrade cleanup, which
    // removes update() integration while retaining the administrator choice.
    Plugin::where('code', 'qrcodeextend')->update(['version' => '1.2.0', 'is_enabled' => false]);
    $installer->synchronize();
    check(Plugin::where('code', 'qrcodeextend')->value('version') === '1.3.1'
        && Plugin::where('code', 'qrcodeextend')->value('is_enabled') === false, 'Disabled bundled upgrade changed the saved choice');
    check(Plugin::where('code', 'qrcodeextend')->value('config') === '{"operator_setting":"preserve"}', 'QR configuration was changed');
    check(file_get_contents($source . '/operator.json') === '{"private":"operator-canary"}', 'Disabled upgrade cleanup deleted private data');
    check(!str_contains(file_get_contents($root . '/public/assets/admin/assets/index-CEIYH7i8.js'), 'qrcodeextend:start:v5')
        && !is_dir($root . '/public/plugins/qrcodeextend'), 'Disabled upgrade left integration/assets enabled');
});
echo "PASS {$passed} update-cleanup guard checks\n";
