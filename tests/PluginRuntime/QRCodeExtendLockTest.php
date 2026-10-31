<?php

declare(strict_types=1);

// Standalone filesystem/process regression checks; no Laravel or live site.
namespace App\Services\Plugin {
    abstract class AbstractPlugin
    {
        public function __construct(protected string $basePath) {}
    }
}

namespace Illuminate\Support\Facades {
    final class File
    {
        public static int $copies = 0;
        public static function ensureDirectoryExists(string $path): void
        {
            if (!is_dir($path)) { mkdir($path, 0775, true); }
        }
        public static function copyDirectory(string $source, string $target): bool
        {
            self::$copies++;
            self::ensureDirectoryExists($target);
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                $path = $target . '/' . substr($entry->getPathname(), strlen($source) + 1);
                if ($entry->isDir()) { self::ensureDirectoryExists($path); }
                elseif (!copy($entry->getPathname(), $path)) { return false; }
            }
            return true;
        }
    }
}

namespace {
    use Illuminate\Support\Facades\File;
    use Plugin\Qrcodeextend\Plugin;
    use Plugin\Qrcodeextend\Services\AdminBundlePatcher;

    $sourceRoot = dirname(__DIR__, 2);
    require $sourceRoot . '/bundled-plugins/Qrcodeextend/Services/AdminBundlePatcher.php';
    require $sourceRoot . '/bundled-plugins/Qrcodeextend/Plugin.php';

    if (($argv[1] ?? '') === '--holder') {
        [, , $root, $ready, $release, $repair] = $argv;
        $lock = fopen($root . '/' . AdminBundlePatcher::LOCK_FILE, 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { exit(2); }
        file_put_contents($ready, 'ready');
        $until = microtime(true) + 1.3;
        while (microtime(true) < $until && !is_file($release)) { usleep(10000); clearstatcache(true, $release); }
        if ($repair !== '-') {
            $path = $root . '/public/assets/admin/' . AdminBundlePatcher::TARGET_BUNDLE;
            copy($repair, $path . '.repair');
            rename($path . '.repair', $path);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        exit(0);
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    function public_path(string $relative = ''): string
    {
        global $fixtureRoot;
        return $fixtureRoot . '/public/' . $relative;
    }
    function app(string $class): object
    {
        global $patcher;
        check($class === AdminBundlePatcher::class, 'Unexpected container lookup.');
        return $patcher;
    }
    function heldLock(callable $operation, string $repair = '-'): float
    {
        global $fixtureRoot;
        $token = bin2hex(random_bytes(5));
        $ready = $fixtureRoot . '/ready-' . $token;
        $release = $fixtureRoot . '/release-' . $token;
        $process = proc_open([PHP_BINARY, __FILE__, '--holder', $fixtureRoot, $ready, $release, $repair], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process), 'Could not start lock holder.');
        fclose($pipes[0]);
        try {
            $until = microtime(true) + 5;
            while (!is_file($ready) && microtime(true) < $until) { usleep(10000); clearstatcache(true, $ready); }
            check(is_file($ready), 'Lock holder did not become ready.');
            $start = microtime(true);
            $operation();
            return microtime(true) - $start;
        } finally {
            file_put_contents($release, 'release');
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $result = proc_close($process);
            foreach ([$ready, $release] as $path) { if (is_file($path)) { unlink($path); } }
            check($result === 0, 'Lock holder failed: ' . $error);
        }
    }

    $fixtureRoot = sys_get_temp_dir() . '/qrcode-lock-' . bin2hex(random_bytes(8));
    $bundlePath = $fixtureRoot . '/public/assets/admin/' . AdminBundlePatcher::TARGET_BUNDLE;
    mkdir(dirname($bundlePath), 0775, true);
    mkdir($fixtureRoot . '/storage/framework', 0775, true);
    copy($sourceRoot . '/public/assets/admin/manifest.json', $fixtureRoot . '/public/assets/admin/manifest.json');
    $source = file_get_contents($sourceRoot . '/public/assets/admin/' . AdminBundlePatcher::TARGET_BUNDLE);
    check(is_string($source), 'Missing source Admin fixture.');
    file_put_contents($bundlePath, $source);
    $patcher = new AdminBundlePatcher($fixtureRoot);
    $checks = [];
    $test = static function (string $name, callable $body) use (&$checks): void {
        try { $body(); $checks[] = ['name' => $name, 'passed' => true]; }
        catch (\Throwable $error) { $checks[] = ['name' => $name, 'passed' => false, 'error' => $error->getMessage()]; }
    };
    try {
        $patcher->patch();
        $patched = file_get_contents($bundlePath);
        $test('complete installed bridge returns while another process owns the lock', static function () use ($patcher, $bundlePath, $patched): void {
            $elapsed = heldLock(static function () use ($patcher): void {
                check($patcher->patch() === 'Admin bridge is already patched; no changes made.', 'Unexpected installed status.');
            });
            check($elapsed < 0.75, 'Installed bridge waited for the shared writer lock.');
            check(file_get_contents($bundlePath) === $patched, 'Read path changed the Admin bundle.');
        });
        $test('clean bridge waits for the lock before writing', static function () use ($patcher, $bundlePath, $source): void {
            file_put_contents($bundlePath, $source);
            $elapsed = heldLock(static function () use ($patcher): void { $patcher->patch(); });
            check($elapsed >= 1.0, 'Clean bridge bypassed the shared writer lock.');
        });
        $test('corrupt owned bridge waits and is rejected without writes', static function () use ($patcher, $bundlePath, $patched): void {
            $corrupt = str_replace('/*qrcodeextend:start:v3*/', '/*qrcodeextend:start:v2*/', $patched);
            file_put_contents($bundlePath, $corrupt);
            $elapsed = heldLock(static function () use ($patcher): void {
                try { $patcher->patch(); }
                catch (\RuntimeException) { return; }
                throw new \RuntimeException('Corrupt bridge was accepted.');
            });
            check($elapsed >= 1.0, 'Invalid optimistic read was not retried under the lock.');
            check(file_get_contents($bundlePath) === $corrupt, 'Corrupt bundle was overwritten.');
        });
        $test('invalid optimistic state is reread after a cooperating writer repairs it', static function () use ($patcher, $bundlePath, $patched, $fixtureRoot): void {
            file_put_contents($bundlePath, 'incomplete concurrent snapshot');
            $repair = $fixtureRoot . '/repair-content';
            file_put_contents($repair, $patched);
            $elapsed = heldLock(static function () use ($patcher): void {
                check($patcher->patch() === 'Admin bridge is already patched; no changes made.', 'Writer repair was not reread.');
            }, $repair);
            check($elapsed >= 1.0, 'Repair did not pass through the shared writer lock.');
            check(file_get_contents($bundlePath) === $patched, 'Writer repair was changed.');
        });
        $test('identical published assets skip copying; same-size corruption and missing files are repaired', static function () use ($sourceRoot, $fixtureRoot): void {
            $plugin = new Plugin($sourceRoot . '/bundled-plugins/Qrcodeextend');
            File::$copies = 0;
            $plugin->boot();
            check(File::$copies === 1, 'Initial publication was skipped.');
            $plugin->boot();
            check(File::$copies === 1, 'Identical assets were recopied.');
            $target = $fixtureRoot . '/public/plugins/qrcodeextend/qrcodeextend.js';
            $expected = file_get_contents($target);
            file_put_contents($target, ($expected[0] === 'x' ? 'y' : 'x') . substr($expected, 1));
            $plugin->boot();
            check(File::$copies === 2 && file_get_contents($target) === $expected, 'Same-size asset corruption was accepted.');
            unlink($fixtureRoot . '/public/plugins/qrcodeextend/vendor/qrcodegen.js');
            $plugin->boot();
            check(File::$copies === 3 && is_file($fixtureRoot . '/public/plugins/qrcodeextend/vendor/qrcodegen.js'), 'Missing nested asset was not repaired.');
        });
    } finally {
        $resolved = realpath($fixtureRoot);
        $temporaryRoot = realpath(sys_get_temp_dir());
        check(is_string($resolved) && is_string($temporaryRoot)
            && str_starts_with($resolved, $temporaryRoot . DIRECTORY_SEPARATOR . 'qrcode-lock-'), 'Refusing cleanup outside the fixture root.');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($resolved);
    }
    $passed = count(array_filter($checks, static fn (array $check): bool => $check['passed']));
    echo json_encode(['type' => 'standalone filesystem and real process lock checks; not PHP-FPM acceptance',
        'passed' => $passed, 'total' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($passed === count($checks) ? 0 : 1);
}
