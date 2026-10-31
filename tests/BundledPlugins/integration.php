<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Models\Plugin;
use App\Services\Plugin\{BundledPluginInstaller, PluginManager};
use Illuminate\Support\Facades\{DB, Schema};

test('source manifest fixes all five versions and QR audit 1.2.0', function (): void {
    global $sourceRoot;
    $manifest = json_decode(file_get_contents($sourceRoot . '/bundled-plugins/manifest.json'), true);
    $versions = array_column($manifest['plugins'], 'version', 'code');
    check($manifest['bundle_version'] === '2026.10.31.1', 'Unexpected bundle release');
    check($versions === ['node_endpoint' => '1.1.3', 'smart_expiry' => '1.2.0', 'qrcodeextend' => '1.2.0', 'flowscope' => '2.4.0', 'subinfo_customizer' => '1.0.1'], 'Unexpected versions');
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
    check(count($first) === 5 && $second['qrcodeextend']['action'] === 'unchanged' && $second['subinfo_customizer']['action'] === 'unchanged', 'Idempotency failed');
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

foreach (['1.0.0', '1.1.0', '1.1.1'] as $historicalVersion) {
foreach (['enabled', 'disabled'] as $historicalChoice) {
    test('real Git-tag v' . $historicalVersion . ' native deployment upgrades to 1.1.3 in fresh processes; ' . $historicalChoice . ' choice survives', function () use ($historicalChoice, $historicalVersion): void {
        global $sourceRoot, $work;
        $root = fixtureRoot('historical-' . $historicalVersion . '-' . $historicalChoice);
        $historical = deployHistoricalNodeEndpoint($root, $historicalVersion);
        $releasedCommits = ['1.0.0' => 'f9f0ca8fc0f26965fbd0d9eb8febd7eab8d861ff', '1.1.0' => '0e5caa5aca55a687a0d82ed70f7aafeb1712c8cc', '1.1.1' => 'c0908a7ca6ef9f448419a150cd6debf067318eca'];
        check($historical['commit'] === $releasedCommits[$historicalVersion], 'Wrong local historical commit');
        copyFixture($sourceRoot . '/public/assets/admin', $root . '/public/assets/admin');
        copy($sourceRoot . '/resources/views/admin.blade.php', $root . '/resources/views/admin.blade.php');
        foreach (['install-old', 'stage-current', 'synchronize-current'] as $phase) {
            $environment = getenv();
            $environment['XBOARD_TEST_WORK'] = $work . '/child-' . $historicalVersion . '-' . $historicalChoice . '-' . $phase;
            $arguments = [PHP_BINARY];
            if (php_ini_loaded_file()) { $arguments = [...$arguments, '-c', php_ini_loaded_file()]; }
            $output = runFixtureProcess([...$arguments, __DIR__ . '/historical-upgrade.php', $phase, $root, $historicalChoice, $historicalVersion], $environment);
            $result = json_decode(trim($output), true, 32, JSON_THROW_ON_ERROR);
            check(($result['phase'] ?? null) === $phase && ($result['composer_loader'] ?? false), 'Historical child process evidence missing');
            check($phase === 'install-old' ? ($result['native_installed'] ?? false) : ($result['result'] ?? '') === 'PASS', 'Historical phase did not pass');
        }
    });
}
}

foreach (['1.1.0', '1.1.1'] as $previousTamperedVersion) {
    test('a modified real v' . $previousTamperedVersion . ' deployment is refused without claiming ownership', function () use ($previousTamperedVersion): void {
        $root = fixtureRoot('previous-' . $previousTamperedVersion . '-tampered');
        deployHistoricalNodeEndpoint($root, $previousTamperedVersion);
        $path = $root . '/plugins/NodeEndpoint/Services/MachineInstallCommand.php';
        file_put_contents($path, '// operator source change', FILE_APPEND);
        $before = hash_file('sha256', $path);
        expectFailure(fn () => (new BundledPluginInstaller($root))->stage(), 'modified files');
        check(hash_file('sha256', $path) === $before && count(glob($root . '/plugins/*')) === 1,
            'Modified previous source or unrelated plugins were overwritten');
        check(!is_file($root . '/storage/app/private/plugin-bundles/ownership.json'), 'Modified previous source was claimed');
    });
}

foreach (['1.0.8', '1.0.10'] as $previousNodeVersion) {
    test('real Git-tag v' . $previousNodeVersion . ' source is accepted and upgraded without losing operator files', function () use ($previousNodeVersion): void {
        $root = fixtureRoot('previous-release-' . $previousNodeVersion);
        $historical = deployHistoricalNodeEndpoint($root, $previousNodeVersion);
        check(count($historical['files']) === 25, 'Previous release fixture is incomplete');
        file_put_contents($root . '/plugins/NodeEndpoint/operator-notes.txt', 'preserve-v' . $previousNodeVersion);
        $installer = new BundledPluginInstaller($root);
        $report = $installer->stage();
        check($report['node_endpoint']['action'] === 'upgraded-files', 'Previous release source was not accepted');
        check(json_decode(file_get_contents($root . '/plugins/NodeEndpoint/config.json'), true)['version'] === '1.1.3', 'Previous release source was not upgraded');
        check(file_get_contents($root . '/plugins/NodeEndpoint/operator-notes.txt') === 'preserve-v' . $previousNodeVersion, 'Unowned operator file changed');
        check($installer->stage()['node_endpoint']['action'] === 'unchanged', 'Previous release upgrade was not idempotent');
    });
}

test('modified independent SubInfo v1.0.0 is refused before any bundled plugin is written', function (): void {
    $root = fixtureRoot('subinfo-tampered');
    deployHistoricalSubinfo($root);
    $path = $root . '/plugins/SubinfoCustomizer/Services/TemplateService.php';
    file_put_contents($path, '// operator change', FILE_APPEND);
    $before = hash_file('sha256', $path);
    expectFailure(fn () => (new BundledPluginInstaller($root))->stage(), 'modified files');
    check(hash_file('sha256', $path) === $before && count(glob($root . '/plugins/*')) === 1,
        'Modified SubInfo or another bundled plugin was partially overwritten');
    check(!is_file($root . '/storage/app/private/plugin-bundles/ownership.json'), 'Rejected source was claimed as managed');
});

test('newer independently installed SubInfo is preserved without downgrade', function (): void {
    $root = fixtureRoot('subinfo-newer');
    copyFixture($root . '/bundled-plugins/SubinfoCustomizer', $root . '/plugins/SubinfoCustomizer');
    $path = $root . '/plugins/SubinfoCustomizer/config.json';
    $config = json_decode(file_get_contents($path), true);
    $config['version'] = '9.0.0'; file_put_contents($path, json_encode($config));
    file_put_contents($root . '/plugins/SubinfoCustomizer/operator-note.txt', 'newer-independent-canary');
    $before = hash_file('sha256', $path);
    $report = (new BundledPluginInstaller($root))->stage();
    check($report['subinfo_customizer']['action'] === 'preserved-newer' && hash_file('sha256', $path) === $before,
        'Newer independent SubInfo was downgraded');
    check(file_get_contents($root . '/plugins/SubinfoCustomizer/operator-note.txt') === 'newer-independent-canary', 'Newer SubInfo operator file changed');
});

test('a completely removed owned SubInfo directory can be restored from the verified bundle', function (): void {
    $root = fixtureRoot('subinfo-owned-removed');
    $installer = new BundledPluginInstaller($root);
    $installer->stage();
    $directory = $root . '/plugins/SubinfoCustomizer';
    check((new Illuminate\Filesystem\Filesystem())->deleteDirectory($directory) && !file_exists($directory), 'Could not remove the dedicated owned fixture directory');
    $report = $installer->stage();
    check($report['subinfo_customizer']['action'] === 'installed-files', 'Completely removed owned SubInfo could not be restored');
    $manifest = json_decode(file_get_contents($root . '/bundled-plugins/manifest.json'), true);
    $subinfo = array_values(array_filter($manifest['plugins'], fn ($item) => $item['code'] === 'subinfo_customizer'))[0];
    foreach ($subinfo['files'] as $file => $digest) {
        check(hash_file('sha256', $directory . '/' . $file) === $digest, 'Restored SubInfo file differs from the verified bundle');
    }
    check($installer->stage()['subinfo_customizer']['action'] === 'unchanged', 'Restored owned SubInfo is not idempotent');
});

test('partially missing or emptied owned SubInfo directories are rejected without silent replacement', function (): void {
    foreach (['partial', 'empty'] as $kind) {
        $root = fixtureRoot('subinfo-owned-' . $kind);
        $installer = new BundledPluginInstaller($root);
        $installer->stage();
        $directory = $root . '/plugins/SubinfoCustomizer';
        $ownership = $root . '/storage/app/private/plugin-bundles/ownership.json';
        $before = hash_file('sha256', $ownership);
        if ($kind === 'partial') {
            check(unlink($directory . '/Plugin.php'), 'Could not remove the partial fixture member');
        } else {
            check((new Illuminate\Filesystem\Filesystem())->deleteDirectory($directory), 'Could not empty the dedicated fixture directory');
            mkdir($directory, 0700);
        }
        expectFailure(fn () => $installer->stage(), 'modified files');
        check(hash_file('sha256', $ownership) === $before && !is_file($directory . '/Plugin.php'),
            'Incomplete owned SubInfo was silently replaced or its ownership changed');
    }
});

test('independent v1.0.0 ZIP verification extras survive adoption and repeat synchronization', function (): void {
    $root = fixtureRoot('subinfo-independent-extras');
    deployHistoricalSubinfo($root);
    $directory = $root . '/plugins/SubinfoCustomizer';
    mkdir($directory . '/tests', 0700);
    file_put_contents($directory . '/tests/operator-evidence.json', '{"independent_zip":"keep"}');
    file_put_contents($directory . '/operator-notes.txt', 'independent-extras-canary');
    $installer = new BundledPluginInstaller($root);
    $report = $installer->stage();
    check($report['subinfo_customizer']['action'] === 'upgraded-files', 'Independent source with ZIP extras was not accepted');
    check(file_get_contents($directory . '/tests/operator-evidence.json') === '{"independent_zip":"keep"}' &&
        file_get_contents($directory . '/operator-notes.txt') === 'independent-extras-canary', 'Independent ZIP extras were removed or changed');
    check($installer->stage()['subinfo_customizer']['action'] === 'unchanged', 'Independent ZIP extras prevent idempotent adoption');
});

// The historical v1.0.0 release itself only supported PHP 8.3. Its real native
// install is exercised there; current fresh installation still runs on PHP 8.2.
if (PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400) {
    foreach (['enabled', 'disabled'] as $historicalChoice) {
        test('independent SubInfo v1.0.0 upgrades in fresh processes and preserves ' . $historicalChoice . ' choice', function () use ($historicalChoice): void {
            global $sourceRoot, $work;
            $root = fixtureRoot('subinfo-historical-' . $historicalChoice);
            deployHistoricalSubinfo($root);
            copyFixture($sourceRoot . '/public/assets/admin', $root . '/public/assets/admin');
            copy($sourceRoot . '/resources/views/admin.blade.php', $root . '/resources/views/admin.blade.php');
            foreach (['install-old', 'stage-current', 'synchronize-current'] as $phase) {
                $environment = getenv();
                $environment['XBOARD_TEST_WORK'] = $work . '/subinfo-child-' . $historicalChoice . '-' . $phase;
                $arguments = [PHP_BINARY];
                if (php_ini_loaded_file()) { $arguments = [...$arguments, '-c', php_ini_loaded_file()]; }
                $output = runFixtureProcess([...$arguments, __DIR__ . '/subinfo-upgrade.php', $phase, $root, $historicalChoice], $environment);
                $result = json_decode(trim($output), true, 32, JSON_THROW_ON_ERROR);
                check(($result['phase'] ?? null) === $phase && ($result['composer_loader'] ?? false), 'Independent SubInfo process evidence missing');
                check(($result['result'] ?? '') === 'PASS', 'Independent SubInfo upgrade phase failed');
            }
        });
    }
} else {
    echo "SKIP independent v1.0.0 native install: that historical release requires PHP 8.3\n";
}

$root = fixtureRoot('lifecycle');
copyFixture($sourceRoot . '/public/assets/admin', $root . '/public/assets/admin');
copy($sourceRoot . '/resources/views/admin.blade.php', $root . '/resources/views/admin.blade.php');
$app = bootFixture($root);
$installer = app(BundledPluginInstaller::class);
test('native default installation installs and enables all five using real Composer autoload', function (): void {
    PluginManager::installDefaultPlugins();
    check(Plugin::count() === 5 && Plugin::where('is_enabled', true)->count() === 5, 'Five plugins are not enabled');
    check(Plugin::where('code', 'qrcodeextend')->value('version') === '1.2.0', 'Wrong QR version');
    foreach (['node_endpoint_settings', 'flowscope_server_settings', 'flowscope_machine_settings', 'flowscope_install_tickets', 'subinfo_customizer_settings'] as $table) {
        check(Schema::hasTable($table), 'Missing migration table ' . $table);
    }
    foreach ([\Plugin\NodeEndpoint\Services\Endpoint::class, \Plugin\Qrcodeextend\Services\AdminBundlePatcher::class,
              \Plugin\Flowscope\Services\InstallService::class, \Plugin\SmartExpiry\Services\AdminBridgePatcher::class,
              \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::class] as $class) {
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
    check(Plugin::where('code', 'node_endpoint')->value('version') === '1.1.3', 'Version not upgraded');
    check(Plugin::where('code', 'node_endpoint')->value('config') === '{"custom":"preserve"}', 'Config changed');
    check(\Plugin\NodeEndpoint\Services\Settings::read()['host'] === $settings['host'], 'Endpoint changed');
    check(file_get_contents($ca) === 'public CA canary', 'CA changed');
});
test('administrator disabled choice is preserved; enable-all is explicit', function () use ($installer): void {
    app(PluginManager::class)->disable('smart_expiry');
    app(PluginManager::class)->disable('subinfo_customizer');
    $installer->synchronize();
    check(!Plugin::where('code', 'smart_expiry')->value('is_enabled'), 'Disabled plugin silently enabled');
    check(!Plugin::where('code', 'subinfo_customizer')->value('is_enabled'), 'Disabled SubInfo silently enabled');
    check((new \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher(base_path()))->inspect() === 'source', 'Disabled SubInfo left its bridge active');
    $installer->synchronize(true);
    check(Plugin::where('code', 'smart_expiry')->value('is_enabled') === true, 'Explicit enable-all failed');
    check(Plugin::where('code', 'subinfo_customizer')->value('is_enabled') === true, 'Explicit SubInfo enable-all failed');
});
test('three dynamic Admin integrations coexist with shared bundle patches', function () use ($app, $root): void {
    $app->http = true;
    app(PluginManager::class)->initializeEnabledPlugins();
    $html = view('admin', ['title' => 'Fixture', 'version' => 'fixture', 'logo' => '', 'secure_path' => 'fixture'])->render();
    check(str_contains($html, 'node-endpoint') && str_contains($html, 'flowscope'), 'View composition lost an integration');
    foreach (['data-ne-install-loader', 'data-flowscope-loader', 'data-subinfo-customizer-loader'] as $marker) {
        check(substr_count($html, $marker) === 1, 'Missing or duplicated Admin loader: ' . $marker);
    }
    $js = file_get_contents($root . '/public/assets/admin/assets/index-CEIYH7i8.js');
    check(str_contains($js, '/*qrcodeextend:start:v3*/') && str_contains($js, 'smart-expiry-edit-v7')
        && str_contains($js, 'smart-expiry-create-v7'), 'Shared Admin patch missing');
    $app->http = false;
});

test('SubInfo disable and reenable preserve settings, shared assets and all other plugin states', function () use ($root): void {
    $settings = ['date_format' => 'YYYY-MM-DD', 'rows' => [
        ['enabled' => true, 'template' => '保留:{uid}'], ['enabled' => false, 'template' => '未启用:{expire_date}'],
    ]];
    \Plugin\SubinfoCustomizer\Services\Settings::write($settings);
    $core = $root . '/' . \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::RELATIVE_PATH;
    $admin = $root . '/public/assets/admin/assets/index-CEIYH7i8.js';
    $adminHash = hash_file('sha256', $admin);
    $otherRows = DB::table('v2_plugins')->where('code', '!=', 'subinfo_customizer')->orderBy('id')->get()->toJson();
    $manager = app(PluginManager::class);
    $manager->disable('subinfo_customizer');
    check(!str_contains(file_get_contents($core), \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::MARKER), 'SubInfo bridge survived disable');
    check(\Plugin\SubinfoCustomizer\Services\Settings::read() === $settings, 'Disable lost SubInfo settings');
    $manager->enable('subinfo_customizer');
    $manager->enable('subinfo_customizer');
    check(substr_count(str_replace("\r\n", "\n", file_get_contents($core)), \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::insertion()) === 1,
        'SubInfo reenable duplicated the core bridge');
    check(hash_file('sha256', $admin) === $adminHash &&
        DB::table('v2_plugins')->where('code', '!=', 'subinfo_customizer')->orderBy('id')->get()->toJson() === $otherRows,
        'SubInfo lifecycle changed shared assets or another plugin record');
    check(\Plugin\SubinfoCustomizer\Services\Settings::read() === $settings, 'Reenable lost SubInfo settings');
});

test('a core archive refresh reapplies exactly one SubInfo bridge and preserves its saved settings', function () use ($root, $installer, $sourceRoot): void {
    $settings = \Plugin\SubinfoCustomizer\Services\Settings::read();
    $relative = \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::RELATIVE_PATH;
    check(copy($sourceRoot . '/' . $relative, $root . '/' . $relative), 'Could not simulate refreshed release core');
    $installer->synchronize();
    $installer->synchronize();
    check(substr_count(str_replace("\r\n", "\n", file_get_contents($root . '/' . $relative)), \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::insertion()) === 1,
        'Core refresh did not restore exactly one bridge');
    check(\Plugin\SubinfoCustomizer\Services\Settings::read() === $settings, 'Core refresh reset saved settings');
});

test('unknown core source is rejected without replacing operator content', function (): void {
    $root = fixtureRoot('unknown-subinfo-core');
    $path = $root . '/' . \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::RELATIVE_PATH;
    file_put_contents($path, "\n// unknown core canary\n", FILE_APPEND);
    $before = hash_file('sha256', $path);
    expectFailure(fn () => (new \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher($root))->apply(), '核心版本');
    check(hash_file('sha256', $path) === $before && !str_contains(file_get_contents($path), \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::MARKER),
        'Unknown core was altered or partially patched');
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
