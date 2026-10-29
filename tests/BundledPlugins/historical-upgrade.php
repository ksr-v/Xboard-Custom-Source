<?php
declare(strict_types=1);
// Each phase runs in a fresh PHP process: old plugin classes must never be
// relabelled as a new release in the same Composer class table.
require __DIR__ . '/bootstrap.php';

use App\Models\Plugin;
use App\Services\Plugin\{BundledPluginInstaller, PluginManager};
use Illuminate\Support\Facades\{DB, Schema};

$phase = $argv[1] ?? ''; $root = realpath($argv[2] ?? ''); $enabled = ($argv[3] ?? '') === 'enabled';
check(in_array($phase, ['install-old', 'stage-current', 'synchronize-current'], true) && $root !== false, 'Invalid historical phase');
$statePath = $root . '/historical-upgrade-state.json';
$app = bootFixture($root, $phase !== 'install-old');
check($loader instanceof Composer\Autoload\ClassLoader, 'Historical fixture did not use Composer ClassLoader');
if ($phase === 'install-old') {
    $manager = app(PluginManager::class);
    check($manager->install('node_endpoint') && $manager->enable('node_endpoint'), 'Native historical install failed');
    if (!$enabled) { $manager->disable('node_endpoint'); }
    check(Plugin::where('code', 'node_endpoint')->value('version') === '1.0.0', 'Native install did not use old config');
    check(!defined('Plugin\\NodeEndpoint\\Plugin::VERSION') && !class_exists(\Plugin\NodeEndpoint\Services\AdminIntegration::class), 'Current NodeEndpoint classes leaked into old install');
    check(realpath((new ReflectionClass(\Plugin\NodeEndpoint\Services\Settings::class))->getFileName()) === realpath($root . '/plugins/NodeEndpoint/Services/Settings.php'), 'Old service bypassed Composer fixture mapping');
    \Plugin\NodeEndpoint\Services\Settings::write(['enabled' => true, 'host' => '198.51.100.27', 'port' => 18443,
        'https' => true, 'http_acknowledged' => false, 'ws_mode' => 'rest']);
    mkdir($root . '/storage/app/node_endpoint', 0700, true);
    file_put_contents($root . '/storage/app/node_endpoint/trust-bundle.pem', 'historical public CA canary');
    Plugin::where('code', 'node_endpoint')->update(['config' => '{"operator_setting":"preserved-v1.0.0"}']);
    Plugin::create(['code' => 'foreign_fixture', 'name' => 'Foreign fixture', 'version' => '7.3.0', 'type' => 'feature',
        'is_enabled' => false, 'config' => '{"foreign":"keep"}', 'installed_at' => now()]);
    mkdir($root . '/plugins/ForeignFixture', 0700);
    file_put_contents($root . '/plugins/ForeignFixture/notes.txt', 'foreign plugin canary');
    file_put_contents($root . '/plugins/NodeEndpoint/operator-notes.txt', 'unowned documentation canary');
    $state = ['enabled' => $enabled, 'row' => (array) DB::table('v2_plugins')->where('code', 'node_endpoint')->first(),
        'foreign' => (array) DB::table('v2_plugins')->where('code', 'foreign_fixture')->first(),
        'settings' => (array) DB::table('node_endpoint_settings')->where('id', 1)->first(),
        'ca_sha256' => hash_file('sha256', $root . '/storage/app/node_endpoint/trust-bundle.pem'),
        'old_plugin_sha256' => hash_file('sha256', $root . '/plugins/NodeEndpoint/Plugin.php'),
        'old_config_sha256' => hash_file('sha256', $root . '/plugins/NodeEndpoint/config.json')];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode(['phase' => $phase, 'old_version' => '1.0.0', 'native_installed' => true, 'composer_loader' => true]), "\n";
    exit;
}
$state = json_decode(file_get_contents($statePath), true, 32, JSON_THROW_ON_ERROR);
check(!class_exists(\Plugin\NodeEndpoint\Plugin::class, false), 'Old plugin classes loaded before fresh staging');
$installer = new BundledPluginInstaller($root);
if ($phase === 'stage-current') {
    check(!is_file($root . '/storage/app/private/plugin-bundles/ownership.json'), 'Old native installation was already managed');
    $report = $installer->stage();
    check($report['node_endpoint']['action'] === 'upgraded-files', 'accepted_previous did not claim the actual old source');
    check((array) DB::table('v2_plugins')->where('code', 'node_endpoint')->first() === $state['row'], 'Source staging changed old native plugin row');
    $backups = glob($root . '/storage/app/private/plugin-bundles/files-*/node_endpoint/Plugin.php');
    check(count($backups) === 1 && hash_file('sha256', $backups[0]) === $state['old_plugin_sha256'], 'Actual historical plugin bytes were not backed up');
    check($installer->stage()['node_endpoint']['action'] === 'unchanged', 'Claimed source staging was not idempotent');
    $ownership = json_decode(file_get_contents($root . '/storage/app/private/plugin-bundles/ownership.json'), true);
    check($ownership['plugins']['node_endpoint']['version'] === '1.1.0', 'Current source ownership was not recorded');
} else {
    $report = $installer->synchronize();
    check($report['node_endpoint']['lifecycle'] === ($state['enabled'] ? 'upgraded and enabled' : 'upgraded; disabled state preserved'), 'Native historical lifecycle not upgraded');
    $row = (array) DB::table('v2_plugins')->where('code', 'node_endpoint')->first();
    check($row['id'] === $state['row']['id'] && $row['installed_at'] === $state['row']['installed_at'] &&
        $row['version'] === '1.1.0' && $row['config'] === $state['row']['config'] && (bool) $row['is_enabled'] === $state['enabled'], 'Old native identity/config/administrator choice changed');
    check(\Plugin\NodeEndpoint\Plugin::VERSION === '1.1.0' && class_exists(\Plugin\NodeEndpoint\Services\AdminIntegration::class), 'Fresh Composer did not load current classes');
    check(Schema::hasTable('node_endpoint_ca_credentials'), 'Existing native deployment did not migrate its credential vault');
    check(Schema::hasTable('node_endpoint_web_jobs'), 'Existing native deployment did not migrate durable Root job ownership');
    check(Plugin::count() === 6 && Plugin::whereIn('code', ['smart_expiry', 'qrcodeextend', 'flowscope', 'subinfo_customizer'])->where('is_enabled', true)->count() === 4, 'Other bundled plugins were not installed normally');
    $installer->synchronize();
    check((bool) Plugin::where('code', 'node_endpoint')->value('is_enabled') === $state['enabled'], 'Repeat changed administrator disabled choice');
}
check((array) DB::table('node_endpoint_settings')->where('id', 1)->first() === $state['settings'], 'Historical endpoint settings changed');
check(hash_file('sha256', $root . '/storage/app/node_endpoint/trust-bundle.pem') === $state['ca_sha256'], 'Historical public CA changed');
check((array) DB::table('v2_plugins')->where('code', 'foreign_fixture')->first() === $state['foreign'], 'Foreign native record changed');
check(file_get_contents($root . '/plugins/ForeignFixture/notes.txt') === 'foreign plugin canary' &&
    file_get_contents($root . '/plugins/NodeEndpoint/operator-notes.txt') === 'unowned documentation canary', 'Unowned source files changed');
$manifest = json_decode(file_get_contents($root . '/bundled-plugins/manifest.json'), true);
$current = array_values(array_filter($manifest['plugins'], fn ($item) => $item['code'] === 'node_endpoint'))[0];
foreach ($current['files'] as $file => $digest) check(hash_file('sha256', $root . '/plugins/NodeEndpoint/' . $file) === $digest, 'Current deployed source mismatch');
echo json_encode(['phase' => $phase, 'result' => 'PASS', 'actual_historical_source_upgraded' => true,
    'administrator_enabled_choice_preserved' => true, 'config_settings_ca_and_foreign_files_preserved' => true, 'composer_loader' => true]), "\n";
