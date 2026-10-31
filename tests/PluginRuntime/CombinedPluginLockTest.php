<?php

declare(strict_types=1);

// Copied production-sized assets, independent PHP processes, and a real shared
// flock. No Laravel, database, plugin boot, or production site is initialized.
use Plugin\SmartExpiry\Services\AdminBridgePatcher;
use Plugin\Qrcodeextend\Services\AdminBundlePatcher;
use Plugin\SubinfoCustomizer\Services\CoreBridgePatcher;

$sourceRoot = dirname(__DIR__, 2);
require $sourceRoot . '/bundled-plugins/SmartExpiry/Services/AdminBridgePatcher.php';
require $sourceRoot . '/bundled-plugins/Qrcodeextend/Services/AdminBundlePatcher.php';
require $sourceRoot . '/bundled-plugins/SubinfoCustomizer/Services/CoreBridgePatcher.php';

if (($argv[1] ?? '') === '--holder') {
    $lock = fopen($argv[2] . '/' . AdminBundlePatcher::LOCK_FILE, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) { exit(2); }
    file_put_contents($argv[3], 'ready');
    fread(STDIN, 1);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}
if (($argv[1] ?? '') === '--apply-all') {
    try {
        echo json_encode([
            'smart_expiry' => (new AdminBridgePatcher($argv[2]))->apply(),
            'qrcodeextend' => (new AdminBundlePatcher($argv[2]))->patch(),
            'subinfo_customizer' => (new CoreBridgePatcher($argv[2]))->apply(),
        ], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function child(array $arguments): array
{
    $process = proc_open([PHP_BINARY, __FILE__, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Cannot start an independent fixture process.');
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes, 'exit' => null];
}
function waitChild(array &$child, float $seconds): bool
{
    if ($child['exit'] !== null) { return true; }
    $until = microtime(true) + $seconds;
    do {
        $status = proc_get_status($child['process']);
        if (!$status['running']) { $child['exit'] = $status['exitcode']; return true; }
        usleep(10000);
    } while (microtime(true) < $until);
    return false;
}
function closeChild(array &$child): array
{
    if (!waitChild($child, 5)) {
        proc_terminate($child['process']);
        waitChild($child, 2);
    }
    $output = stream_get_contents($child['pipes'][1]);
    $error = stream_get_contents($child['pipes'][2]);
    foreach ($child['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    proc_close($child['process']);
    return ['exit' => $child['exit'], 'output' => $output, 'error' => $error];
}
function fingerprints(string $root, array $files, bool $metadata = false): array
{
    $result = [];
    foreach ($files as $relative) {
        $path = $root . '/' . $relative;
        clearstatcache(true, $path);
        $result[$relative] = [filesize($path), hash_file('sha256', $path)];
        if ($metadata) {
            $result[$relative][] = filemtime($path);
            $result[$relative][] = fileinode($path);
        }
    }
    return $result;
}
function completeBridges(AdminBridgePatcher $smart, AdminBundlePatcher $qr, CoreBridgePatcher $sub): void
{
    check($smart->status() === 'v7', 'Complete SmartExpiry bridge was lost.');
    $qrState = $qr->status();
    check($qrState['supported'] && $qrState['patched'], 'Complete QRCodeExtend bridge was lost.');
    check($sub->inspect() === 'patched', 'Complete SubInfoCustomizer bridge was lost.');
}

check(function_exists('proc_open'), 'This test requires real independent processes via proc_open.');
$fixture = sys_get_temp_dir() . '/xboard-combined-lock-' . bin2hex(random_bytes(8));
$bundle = 'public/assets/admin/' . AdminBundlePatcher::TARGET_BUNDLE;
$core = CoreBridgePatcher::RELATIVE_PATH;
$files = ['public/assets/admin/index.html', 'public/assets/admin/manifest.json', $bundle,
    'public/assets/admin/locales/en-US.js', 'public/assets/admin/locales/ru-RU.js',
    'public/assets/admin/locales/zh-CN.js', $core];
$checks = [];
$test = static function (string $name, callable $body) use (&$checks): void {
    try { $body(); $checks[] = ['name' => $name, 'passed' => true]; }
    catch (Throwable $error) { $checks[] = ['name' => $name, 'passed' => false, 'error' => $error->getMessage()]; }
};

try {
    foreach ($files as $relative) {
        if (!is_dir(dirname($fixture . '/' . $relative))) { mkdir(dirname($fixture . '/' . $relative), 0775, true); }
        check(copy($sourceRoot . '/' . $relative, $fixture . '/' . $relative), 'Cannot copy original fixture asset.');
    }
    mkdir($fixture . '/storage/framework', 0775, true);
    $original = fingerprints($fixture, $files);
    // The pre-existing SmartExpiry cleanup changes exactly the CRLF after
    // "permanent" to LF in these locale files. Register that old behavior
    // explicitly rather than claiming complete byte restoration or broadly
    // ignoring whitespace differences. Every other byte must still match.
    $expectedClean = $original;
    $legacyNewlineDifferences = [];
    foreach (['en-US', 'ru-RU', 'zh-CN'] as $locale) {
        $relative = 'public/assets/admin/locales/' . $locale . '.js';
        $before = (string) file_get_contents($sourceRoot . '/' . $relative);
        $expected = preg_replace('~("permanent":[^\r\n]*)\r\n(?=[ \t]*"subscription":)~',
            '${1}' . "\n", $before, -1, $count);
        check(is_string($expected) && $count <= 1, 'Unexpected locale newline fixture shape.');
        check(str_replace("\r\n", "\n", $expected) === str_replace("\r\n", "\n", $before),
            'Registered legacy cleanup expectation changed non-newline bytes.');
        $expectedClean[$relative] = [strlen($expected), hash('sha256', $expected)];
        if ($expected !== $before) { $legacyNewlineDifferences[] = $relative; }
    }
    $smart = new AdminBridgePatcher($fixture);
    $qr = new AdminBundlePatcher($fixture);
    $sub = new CoreBridgePatcher($fixture);
    $afterSmart = $allPatched = [];

    $test('initial SmartExpiry, QRCodeExtend and SubInfoCustomizer installation coexists on the same assets',
        static function () use ($smart, $qr, $sub, $fixture, $files, &$afterSmart, &$allPatched): void {
            check($smart->status() === 'source' && !$qr->status()['patched'] && $sub->inspect() === 'source',
                'Expected clean source assets before installation.');
            check($smart->apply() === 'patched', 'SmartExpiry initial installation was not exercised.');
            $afterSmart = fingerprints($fixture, $files);
            check($qr->patch() === 'Admin bridge patched; foreign modifications were preserved.',
                'QRCodeExtend initial installation was not exercised.');
            check($sub->apply() === 'patched', 'SubInfoCustomizer initial installation was not exercised.');
            completeBridges($smart, $qr, $sub);
            $allPatched = fingerprints($fixture, $files);
        });
    check($afterSmart !== [] && $allPatched !== [], 'Initial installation failed; remaining cases would not be meaningful.');

    $test('all three complete bridges finish while another process holds their shared lock without writes',
        static function () use ($fixture, $files, $smart, $qr, $sub): void {
            $before = fingerprints($fixture, $files, true);
            $ready = $fixture . '/holder.ready';
            $holder = child(['--holder', $fixture, $ready]);
            $worker = null;
            $finishedBeforeRelease = false;
            try {
                $until = microtime(true) + 5;
                do {
                    clearstatcache(true, $ready);
                    if (is_file($ready)) { break; }
                    check(proc_get_status($holder['process'])['running'], 'Holder exited before locking.');
                    usleep(10000);
                } while (microtime(true) < $until);
                check(is_file($ready), 'Holder did not acquire the shared fixture lock.');
                $worker = child(['--apply-all', $fixture]);
                $finishedBeforeRelease = waitChild($worker, 3);
                check(proc_get_status($holder['process'])['running'], 'Holder stopped before the fast paths completed.');
                check(fingerprints($fixture, $files, true) === $before, 'An idempotent bridge changed shared fixture files.');
            } finally {
                fwrite($holder['pipes'][0], "\n");
                fflush($holder['pipes'][0]);
                $holderResult = closeChild($holder);
                $workerResult = $worker === null ? null : closeChild($worker);
            }
            check($holderResult['exit'] === 0 && $holderResult['error'] === '', 'Shared lock holder failed.');
            check($finishedBeforeRelease, 'One of the three complete bridges waited for the shared lock.');
            check($workerResult !== null && $workerResult['exit'] === 0 && $workerResult['error'] === '',
                'Combined worker failed: ' . ($workerResult['error'] ?? 'not started'));
            $results = json_decode($workerResult['output'], true, 512, JSON_THROW_ON_ERROR);
            check($results === ['smart_expiry' => 'already patched',
                'qrcodeextend' => 'Admin bridge is already patched; no changes made.',
                'subinfo_customizer' => 'already patched'], 'A complete bridge did not use its idempotent result.');
            completeBridges($smart, $qr, $sub);
        });

    $test('removing QRCodeExtend preserves the complete SmartExpiry and SubInfoCustomizer bridges',
        static function () use ($smart, $qr, $sub, $fixture, $files, $core, $afterSmart, $allPatched): void {
            check($qr->removeOwnedChanges() === 'Removed only the owned qrcodeextend Admin bridge.', 'QR removal failed.');
            check($smart->status() === 'v7' && $sub->inspect() === 'patched' && !$qr->status()['patched'],
                'QR removal damaged another bridge.');
            $expected = $afterSmart;
            $expected[$core] = $allPatched[$core];
            check(fingerprints($fixture, $files) === $expected, 'QR removal changed bytes outside its owned block.');
        });

    $test('remaining cleanup restores original core/assets with only registered legacy locale newline changes',
        static function () use ($smart, $sub, $fixture, $files, $core, $expectedClean, $allPatched): void {
            check($smart->remove() === 'removed', 'SmartExpiry removal failed.');
            check($sub->inspect() === 'patched', 'SmartExpiry removal damaged the SubInfoCustomizer bridge.');
            $expected = $expectedClean;
            $expected[$core] = $allPatched[$core];
            check(fingerprints($fixture, $files) === $expected, 'SmartExpiry removal changed unrelated bytes.');
            check($sub->remove() === 'removed', 'SubInfoCustomizer removal failed.');
            check(fingerprints($fixture, $files) === $expectedClean, 'Cleanup differed from the registered clean asset bytes.');
        });

    $test('reverse shared-asset installation and cleanup order preserves QRCodeExtend until its own removal',
        static function () use ($smart, $qr, $sub, $fixture, $files, $core, $expectedClean): void {
            check(fingerprints($fixture, $files) === $expectedClean, 'Second installation did not begin from expected clean assets.');
            $qr->patch();
            $qrOnly = fingerprints($fixture, $files);
            $smart->apply();
            $sub->apply();
            completeBridges($smart, $qr, $sub);
            $withCore = fingerprints($fixture, $files);
            $smart->remove();
            $expected = $qrOnly;
            $expected[$core] = $withCore[$core];
            check($smart->status() === 'source' && $qr->status()['patched'] && $sub->inspect() === 'patched',
                'Removing SmartExpiry first damaged a foreign bridge.');
            check(fingerprints($fixture, $files) === $expected, 'SmartExpiry-first removal changed foreign bytes.');
            $sub->remove();
            check($qr->status()['patched'] && fingerprints($fixture, $files) === $qrOnly,
                'SubInfoCustomizer removal changed QRCodeExtend assets.');
            $qr->removeOwnedChanges();
            check(fingerprints($fixture, $files) === $expectedClean, 'Reverse-order cleanup differed from the registered clean bytes.');
        });
} finally {
    if (is_dir($fixture)) {
        $resolved = realpath($fixture);
        check(is_string($resolved) && strcasecmp(dirname($resolved), (string) realpath(sys_get_temp_dir())) === 0
            && preg_match('/^xboard-combined-lock-[a-f0-9]{16}$/D', basename($resolved)) === 1,
            'Refusing cleanup outside the explicitly created temporary fixture.');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($resolved);
    }
}

$passed = count(array_filter($checks, static fn (array $check): bool => $check['passed']));
echo json_encode(['type' => 'combined copied-asset and real-process lock checks; not PHP-FPM acceptance',
    'php_version' => PHP_VERSION, 'source_admin_bundle_bytes' => filesize($sourceRoot . '/' . $bundle),
    'pre_existing_cleanup_crlf_to_lf_assets' => $legacyNewlineDifferences,
    'passed' => $passed, 'total' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($passed === count($checks) ? 0 : 1);
