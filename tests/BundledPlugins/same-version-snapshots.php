<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Models\Plugin;
use App\Services\Plugin\{BundledPluginInstaller, PluginManager};

/** Build fixture-local hashes; never refresh the shared working-tree manifest. */
function snapshotFixture(string $name, string $snapshot = 'first'): array
{
    $root = fixtureRoot('same-version-' . $name);
    $manifest = json_decode(file_get_contents($root . '/bundled-plugins/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    foreach ($manifest['plugins'] as &$item) {
        $directory = $root . '/bundled-plugins/' . $item['directory'];
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile()) { $files[str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1))] = hash_file('sha256', $entry->getPathname()); }
        }
        ksort($files);
        $item['files'] = $files;
        $item['version'] = json_decode(file_get_contents($directory . '/config.json'), true)['version'];
        if ($item['code'] !== 'smart_expiry') { continue; }
        $first = $second = $files;
        $first['Plugin.php'] = hash('sha256', "<?php // first reviewed snapshot\n");
        $first['retired.txt'] = hash('sha256', 'reviewed retired member');
        $second['Plugin.php'] = hash('sha256', "<?php // second reviewed snapshot\n");
        ksort($first); ksort($second);
        $item['accepted_previous'] = [
            ['version' => $item['version'], 'source_commit' => str_repeat('1', 40), 'files' => $first],
            ['version' => $item['version'], 'source_commit' => str_repeat('2', 40), 'files' => $second],
        ];
    }
    unset($item);
    file_put_contents($root . '/bundled-plugins/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    copyFixture($root . '/bundled-plugins/SmartExpiry', $root . '/plugins/SmartExpiry');
    if ($snapshot !== 'current') {
        file_put_contents($root . '/plugins/SmartExpiry/Plugin.php', "<?php // {$snapshot} reviewed snapshot\n");
        if ($snapshot === 'first') { file_put_contents($root . '/plugins/SmartExpiry/retired.txt', 'reviewed retired member'); }
    }
    file_put_contents($root . '/plugins/SmartExpiry/operator-notes.txt', 'operator-canary');
    return [$root, $manifest];
}

foreach (['first', 'second'] as $snapshot) {
    test('either complete same-version historical snapshot upgrades without losing operator notes: ' . $snapshot, function () use ($snapshot): void {
        [$root] = snapshotFixture($snapshot, $snapshot);
        $installer = new BundledPluginInstaller($root);
        $report = $installer->stage();
        check($report['smart_expiry']['action'] === 'upgraded-files', 'Known same-version snapshot was not upgraded');
        check(hash_file('sha256', $root . '/plugins/SmartExpiry/Plugin.php') === hash_file('sha256', $root . '/bundled-plugins/SmartExpiry/Plugin.php'), 'Latest source was not staged');
        check(!file_exists($root . '/plugins/SmartExpiry/retired.txt'), 'Retired managed member was not removed');
        check(file_get_contents($root . '/plugins/SmartExpiry/operator-notes.txt') === 'operator-canary', 'Operator notes were changed');
        check($installer->stage()['smart_expiry']['action'] === 'unchanged', 'Repeated staging was not idempotent');
    });
}

test('unknown same-version modifications fail before any plugin is staged', function (): void {
    [$root] = snapshotFixture('modified');
    $plugin = $root . '/plugins/SmartExpiry/Plugin.php';
    file_put_contents($plugin, '// unknown change', FILE_APPEND);
    $before = hash_file('sha256', $plugin);
    expectFailure(fn () => (new BundledPluginInstaller($root))->stage(), 'modified files');
    check(hash_file('sha256', $plugin) === $before && count(glob($root . '/plugins/*')) === 1, 'Rejected tree or unrelated plugins were changed');
    check(!file_exists($root . '/storage/app/private/plugin-bundles/ownership.json'), 'Rejected tree was adopted');
});

test('a mixture of known same-version snapshots is rejected', function (): void {
    [$root] = snapshotFixture('mixed', 'second');
    file_put_contents($root . '/plugins/SmartExpiry/retired.txt', 'reviewed retired member');
    expectFailure(fn () => (new BundledPluginInstaller($root))->stage(), 'modified files');
    check(count(glob($root . '/plugins/*')) === 1, 'Mixed tree caused partial staging');
});

test('current same-version source is adopted and preserved on repeat', function (): void {
    [$root] = snapshotFixture('latest', 'current');
    $installer = new BundledPluginInstaller($root);
    check($installer->stage()['smart_expiry']['action'] === 'adopted', 'Current source was not adopted');
    check($installer->stage()['smart_expiry']['action'] === 'unchanged', 'Current source was rewritten on repeat');
});

test('an older bundle preserves verified newer owned same-version source', function (): void {
    [$root] = snapshotFixture('owned-newer', 'current');
    $installer = new BundledPluginInstaller($root);
    $installer->stage();
    $plugin = $root . '/plugins/SmartExpiry/Plugin.php';
    file_put_contents($plugin, "<?php // newer verified online fix\n");
    $ownershipPath = $root . '/storage/app/private/plugin-bundles/ownership.json';
    $ownership = json_decode(file_get_contents($ownershipPath), true, 64, JSON_THROW_ON_ERROR);
    $ownership['plugins']['smart_expiry']['bundle_version'] = '2099.01.01.1';
    $ownership['plugins']['smart_expiry']['files']['Plugin.php'] = hash_file('sha256', $plugin);
    file_put_contents($ownershipPath, json_encode($ownership, JSON_THROW_ON_ERROR));
    $before = hash_file('sha256', $plugin);
    check($installer->stage()['smart_expiry']['action'] === 'preserved-newer', 'Older bundle replaced a newer same-version online update');
    check(hash_file('sha256', $plugin) === $before, 'Newer same-version source was downgraded');
    file_put_contents($plugin, '// unknown local modification', FILE_APPEND);
    expectFailure(fn () => $installer->stage(), 'modified files');
    check(str_ends_with(file_get_contents($plugin), '// unknown local modification'), 'Modified newer source was overwritten');
});

final class SnapshotLifecycleManager extends PluginManager
{
    public array $enabled = [];
    public function enable(string $code): bool { $this->enabled[] = $code; return true; }
    public function update(string $code): bool { throw new RuntimeException('Same-version source refresh must not run version migrations'); }
    public function install(string $code): bool { throw new RuntimeException('Existing plugin must not be installed again'); }
    public function disable(string $code): bool { throw new RuntimeException('Administrator state must not be changed'); }
}
foreach ([false, true] as $enabled) {
    test('same-version lifecycle retains settings and ' . ($enabled ? 'enabled' : 'disabled') . ' choice', function () use ($enabled): void {
        [$root, $manifest] = snapshotFixture($enabled ? 'enabled' : 'disabled');
        $app = bootFixture($root);
        $manager = new SnapshotLifecycleManager();
        $app->instance(PluginManager::class, $manager);
        foreach ($manifest['plugins'] as $item) {
            Plugin::create(['code' => $item['code'], 'name' => $item['code'], 'version' => $item['version'],
                'is_enabled' => $item['code'] === 'smart_expiry' && $enabled,
                'config' => json_encode(['operator_setting' => 'keep-' . $item['code']])]);
        }
        $before = Plugin::orderBy('code')->get()->toArray();
        $report = (new BundledPluginInstaller($root))->synchronize();
        check($report['smart_expiry']['action'] === 'upgraded-files', 'Same-version source fix was not staged');
        check(Plugin::orderBy('code')->get()->toArray() === $before, 'Version, configuration or administrator choice changed');
        check($manager->enabled === ($enabled ? ['smart_expiry'] : []), 'Integration was not reapplied according to the saved enabled choice');
    });
    test('preserved newer same-version lifecycle reapplies only the ' . ($enabled ? 'enabled' : 'disabled') . ' choice', function () use ($enabled): void {
        [$root, $manifest] = snapshotFixture($enabled ? 'newer-enabled' : 'newer-disabled', 'current');
        $installer = new BundledPluginInstaller($root);
        $installer->stage();
        $plugin = $root . '/plugins/SmartExpiry/Plugin.php';
        file_put_contents($plugin, "<?php // verified newer source retained\n");
        $ownershipPath = $root . '/storage/app/private/plugin-bundles/ownership.json';
        $ownership = json_decode(file_get_contents($ownershipPath), true, 64, JSON_THROW_ON_ERROR);
        $ownership['plugins']['smart_expiry']['bundle_version'] = '2099.01.01.1';
        $ownership['plugins']['smart_expiry']['files']['Plugin.php'] = hash_file('sha256', $plugin);
        file_put_contents($ownershipPath, json_encode($ownership, JSON_THROW_ON_ERROR));
        $sourceHash = hash_file('sha256', $plugin);
        $app = bootFixture($root);
        $manager = new SnapshotLifecycleManager();
        $app->instance(PluginManager::class, $manager);
        foreach ($manifest['plugins'] as $item) {
            Plugin::create(['code' => $item['code'], 'name' => $item['code'], 'version' => $item['version'],
                'is_enabled' => $item['code'] === 'smart_expiry' && $enabled,
                'config' => json_encode(['operator_setting' => 'keep-' . $item['code']])]);
        }
        $before = Plugin::orderBy('code')->get()->toArray();
        check($installer->synchronize()['smart_expiry']['action'] === 'preserved-newer', 'Newer same-version source was not preserved');
        check(hash_file('sha256', $plugin) === $sourceHash, 'Preserved source was replaced');
        check(Plugin::orderBy('code')->get()->toArray() === $before, 'Preserved newer plugin settings or state changed');
        check($manager->enabled === ($enabled ? ['smart_expiry'] : []), 'Newer preserved integration did not follow the saved enabled choice');
    });
}
echo "PASS {$passed} same-version snapshot checks\n";
