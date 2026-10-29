<?php
declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\{DB, Schema};

$sourceRoot = dirname(__DIR__, 2);
$vendor = getenv('XBOARD_TEST_VENDOR') ?: $sourceRoot . '/vendor/autoload.php';
if (!is_file($vendor)) { throw new RuntimeException('Set XBOARD_TEST_VENDOR to a local Composer vendor/autoload.php.'); }
/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $vendor;
$loader->setPsr4('App\\', [$sourceRoot . '/app']);
// A shared optimized vendor must load this checkout's application classes.
foreach ($loader->getClassMap() as $class => $path) {
    if (str_starts_with($class, 'App\\')) {
        $localPath = $sourceRoot . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($localPath)) { $loader->addClassMap([$class => $localPath]); }
    }
}
$work = getenv('XBOARD_TEST_WORK') ?: sys_get_temp_dir() . '/xboard-bundle-' . bin2hex(random_bytes(6));
if (file_exists($work)) { throw new RuntimeException('Use a new dedicated test directory.'); }
mkdir($work, 0700, true);

function copyFixture(string $from, string $to): void {
    if (is_link($from)) { throw new RuntimeException('Fixture source link refused.'); }
    if (!is_dir($to)) { mkdir($to, 0700, true); }
    foreach (new DirectoryIterator($from) as $entry) {
        if ($entry->isDot() || $entry->getFilename() === '.git') { continue; }
        if ($entry->isLink()) { throw new RuntimeException('Fixture source link refused.'); }
        $target = $to . '/' . $entry->getFilename();
        if ($entry->isDir()) { copyFixture($entry->getPathname(), $target); }
        elseif (!copy($entry->getPathname(), $target)) { throw new RuntimeException('Fixture copy failed.'); }
    }
}
function fixtureRoot(string $name): string {
    global $work, $sourceRoot;
    $root = $work . '/' . $name;
    mkdir($root, 0700, true);
    copyFixture($sourceRoot . '/bundled-plugins', $root . '/bundled-plugins');
    foreach (['plugins', 'plugins-core', 'bootstrap/cache', 'storage/app/private', 'storage/framework/views',
              'storage/logs', 'resources/views', 'public'] as $part) { mkdir($root . '/' . $part, 0700, true); }
    $controller = 'app/Http/Controllers/V1/Client/ClientController.php';
    mkdir(dirname($root . '/' . $controller), 0700, true);
    check(copy($sourceRoot . '/' . $controller, $root . '/' . $controller), 'Could not copy the real subscription controller');
    return $root;
}
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function expectFailure(callable $action, string $contains): void {
    try { $action(); } catch (Throwable $error) {
        check(str_contains($error->getMessage(), $contains), 'Unexpected failure: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $contains);
}
function test(string $name, callable $action): void {
    global $passed;
    $action();
    $passed++;
    echo "PASS {$name}\n";
}
if (!function_exists('admin_setting')) {
    function admin_setting($key, $default = null) { return ['app_url' => 'https://panel.example.test'][$key] ?? $default; }
}
final class BundleFixtureApplication extends Application {
    public bool $http = false;
    public function runningInConsole() { return !$this->http; }
}
final class BundleFixtureKernel extends Illuminate\Foundation\Console\Kernel {
    protected $bootstrappers = [];
}
function bootFixture(string $root, bool $existingDatabase = false): BundleFixtureApplication {
    global $loader;
    $loader->setPsr4('Plugin\\', [$root . '/plugins']);
    if ($existingDatabase) { check(is_file($root . '/database.sqlite'), 'Existing fixture database missing'); }
    else { touch($root . '/database.sqlite'); }
    $app = new BundleFixtureApplication($root);
    $app->useStoragePath($root . '/storage');
    $app->usePublicPath($root . '/public');
    $app->instance('config', new Repository([
        'app' => ['name' => 'Bundle fixture', 'env' => 'testing', 'debug' => true, 'timezone' => 'UTC',
                  'url' => 'https://panel.example.test', 'key' => 'base64:' . base64_encode(random_bytes(32)), 'cipher' => 'AES-256-CBC'],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite',
             'database' => $root . '/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]],
             'migrations' => ['table' => 'migrations']],
        'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
        'queue' => ['default' => 'sync'],
        'logging' => ['default' => 'single', 'channels' => ['single' => ['driver' => 'single',
                     'path' => $root . '/storage/logs/test.log', 'level' => 'debug']]],
        'view' => ['paths' => [$root . '/resources/views'], 'compiled' => $root . '/storage/framework/views'],
        'filesystems' => ['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => $root . '/storage/app']]],
    ]));
    Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    $app->instance('request', Illuminate\Http\Request::create('https://panel.example.test/'));
    $app->singleton(Illuminate\Contracts\Console\Kernel::class, BundleFixtureKernel::class);
    foreach ([Illuminate\Filesystem\FilesystemServiceProvider::class, Illuminate\Database\DatabaseServiceProvider::class,
        Illuminate\Database\MigrationServiceProvider::class, Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\View\ViewServiceProvider::class, Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Validation\ValidationServiceProvider::class, Illuminate\Foundation\Providers\FoundationServiceProvider::class,
        App\Providers\PluginServiceProvider::class] as $provider) { $app->register($provider); }
    $app->boot();
    if ($existingDatabase) { check(Schema::hasTable('v2_plugins'), 'Existing native plugin records missing'); }
    else Schema::create('v2_plugins', function (Blueprint $table): void {
        $table->id(); $table->string('code')->unique(); $table->string('name'); $table->string('version');
        $table->string('type')->default('feature'); $table->boolean('is_enabled')->default(false);
        $table->text('config')->nullable(); $table->timestamp('installed_at')->nullable(); $table->timestamps();
    });
    return $app;
}
function runFixtureProcess(array $arguments, ?array $environment = null): string {
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, $environment, ['bypass_shell' => true]);
    check(is_resource($process), 'Could not start isolated fixture process'); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'Isolated fixture failed: ' . $stdout . $stderr);
    return $stdout;
}
function deployHistoricalNodeEndpoint(string $root, string $version = '1.0.0'): array {
    global $sourceRoot;
    check(in_array($version, ['1.0.0', '1.0.8'], true), 'Unsupported historical fixture version');
    $tag = 'v' . $version;
    $reference = getenv('NODE_ENDPOINT_REFERENCE') ?: dirname($sourceRoot, 2);
    $git = ['git', '-c', 'safe.directory=' . str_replace('\\', '/', $reference), '-C', $reference];
    $commit = trim(runFixtureProcess([...$git, 'rev-parse', $tag . '^{commit}']));
    $manifest = json_decode(file_get_contents($root . '/bundled-plugins/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    $node = array_values(array_filter($manifest['plugins'], fn ($item) => $item['code'] === 'node_endpoint'))[0];
    $previous = array_values(array_filter($node['accepted_previous'], fn ($item) => $item['version'] === $version))[0];
    check($commit === $previous['source_commit'], 'Historical tag commit differs from accepted_previous');
    $members = array_filter(explode("\n", trim(runFixtureProcess([...$git, 'ls-tree', '-r', '--name-only', $tag, 'plugin/NodeEndpoint']))));
    $files = [];
    foreach ($members as $member) {
        check(str_starts_with($member, 'plugin/NodeEndpoint/'), 'Unexpected historical Git member');
        $relative = substr($member, strlen('plugin/NodeEndpoint/'));
        check(!str_contains($relative, '..') && !str_contains($relative, '\\'), 'Unsafe historical Git member');
        $bytes = runFixtureProcess([...$git, 'show', $tag . ':' . $member]);
        $files[$relative] = hash('sha256', $bytes);
        check(($previous['files'][$relative] ?? null) === $files[$relative], 'Historical blob digest differs from accepted_previous');
        $target = $root . '/plugins/NodeEndpoint/' . $relative;
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
        check(file_put_contents($target, $bytes) === strlen($bytes), 'Historical blob deployment failed');
    }
    ksort($files); $expected = $previous['files']; ksort($expected);
    check($files === $expected && count($files) === ($version === '1.0.0' ? 16 : 25), 'Historical source must be the complete pinned tree');
    check(json_decode(file_get_contents($root . '/plugins/NodeEndpoint/config.json'), true)['version'] === $version, 'Historical source version missing');
    if ($version === '1.0.0') {
        check(!is_file($root . '/plugins/NodeEndpoint/Services/AdminIntegration.php'), 'Historical tree was replaced by current files');
    }
    return ['commit' => $commit, 'files' => $files];
}
function deployHistoricalSubinfo(string $root): array {
    global $sourceRoot;
    $reference = getenv('SUBINFO_REFERENCE') ?: dirname($sourceRoot) . '/release-work/subinfo';
    $git = ['git', '-c', 'safe.directory=' . str_replace('\\', '/', $reference), '-C', $reference];
    $commit = trim(runFixtureProcess([...$git, 'rev-parse', 'v1.0.0^{commit}']));
    $manifest = json_decode(file_get_contents($root . '/bundled-plugins/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    $plugin = array_values(array_filter($manifest['plugins'], fn ($item) => $item['code'] === 'subinfo_customizer'))[0];
    $previous = array_values(array_filter($plugin['accepted_previous'], fn ($item) => $item['version'] === '1.0.0'))[0];
    check($commit === $previous['source_commit'], 'Independent SubInfo tag differs from accepted_previous');
    $members = array_filter(explode("\n", trim(runFixtureProcess([...$git, 'ls-tree', '-r', '--name-only', $commit, 'SubInfoCustomizer/']))));
    $files = [];
    foreach ($members as $member) {
        check(str_starts_with($member, 'SubInfoCustomizer/'), 'Unexpected independent SubInfo Git member');
        $relative = substr($member, strlen('SubInfoCustomizer/'));
        check(!str_contains($relative, '..') && !str_contains($relative, '\\'), 'Unsafe independent SubInfo member');
        $bytes = runFixtureProcess([...$git, 'show', $commit . ':' . $member]);
        $files[$relative] = hash('sha256', $bytes);
        check(($previous['files'][$relative] ?? null) === $files[$relative], 'Independent SubInfo blob differs from accepted_previous');
        $target = $root . '/plugins/SubinfoCustomizer/' . $relative;
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
        check(file_put_contents($target, $bytes) === strlen($bytes), 'Independent SubInfo blob deployment failed');
    }
    ksort($files); $expected = $previous['files']; ksort($expected);
    check($files === $expected, 'Independent SubInfo fixture must be the complete pinned tree');
    check(json_decode(file_get_contents($root . '/plugins/SubinfoCustomizer/config.json'), true)['version'] === '1.0.0', 'Independent SubInfo source version missing');
    return ['commit' => $commit, 'files' => $files];
}
$passed = 0;
