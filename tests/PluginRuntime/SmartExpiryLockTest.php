<?php
declare(strict_types=1);

// Real PHP processes and flock, with copied Admin assets only. No Laravel/DB.
use Plugin\SmartExpiry\Services\AdminBridgePatcher;

$panel = dirname(__DIR__, 2);
require $panel . '/bundled-plugins/SmartExpiry/Services/AdminBridgePatcher.php';

if (($argv[1] ?? '') === '--hold') {
    $lock = fopen($argv[2], 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) { exit(2); }
    file_put_contents($argv[3], 'locked');
    fread(STDIN, 1);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}
if (($argv[1] ?? '') === '--worker') {
    try {
        $patcher = new AdminBridgePatcher($argv[2]);
        $operation = $argv[3] ?? 'apply';
        if (!in_array($operation, ['apply', 'status', 'remove'], true)) { exit(2); }
        echo json_encode(['result' => $patcher->$operation()], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    } catch (Throwable $error) {
        echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR), "\n";
        exit(1);
    }
}
if (!function_exists('proc_open')) {
    fwrite(STDERR, "This regression requires proc_open for real independent lock holders.\n");
    exit(2);
}

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function startChild(array $arguments): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Could not start fixture child.');
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes, 'exit' => null];
}
function waitChild(array &$child, float $seconds): bool
{
    $until = microtime(true) + $seconds;
    do {
        $status = proc_get_status($child['process']);
        if (!$status['running']) { $child['exit'] = $status['exitcode']; return true; }
        usleep(10000);
    } while (microtime(true) < $until);
    return false;
}
function finishChild(array &$child): array
{
    check(waitChild($child, 10), 'Fixture child did not finish.');
    $output = stream_get_contents($child['pipes'][1]);
    $error = stream_get_contents($child['pipes'][2]);
    foreach ($child['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    proc_close($child['process']);
    check($error === '', 'Unexpected fixture stderr: ' . $error);
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
function holder(string $root): array
{
    $ready = $root . '/holder-' . bin2hex(random_bytes(4)) . '.ready';
    $child = startChild(['--hold', $root . '/storage/framework/xboard-admin-patch.lock', $ready]);
    $until = microtime(true) + 5;
    do {
        clearstatcache(true, $ready);
        if (is_file($ready)) { unlink($ready); return $child; }
        usleep(10000);
    } while (microtime(true) < $until);
    proc_terminate($child['process']);
    throw new RuntimeException('Fixture lock holder did not become ready.');
}
function releaseHolder(array &$child): void
{
    fwrite($child['pipes'][0], "\n");
    fflush($child['pipes'][0]);
    check(waitChild($child, 5), 'Fixture holder did not release.');
    foreach ($child['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    proc_close($child['process']);
}
function fingerprints(string $root, array $files): array
{
    $result = [];
    foreach ($files as $file) {
        clearstatcache(true, $root . '/' . $file);
        $result[$file] = [hash_file('sha256', $root . '/' . $file), filemtime($root . '/' . $file), fileinode($root . '/' . $file)];
    }
    return $result;
}
function underLock(string $root, string $operation, bool $mustFinish, callable $whileHeld): array
{
    $hold = holder($root);
    $worker = startChild(['--worker', $root, $operation]);
    $failure = null;
    try {
        $finished = waitChild($worker, $mustFinish ? 2.0 : 0.4);
        check($finished === $mustFinish, $operation . ($mustFinish ? ' waited on the shared lock.' : ' bypassed the shared lock.'));
        $whileHeld();
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        releaseHolder($hold);
    }
    $result = finishChild($worker);
    if ($failure !== null) { throw $failure; }
    return $result;
}

$fixture = sys_get_temp_dir() . '/smart-expiry-lock-' . bin2hex(random_bytes(8));
$files = ['public/assets/admin/index.html', 'public/assets/admin/assets/index-CEIYH7i8.js',
    'public/assets/admin/locales/en-US.js', 'public/assets/admin/locales/ru-RU.js', 'public/assets/admin/locales/zh-CN.js'];
$checks = 0;
try {
    foreach ([...array_map('dirname', $files), 'storage/framework'] as $directory) {
        if (!is_dir($fixture . '/' . $directory)) { mkdir($fixture . '/' . $directory, 0775, true); }
    }
    foreach ($files as $file) { check(copy($panel . '/' . $file, $fixture . '/' . $file), 'Fixture copy failed.'); }
    $patcher = new AdminBridgePatcher($fixture);
    $patcher->apply();
    $before = fingerprints($fixture, $files);

    $result = underLock($fixture, 'apply', true, static function () use ($fixture, $files, $before): void {
        check(fingerprints($fixture, $files) === $before, 'Read-only fast path changed Admin files.');
    });
    check(($result['result'] ?? null) === 'already patched', 'Complete v7 bridge was not accepted.');
    echo "PASS complete v7 apply finishes while another process owns the lock, without writes\n"; $checks++;

    $patcher->remove();
    $before = fingerprints($fixture, $files);
    $result = underLock($fixture, 'apply', false, static function () use ($fixture, $files, $before): void {
        check(fingerprints($fixture, $files) === $before, 'Source bridge was modified before acquiring lock.');
    });
    check(($result['result'] ?? null) === 'patched' && $patcher->status() === 'v7', 'Source bridge did not patch after unlock.');
    echo "PASS source apply waits for shared lock before changing files\n"; $checks++;

    $locale = $fixture . '/public/assets/admin/locales/en-US.js';
    $validLocale = file_get_contents($locale);
    $corruptLocale = str_replace(AdminBridgePatcher::MARKER, 'smart-expiry-corrupt', $validLocale);
    check($validLocale !== $corruptLocale, 'Fixture corruption did not remove v7 marker.');
    file_put_contents($locale, $corruptLocale);
    $before = fingerprints($fixture, $files);
    $result = underLock($fixture, 'apply', false, static function () use ($fixture, $files, $before): void {
        check(fingerprints($fixture, $files) === $before, 'Corrupt bridge was overwritten.');
    });
    check(isset($result['error']) && fingerprints($fixture, $files) === $before, 'Corrupt bridge was accepted or modified.');
    echo "PASS corrupt multi-file bridge rechecks under lock and is rejected without writes\n"; $checks++;

    $result = underLock($fixture, 'apply', false, static function () use ($locale, $validLocale): void {
        file_put_contents($locale, $validLocale);
    });
    check(($result['result'] ?? null) === 'already patched', 'Transient multi-file update was not rechecked after lock.');
    echo "PASS transient validation error is rechecked after the lock holder completes\n"; $checks++;

    $result = underLock($fixture, 'status', false, static function (): void {});
    check(($result['result'] ?? null) === 'v7', 'Status changed.');
    $result = underLock($fixture, 'remove', false, static function (): void {});
    check(($result['result'] ?? null) === 'removed' && $patcher->status() === 'source', 'Cleanup changed.');
    echo "PASS status and cleanup retain shared-lock exclusion\n"; $checks++;

    echo "PASS $checks real-process lock regression scenarios\n";
} finally {
    if (is_dir($fixture)) {
        $resolved = realpath($fixture);
        check(is_string($resolved) && strcasecmp(dirname($resolved), (string) realpath(sys_get_temp_dir())) === 0
            && preg_match('/^smart-expiry-lock-[a-f0-9]{16}$/D', basename($resolved)) === 1,
            'Refusing to clean up a path outside the created temporary fixture.');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($fixture);
    }
}
