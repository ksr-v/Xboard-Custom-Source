<?php
declare(strict_types=1);

// Independent PHP processes and flock against copied assets. No Laravel/DB.
use Plugin\Qrcodeextend\Services\AdminBundlePatcher;

$panel = dirname(__DIR__, 2);
$patcherPath = $panel . '/bundled-plugins/Qrcodeextend/Services/AdminBundlePatcher.php';
$option = array_search('--patcher', $argv, true);
if ($option !== false) {
    $patcherPath = $argv[$option + 1] ?? '';
    array_splice($argv, $option, 2);
}
require $patcherPath;

if (($argv[1] ?? '') === '--hold') {
    $lock = fopen($argv[2] . '/' . AdminBundlePatcher::LOCK_FILE, 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) { exit(2); }
    file_put_contents($argv[3], 'locked');
    fread(STDIN, 1);
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}
if (($argv[1] ?? '') === '--worker') {
    try {
        $patcher = new AdminBundlePatcher($argv[2]);
        $operation = $argv[3] ?? 'patch';
        if (!in_array($operation, ['patch', 'status', 'removeOwnedChanges'], true)) { exit(2); }
        echo json_encode(['result' => $patcher->$operation()], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    } catch (Throwable $error) {
        echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR), "\n";
        exit(1);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function startChild(array $arguments): array
{
    global $patcherPath;
    $process = proc_open([PHP_BINARY, __FILE__, ...$arguments, '--patcher', $patcherPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Could not start fixture child.');
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
    if (!waitChild($child, 10)) {
        proc_terminate($child['process']);
        waitChild($child, 2);
    }
    $output = stream_get_contents($child['pipes'][1]);
    $error = stream_get_contents($child['pipes'][2]);
    foreach ($child['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    proc_close($child['process']);
    return ['exit' => $child['exit'], 'output' => $output, 'error' => $error];
}
function fingerprints(string $root, array $files): array
{
    $result = [];
    foreach ($files as $file) {
        $path = $root . '/' . $file;
        clearstatcache(true, $path);
        $result[$file] = [hash_file('sha256', $path), filesize($path), filemtime($path), fileinode($path)];
    }
    return $result;
}
function underLock(string $root, string $operation, bool $mustFinish, callable $whileHeld): array
{
    $ready = $root . '/holder.ready';
    $holder = startChild(['--hold', $root, $ready]);
    $worker = null;
    $failure = null;
    try {
        $until = microtime(true) + 5;
        do {
            clearstatcache(true, $ready);
            if (is_file($ready)) { break; }
            check(proc_get_status($holder['process'])['running'], 'Holder exited before locking.');
            usleep(10000);
        } while (microtime(true) < $until);
        check(is_file($ready), 'Fixture lock holder did not become ready.');
        unlink($ready);
        $worker = startChild(['--worker', $root, $operation]);
        $finished = waitChild($worker, $mustFinish ? 2.0 : 0.4);
        check($finished === $mustFinish,
            $operation . ($mustFinish ? ' unnecessarily waited on the shared lock.' : ' bypassed the shared lock.'));
        check(proc_get_status($holder['process'])['running'], 'Holder stopped before the check completed.');
        $whileHeld();
    } catch (Throwable $error) {
        $failure = $error;
    } finally {
        fwrite($holder['pipes'][0], "\n");
        fflush($holder['pipes'][0]);
        $holderResult = closeChild($holder);
        $workerResult = $worker === null ? null : closeChild($worker);
    }
    if ($failure !== null) { throw $failure; }
    check($holderResult['exit'] === 0 && $holderResult['error'] === '', 'Fixture lock holder failed.');
    check($workerResult !== null && $workerResult['error'] === '', 'Unexpected fixture stderr.');
    $result = json_decode($workerResult['output'], true, 512, JSON_THROW_ON_ERROR);
    check($workerResult['exit'] === (isset($result['error']) ? 1 : 0), 'Unexpected worker exit status.');
    return $result;
}

check(function_exists('proc_open'), 'This regression requires proc_open for real independent lock holders.');
$fixture = sys_get_temp_dir() . '/qrcodeextend-lock-' . bin2hex(random_bytes(8));
$bundle = 'public/assets/admin/' . AdminBundlePatcher::TARGET_BUNDLE;
$manifest = 'public/assets/admin/manifest.json';
$files = [$bundle, $manifest];
$checks = 0;
try {
    foreach ([dirname($bundle), 'storage/framework'] as $directory) { mkdir($fixture . '/' . $directory, 0775, true); }
    foreach ($files as $file) { check(copy($panel . '/' . $file, $fixture . '/' . $file), 'Fixture asset copy failed.'); }
    $patcher = new AdminBundlePatcher($fixture);
    $patcher->removeOwnedChanges();
    $clean = (string) file_get_contents($fixture . '/' . $bundle);
    $validManifest = (string) file_get_contents($fixture . '/' . $manifest);
    $patcher->patch();
    $valid = (string) file_get_contents($fixture . '/' . $bundle);
    $reflection = new ReflectionClass($patcher);
    $block = $reflection->getMethod('ownedBlock')->invoke($patcher);
    $blockV4 = $reflection->getMethod('ownedBlockV4')->invoke($patcher);
    $before = fingerprints($fixture, $files);
    $noWrites = static function () use ($fixture, $files, &$before): void {
        check(fingerprints($fixture, $files) === $before, 'Assets or metadata changed before lock acquisition.');
    };

    $result = underLock($fixture, 'patch', true, $noWrites);
    check(($result['result'] ?? null) === 'Admin bridge is already patched; no changes made.', 'Complete bridge was not accepted.');
    check(fingerprints($fixture, $files) === $before, 'Read-only fast path changed files or metadata.');
    echo "PASS complete v5 patch finishes under an independently held lock without writes\n"; $checks++;

    file_put_contents($fixture . '/' . $bundle, $clean);
    $before = fingerprints($fixture, $files);
    $result = underLock($fixture, 'patch', false, $noWrites);
    check(($result['result'] ?? null) === 'Admin bridge patched; foreign modifications were preserved.'
        && file_get_contents($fixture . '/' . $bundle) === $valid, 'Clean installation failed after unlock.');
    echo "PASS clean installation waits for the shared lock before writing\n"; $checks++;

    $old = str_replace($block, $blockV4, $valid, $count);
    check($count === 1, 'Expected one owned v5 block in the fixture.');
    file_put_contents($fixture . '/' . $bundle, $old);
    $before = fingerprints($fixture, $files);
    $result = underLock($fixture, 'patch', false, $noWrites);
    check(($result['result'] ?? null) === 'Admin bridge upgraded from owned v4 to v5.'
        && file_get_contents($fixture . '/' . $bundle) === $valid, 'Owned v4 upgrade failed after unlock.');
    echo "PASS owned v4 upgrade waits for the shared lock before writing\n"; $checks++;

    $invalidCases = [
        'modified marker' => [str_replace('qrcodeextend:start:v5', 'qrcodeextend:start:damaged', $valid), $validManifest],
        'moved owned block' => [$block . str_replace($block, '', $valid), $validManifest],
        'unsupported manifest' => [$valid, '{"index.html":{"file":"assets/unsupported.js"}}'],
    ];
    foreach ($invalidCases as $label => [$invalidBundle, $invalidManifest]) {
        file_put_contents($fixture . '/' . $bundle, $invalidBundle);
        file_put_contents($fixture . '/' . $manifest, $invalidManifest);
        $before = fingerprints($fixture, $files);
        $result = underLock($fixture, 'patch', false, $noWrites);
        check(isset($result['error']) && fingerprints($fixture, $files) === $before,
            $label . ' was accepted or overwritten.');
        echo "PASS $label is rechecked under lock and rejected without writes\n"; $checks++;
    }

    file_put_contents($fixture . '/' . $manifest, $validManifest);
    file_put_contents($fixture . '/' . $bundle, $invalidCases['modified marker'][0]);
    $before = fingerprints($fixture, $files);
    $expectedAfterRepair = null;
    $result = underLock($fixture, 'patch', false, static function () use ($noWrites, $fixture, $bundle, $valid, $files, &$expectedAfterRepair): void {
        $noWrites();
        file_put_contents($fixture . '/' . $bundle, $valid);
        $expectedAfterRepair = fingerprints($fixture, $files);
    });
    check(($result['result'] ?? null) === 'Admin bridge is already patched; no changes made.'
        && fingerprints($fixture, $files) === $expectedAfterRepair, 'Transient invalid state was not rechecked after unlock.');
    echo "PASS transient validation failure is rechecked after the holder repairs it\n"; $checks++;

    file_put_contents($fixture . '/' . $bundle, $clean);
    $before = fingerprints($fixture, $files);
    $foreign = "\n/*foreign-lock-update*/";
    $result = underLock($fixture, 'patch', false, static function () use ($noWrites, $fixture, $bundle, $clean, $foreign): void {
        $noWrites();
        file_put_contents($fixture . '/' . $bundle, $clean . $foreign);
    });
    check(isset($result['result']) && file_get_contents($fixture . '/' . $bundle) === $valid . $foreign,
        'Patch used stale pre-lock bytes and overwrote a foreign update.');
    echo "PASS changed source is reread under lock and preserves the holder's foreign bytes\n"; $checks++;

    file_put_contents($fixture . '/' . $bundle, $clean);
    $before = fingerprints($fixture, $files);
    $invalidated = str_replace((string) $reflection->getConstant('ANCHOR'), '/*foreign-changed-anchor*/', $clean);
    $result = underLock($fixture, 'patch', false, static function () use ($noWrites, $fixture, $bundle, $invalidated): void {
        $noWrites();
        file_put_contents($fixture . '/' . $bundle, $invalidated);
    });
    check(isset($result['error']) && file_get_contents($fixture . '/' . $bundle) === $invalidated,
        'Source invalidated while waiting was accepted or overwritten.');
    echo "PASS source invalidated while waiting is rejected without overwriting the holder's bytes\n"; $checks++;

    file_put_contents($fixture . '/' . $bundle, $valid);
    $before = fingerprints($fixture, $files);
    $result = underLock($fixture, 'status', false, $noWrites);
    check(($result['result']['supported'] ?? false) && ($result['result']['patched'] ?? false), 'Status changed.');
    $result = underLock($fixture, 'removeOwnedChanges', false, $noWrites);
    check(($result['result'] ?? null) === 'Removed only the owned qrcodeextend Admin bridge.'
        && file_get_contents($fixture . '/' . $bundle) === $clean, 'Cleanup changed.');
    echo "PASS status and cleanup retain shared-lock exclusion\n"; $checks++;

    echo "PASS $checks real-process QRCodeExtend lock regression scenarios\n";
} finally {
    if (is_dir($fixture)) {
        $resolved = realpath($fixture);
        check(is_string($resolved) && strcasecmp(dirname($resolved), (string) realpath(sys_get_temp_dir())) === 0
            && preg_match('/^qrcodeextend-lock-[a-f0-9]{16}$/D', basename($resolved)) === 1,
            'Refusing to clean up a path outside the created temporary fixture.');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($fixture);
    }
}
