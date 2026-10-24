<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Models\Plugin;
use App\Services\Plugin\{BundledPluginInstaller, PluginManager};
use Illuminate\Support\Facades\{DB, Schema};

test('source manifest fixes all four versions and QR audit 1.2.0', function (): void {
    global $sourceRoot;
    $manifest = json_decode(file_get_contents($sourceRoot . '/bundled-plugins/manifest.json'), true);
    $versions = array_column($manifest['plugins'], 'version', 'code');
    check($versions === ['node_endpoint' => '1.0.8', 'smart_expiry' => '1.2.0', 'qrcodeextend' => '1.2.0', 'flowscope' => '2.4.0'], 'Unexpected versions');
    foreach (['bootstrap.py', 'flowscope_collector.py', 'install.sh', 'flowscope-collector.service', 'manage.sh'] as $file) {
        check(is_file($sourceRoot . '/bundled-plugins/Flowscope/collector/' . $file), 'Missing collector asset');
    }
});
test('tampered bundle is rejected before any runtime plugin is written', function (): void {
    $root = fixtureRoot('bad-source');
    file_put_contents($root . '/bundled-plugins/NodeEndpoint/Plugin.php', '// tampered', FILE_APPEND);
    expectFailure(fn () => (new BundledPluginInstaller($root))->stage(), 'integrity failed');
    check(count(glob($root . '/plugins/*')) === 0, 'Partial plugin deployment');
});
test('first staging and repeat are idempotent; unrelated plugin remains', function (): void {
    $root = fixtureRoot('stage');
    mkdir($root . '/plugins/Other'); file_put_contents($root . '/plugins/Other/evidence.txt', 'preserve');
    $installer = new BundledPluginInstaller($root);
    $first = $installer->stage(); $second = $installer->stage();
    check(count($first) === 4 && $second['qrcodeextend']['action'] === 'unchanged', 'Idempotency failed');
    check(file_get_contents($root . '/plugins/Other/evidence.txt') === 'preserve', 'Other plugin changed');
    file_put_contents($root . '/plugins/NodeEndpoint/Plugin.php', '// local edit', FILE_APPEND);
    expectFailure(fn () => $installer->stage(), 'modified files');
    check(str_ends_with(file_get_contents($root . '/plugins/NodeEndpoint/Plugin.php'), '// local edit'), 'Local edit overwritten');
});
test('existing exact native plugin is adopted with extra documentation preserved', function (): void {
    $root = fixtureRoot('adopt');
    copyFixture($root . '/bundled-plugins/Qrcodeextend', $root . '/plugins/Qrcodeextend');
    file_put_contents($root . '/plugins/Qrcodeextend/user-notes.txt', 'preserve');
    $report = (new BundledPluginInstaller($root))->stage();
    check($report['qrcodeextend']['action'] === 'adopted', 'Native plugin not adopted');
    check(file_get_contents($root . '/plugins/Qrcodeextend/user-notes.txt') === 'preserve', 'Extra file removed');
});
test('unknown old plugin is refused and newer plugin is preserved', function (): void {
    $root = fixtureRoot('version');
    copyFixture($root . '/bundled-plugins/Qrcodeextend', $root . '/plugins/Qrcodeextend');
    $path = $root . '/plugins/Qrcodeextend/config.json';
    $config = json_decode(file_get_contents($path), true); $config['version'] = '1.0.7'; file_put_contents($path, json_encode($config));
    expectFailure(fn () => (new BundledPluginInstaller($root))->stage(), 'not a verified');
    $config['version'] = '9.0.0'; file_put_contents($path, json_encode($config));
    $report = (new BundledPluginInstaller($root))->stage();
    check($report['qrcodeextend']['action'] === 'preserved-newer', 'Newer plugin downgraded');
    check(json_decode(file_get_contents($path), true)['version'] === '9.0.0', 'Newer source changed');
});

test('a locally modified real v1.0.0 deployment is refused before any bundle is copied', function (): void {
    $root = fixtureRoot('historical-tampered');
    deployHistoricalNodeEndpoint($root);
    $path = $root . '/plugins/NodeEndpoint/Controllers/SettingsController.php';
    file_put_contents($path, '// operator change', FILE_APPEND);
    $before = hash_file('sha256', $path);
    expectFailure(fn () => (new BundledPluginInstaller($root))->stage(), 'modified files');
    check(hash_file('sha256', $path) === $before && count(glob($root . '/plugins/*')) === 1,
        'Modified historical source or another plugin was partially overwritten');
});

foreach (['enabled', 'disabled'] as $historicalChoice) {
    test('real Git-tag v1.0.0 native deployment upgrades to 1.0.8 in fresh processes; ' . $historicalChoice . ' choice survives', function () use ($historicalChoice): void {
        global $sourceRoot, $work;
        $root = fixtureRoot('historical-' . $historicalChoice);
        $historical = deployHistoricalNodeEndpoint($root);
        check($historical['commit'] === 'f9f0ca8fc0f26965fbd0d9eb8febd7eab8d861ff', 'Wrong local historical commit');
        copyFixture($sourceRoot . '/public/assets/admin', $root . '/public/assets/admin');
        copy($sourceRoot . '/resources/views/admin.blade.php', $root . '/resources/views/admin.blade.php');
        foreach (['install-old', 'stage-current', 'synchronize-current'] as $phase) {
            $environment = getenv();
            $environment['XBOARD_TEST_WORK'] = $work . '/child-' . $historicalChoice . '-' . $phase;
            $arguments = [PHP_BINARY];
            if (php_ini_loaded_file()) { $arguments = [...$arguments, '-c', php_ini_loaded_file()]; }
            $output = runFixtureProcess([...$arguments, __DIR__ . '/historical-upgrade.php', $phase, $root, $historicalChoice], $environment);
            $result = json_decode(trim($output), true, 32, JSON_THROW_ON_ERROR);
            check(($result['phase'] ?? null) === $phase && ($result['composer_loader'] ?? false), 'Historical child process evidence missing');
            check($phase === 'install-old' ? ($result['native_installed'] ?? false) : ($result['result'] ?? '') === 'PASS', 'Historical phase did not pass');
        }
    });
}

$root = fixtureRoot('lifecycle');
copyFixture($sourceRoot . '/public/assets/admin', $root . '/public/assets/admin');
copy($sourceRoot . '/resources/views/admin.blade.php', $root . '/resources/views/admin.blade.php');
$app = bootFixture($root);
$installer = app(BundledPluginInstaller::class);
test('native default installation installs and enables all four using real Composer autoload', function (): void {
    PluginManager::installDefaultPlugins();
    check(Plugin::count() === 4 && Plugin::where('is_enabled', true)->count() === 4, 'Four plugins are not enabled');
    check(Plugin::where('code', 'qrcodeextend')->value('version') === '1.2.0', 'Wrong QR version');
    foreach (['node_endpoint_settings', 'flowscope_server_settings', 'flowscope_machine_settings', 'flowscope_install_tickets'] as $table) {
        check(Schema::hasTable($table), 'Missing migration table ' . $table);
    }
    foreach ([\Plugin\NodeEndpoint\Services\Endpoint::class, \Plugin\Qrcodeextend\Services\AdminBundlePatcher::class,
              \Plugin\Flowscope\Services\InstallService::class, \Plugin\SmartExpiry\Services\AdminBridgePatcher::class] as $class) {
        check(class_exists($class), 'Real Composer could not load ' . $class);
    }
});
test('repeat preserves settings and public CA; upgrade preserves existing identity', function () use ($root, $installer): void {
    $settings = ['enabled' => true, 'host' => '198.51.100.9', 'port' => 18443, 'https' => true, 'ws_mode' => 'rest'];
    \Plugin\NodeEndpoint\Services\Settings::write($settings);
    mkdir($root . '/storage/app/node_endpoint', 0700, true);
    $ca = $root . '/storage/app/node_endpoint/trust-bundle.pem'; file_put_contents($ca, 'public CA canary');
    Plugin::where('code', 'node_endpoint')->update(['config' => '{"custom":"preserve"}', 'version' => '1.0.0']);
    $id = Plugin::where('code', 'node_endpoint')->value('id');
    $installer->synchronize();
    check(Plugin::where('code', 'node_endpoint')->value('id') === $id, 'Identity changed');
    check(Plugin::where('code', 'node_endpoint')->value('version') === '1.0.8', 'Version not upgraded');
    check(Plugin::where('code', 'node_endpoint')->value('config') === '{"custom":"preserve"}', 'Config changed');
    check(\Plugin\NodeEndpoint\Services\Settings::read()['host'] === $settings['host'], 'Endpoint changed');
    check(file_get_contents($ca) === 'public CA canary', 'CA changed');
});
test('administrator disabled choice is preserved; enable-all is explicit', function () use ($installer): void {
    app(PluginManager::class)->disable('smart_expiry');
    $installer->synchronize();
    check(!Plugin::where('code', 'smart_expiry')->value('is_enabled'), 'Disabled plugin silently enabled');
    $installer->synchronize(true);
    check(Plugin::where('code', 'smart_expiry')->value('is_enabled') === true, 'Explicit enable-all failed');
});
test('both dynamic Admin integrations coexist with shared bundle patches', function () use ($app, $root): void {
    $app->http = true;
    app(PluginManager::class)->initializeEnabledPlugins();
    $html = view('admin', ['title' => 'Fixture', 'version' => 'fixture', 'logo' => '', 'secure_path' => 'fixture'])->render();
    check(str_contains($html, 'node-endpoint') && str_contains($html, 'flowscope'), 'View composition lost an integration');
    $js = file_get_contents($root . '/public/assets/admin/assets/index-CEIYH7i8.js');
    check(str_contains($js, '/*qrcodeextend:start:v3*/') && str_contains($js, 'smart-expiry-edit-v7')
        && str_contains($js, 'smart-expiry-create-v7'), 'Shared Admin patch missing');
    $app->http = false;
});
test('boot failure cannot leave an apparently enabled plugin', function () use ($app, $installer): void {
    $original = app(PluginManager::class);
    $app->instance(PluginManager::class, new class extends PluginManager {
        public function enable(string $code): bool {
            Plugin::where('code', $code)->update(['is_enabled' => true]);
            throw new RuntimeException('fixture boot failure');
        }
    });
    expectFailure(fn () => $installer->synchronize(), 'fixture boot failure');
    check(!Plugin::where('code', 'node_endpoint')->value('is_enabled'), 'Failed boot remained enabled');
    $app->instance(PluginManager::class, $original);
});
echo json_encode(['result' => 'PASS', 'checks' => $passed, 'composer_loader' => true,
                  'database' => 'isolated SQLite', 'root_actions' => false], JSON_PRETTY_PRINT) . "\n";

// Remove only this newly created private fixture after all checks pass.
(new Illuminate\Filesystem\Filesystem())->deleteDirectory($work);
