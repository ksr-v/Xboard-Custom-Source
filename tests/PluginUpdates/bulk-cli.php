<?php
declare(strict_types=1);
$GLOBALS['__composer_autoload_files']['a03e85e827f2f53ca6b173cf8d947ac6'] = true;
require dirname(__DIR__) . '/BundledPlugins/bootstrap.php';

use App\Console\Commands\PluginsOnlineUpdate;
use App\Models\Plugin;
use App\Services\Plugin\OnlinePluginUpdater;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class BulkUpdaterFixture
{
    public array $calls = [];
    public $action;
    public function supports(string $code): bool { return in_array($code, ['qrcodeextend', 'telegram'], true); }
    public function update(string $code): array
    {
        $this->calls[] = $code;
        if ($this->action) { return ($this->action)($code); }
        $row = Plugin::where('code', $code)->firstOrFail();
        return ['code' => $code, 'version' => $row->version, 'updated' => false];
    }
}
check(!class_exists(OnlinePluginUpdater::class, false), 'Fixture requires an unloaded service class');
class_alias(BulkUpdaterFixture::class, OnlinePluginUpdater::class);
$root = fixtureRoot('bulk-cli');
$app = bootFixture($root);
$updater = new BulkUpdaterFixture();
$app->instance(OnlinePluginUpdater::class, $updater);
foreach (['qrcodeextend', 'telegram', 'third_party'] as $code) {
    Plugin::create(['code' => $code, 'name' => $code, 'version' => '1.3.1', 'config' => '{"secret":"keep"}', 'is_enabled' => false]);
}
function runBulk(): array {
    global $app;
    $command = new PluginsOnlineUpdate();
    $command->setLaravel($app);
    $output = new BufferedOutput();
    $exit = $command->run(new ArrayInput(['--json' => true], $command->getDefinition()), $output);
    return [$exit, json_decode(trim($output->fetch()), true, 32, JSON_THROW_ON_ERROR)];
}
test('bulk reports installed supported plugins and skips third-party sources', function () use ($updater): void {
    [$exit, $report] = runBulk();
    check($exit === 0 && $report['failures'] === [], 'Bulk command failed');
    check(array_keys($report['plugins']) === ['qrcodeextend', 'telegram'], 'Unexpected plugin selection');
    check($updater->calls === ['qrcodeextend', 'telegram'], 'Unexpected updater invocation');
    check(!str_contains(json_encode($report), 'secret'), 'Configuration leaked in report');
});
test('bulk stops on the first source error and omits sensitive exception text', function () use ($updater): void {
    $updater->calls = [];
    $updater->action = static function (string $code): array { throw new RuntimeException('password=private-fixture'); };
    [$exit, $report] = runBulk();
    check($exit === 1 && $updater->calls === ['qrcodeextend'], 'A failed source did not stop the batch');
    check($report['failures'][0]['code'] === 'qrcodeextend' && !str_contains(json_encode($report), 'private-fixture'), 'Failure report leaked sensitive context');
});
test('bulk refuses successful-looking reports when configuration or enabled state changes', function () use ($updater): void {
    $updater->action = static function (string $code): array {
        Plugin::where('code', $code)->update(['is_enabled' => true]);
        return ['code' => $code, 'version' => '1.3.1', 'updated' => false];
    };
    [$exit, $report] = runBulk();
    check($exit === 1 && $report['plugins'] === [], 'Changed enabled choice was accepted');
    Plugin::where('code', 'qrcodeextend')->update(['is_enabled' => false]);
});
test('bulk reports verified actual target versions without exposing source extras', function () use ($updater): void {
    $updater->action = static function (string $code): array {
        Plugin::where('code', $code)->update(['version' => '1.3.2']);
        return ['code' => $code, 'version' => '1.3.2', 'updated' => true,
            'source' => ['repository' => 'ksr-v/fixture', 'tag' => 'v1.3.2', 'config' => 'private-fixture']];
    };
    [$exit, $report] = runBulk();
    check($exit === 0 && $report['plugins']['qrcodeextend']['version'] === '1.3.2', 'Verified target version missing');
    check(!isset($report['plugins']['qrcodeextend']['source']['config']), 'Unexpected source fields leaked');
});
\Illuminate\Support\Facades\DB::purge('sqlite');
$temporaryRoot = realpath(sys_get_temp_dir());
$fixturePath = realpath($work);
check($fixturePath !== false && str_starts_with($fixturePath, $temporaryRoot . DIRECTORY_SEPARATOR)
    && str_starts_with(basename($fixturePath), 'xboard-bundle-'), 'Unsafe fixture cleanup target');
check((new \Illuminate\Filesystem\Filesystem())->deleteDirectory($fixturePath), 'Could not remove bulk CLI fixture');
echo "{$passed} bulk CLI checks passed\n";
