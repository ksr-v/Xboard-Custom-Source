<?php
declare(strict_types=1);
// Each phase gets a fresh class table, so verified old source is never tested
// with the new plugin's already-loaded PHP classes.
require __DIR__ . '/bootstrap.php';

use App\Models\Plugin;
use App\Services\Plugin\{BundledPluginInstaller, PluginManager};
use Illuminate\Support\Facades\DB;

$phase = $argv[1] ?? '';
$root = realpath($argv[2] ?? '');
$enabled = ($argv[3] ?? '') === 'enabled';
check(in_array($phase, ['install-old', 'stage-current', 'synchronize-current'], true) && $root !== false, 'Invalid independent SubInfo phase');
$app = bootFixture($root, $phase !== 'install-old');
$statePath = $root . '/independent-subinfo-state.json';
$core = $root . '/app/Http/Controllers/V1/Client/ClientController.php';

if ($phase === 'install-old') {
    $manager = app(PluginManager::class);
    check($manager->install('subinfo_customizer') && $manager->enable('subinfo_customizer'), 'Real independent SubInfo installation failed');
    if (!$enabled) { $manager->disable('subinfo_customizer'); }
    check(\Plugin\SubinfoCustomizer\Plugin::VERSION === '1.0.0', 'Historical phase loaded current plugin classes');
    check(realpath((new ReflectionClass(\Plugin\SubinfoCustomizer\Plugin::class))->getFileName()) === realpath($root . '/plugins/SubinfoCustomizer/Plugin.php'), 'Independent source bypassed fixture Composer loader');
    \Plugin\SubinfoCustomizer\Services\Settings::write(['date_format' => 'YYYY-MM-DD', 'rows' => [
        ['enabled' => true, 'template' => '原配置:{uid}'], ['enabled' => false, 'template' => '保留:{remaining_traffic}'],
    ]]);
    Plugin::where('code', 'subinfo_customizer')->update(['config' => '{"management_page":"preserved-independent-choice"}']);
    file_put_contents($root . '/plugins/SubinfoCustomizer/operator-notes.txt', 'independent-notes-canary');
    $state = [
        'enabled' => $enabled,
        'row' => (array) DB::table('v2_plugins')->where('code', 'subinfo_customizer')->first(),
        'settings' => (array) DB::table('subinfo_customizer_settings')->where('id', 1)->first(),
        'core_sha256' => hash_file('sha256', $core),
        'old_plugin_sha256' => hash_file('sha256', $root . '/plugins/SubinfoCustomizer/Plugin.php'),
    ];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
} else {
    $state = json_decode(file_get_contents($statePath), true, 32, JSON_THROW_ON_ERROR);
    check(!class_exists(\Plugin\SubinfoCustomizer\Plugin::class, false), 'Old SubInfo classes loaded before staging');
    $installer = new BundledPluginInstaller($root);
    if ($phase === 'stage-current') {
        check(!is_file($root . '/storage/app/private/plugin-bundles/ownership.json'), 'Independent plugin was already claimed');
        $report = $installer->stage();
        check($report['subinfo_customizer']['action'] === 'upgraded-files', 'Independent v1.0.0 source was not accepted');
        check((array) DB::table('v2_plugins')->where('code', 'subinfo_customizer')->first() === $state['row'], 'Staging changed the independent native row');
        check(hash_file('sha256', $core) === $state['core_sha256'], 'Source staging altered the active core bridge');
        $backups = glob($root . '/storage/app/private/plugin-bundles/files-*/subinfo_customizer/Plugin.php');
        check(count($backups) === 1 && hash_file('sha256', $backups[0]) === $state['old_plugin_sha256'], 'Independent source was not backed up before replacement');
        check($installer->stage()['subinfo_customizer']['action'] === 'unchanged', 'Independent source adoption was not idempotent');
    } else {
        $report = $installer->synchronize();
        check($report['subinfo_customizer']['lifecycle'] === ($enabled ? 'upgraded and enabled' : 'upgraded; disabled state preserved'), 'Independent lifecycle did not preserve its enabled choice');
        $row = (array) DB::table('v2_plugins')->where('code', 'subinfo_customizer')->first();
        check($row['id'] === $state['row']['id'] && $row['installed_at'] === $state['row']['installed_at'] &&
            $row['config'] === $state['row']['config'] && $row['version'] === '1.0.1' && (bool) $row['is_enabled'] === $enabled,
            'Independent plugin identity, config or enabled choice changed');
        check(\Plugin\SubinfoCustomizer\Plugin::VERSION === '1.0.1', 'Fresh process did not load current SubInfo classes');
        check(Plugin::count() === 5 && Plugin::whereIn('code', ['node_endpoint', 'smart_expiry', 'qrcodeextend', 'flowscope'])->where('is_enabled', true)->count() === 4,
            'Other bundled plugins did not install normally');
        $installer->synchronize();
        check((bool) Plugin::where('code', 'subinfo_customizer')->value('is_enabled') === $enabled, 'Repeat synchronization changed the independent disabled choice');
        $bridgeCount = substr_count(str_replace("\r\n", "\n", file_get_contents($core)), \Plugin\SubinfoCustomizer\Services\CoreBridgePatcher::insertion());
        check($bridgeCount === ($enabled ? 1 : 0), 'Independent upgrade left an incorrect bridge state');
    }
    check((array) DB::table('subinfo_customizer_settings')->where('id', 1)->first() === $state['settings'], 'Independent saved template row changed');
    check(file_get_contents($root . '/plugins/SubinfoCustomizer/operator-notes.txt') === 'independent-notes-canary', 'Independent operator notes changed');
}
echo json_encode(['phase' => $phase, 'result' => 'PASS', 'composer_loader' => $loader instanceof Composer\Autoload\ClassLoader,
    'actual_independent_v1_source' => true, 'administrator_choice' => $enabled ? 'enabled' : 'disabled']), "\n";
