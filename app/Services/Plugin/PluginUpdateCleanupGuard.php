<?php

namespace App\Services\Plugin;

use RuntimeException;
use Throwable;

/** Keeps QR's private storage across update cleanup; ordinary disable is unchanged. */
final class PluginUpdateCleanupGuard
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? base_path(), '/\\');
    }

    public function run(string $code, callable $cleanup): mixed
    {
        if ($code !== 'qrcodeextend') { return $cleanup(); }
        $source = $this->root . '/storage/qrcodeextend';
        $this->safe($source);
        if (!file_exists($source)) { return $cleanup(); }
        if (!is_dir($source)) { throw new RuntimeException('QR private storage must be a regular directory before update cleanup.'); }
        $private = $this->root . '/storage/app/private/plugin-update-cleanup';
        $this->directory($private);
        $lockPath = $private . '/qrcodeextend.lock';
        $this->safe($lockPath);
        if (file_exists($lockPath)) { $this->regular($lockPath); }
        $lock = fopen($lockPath, 'c+b');
        if (!$lock || !chmod($lockPath, 0600)) { throw new RuntimeException('Cannot protect the QR update-cleanup lock.'); }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another QR update cleanup is already running.');
        }
        try {
            $snapshot = $this->tree($source);
            $backup = $private . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
            $this->directory($backup);
            $this->copyTree($source, $backup . '/data', $snapshot);
            $metadata = json_encode(['schema_version' => 1, 'code' => $code, 'created_at' => gmdate('c'), 'tree' => $snapshot], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $this->exclusiveFile($backup . '/snapshot.json', $metadata);
            if ($this->tree($source) !== $snapshot) {
                throw new RuntimeException('QR private storage changed while its update backup was copied; cleanup was not started. Backup retained: ' . $backup);
            }
            $failure = null;
            $result = null;
            try { $result = $cleanup(); } catch (Throwable $error) { $failure = $error; }
            try { $this->restore($source, $backup . '/data', $snapshot); }
            catch (Throwable $error) {
                throw new RuntimeException('QR update cleanup could not restore private storage safely. No conflicting data was overwritten. Backup retained: ' . $backup . '. ' . $error->getMessage(), 0, $failure ?? $error);
            }
            if ($failure) { throw $failure; }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function restore(string $target, string $backup, array $snapshot): void
    {
        if ($this->tree($backup) !== $snapshot) { throw new RuntimeException('The protected QR backup changed.'); }
        $this->safe($target);
        if (!file_exists($target)) {
            $temporary = dirname($target) . '/.qrcodeextend-restore-' . bin2hex(random_bytes(8));
            $this->copyTree($backup, $temporary, $snapshot);
            // A newly-created nonempty directory makes rename fail rather than
            // replacing its data. The retained backup is never moved/deleted.
            if (@rename($temporary, $target)) { return; }
        }
        if (!is_dir($target) || is_link($target)) { throw new RuntimeException('QR private storage was concurrently replaced.'); }
        $current = $this->tree($target);
        foreach ($snapshot as $relative => $entry) {
            if (isset($current[$relative]) && $current[$relative] !== $entry) {
                throw new RuntimeException('QR private storage has concurrent conflicting data.');
            }
        }
        foreach ($snapshot as $relative => $entry) {
            $path = $target . '/' . $relative;
            if ($entry['type'] === 'directory') { $this->directory($path); continue; }
            if (file_exists($path)) {
                $this->regular($path);
                if (hash_file('sha256', $path) !== $entry['sha256']) { throw new RuntimeException('QR private storage has concurrent conflicting data.'); }
                continue;
            }
            $this->directory(dirname($path));
            $this->copyFile($backup . '/' . $relative, $path, $entry);
        }
        $restored = $this->tree($target);
        foreach ($snapshot as $relative => $entry) {
            if (($restored[$relative] ?? null) !== $entry) { throw new RuntimeException('QR private storage restoration did not verify.'); }
        }
    }

    private function copyTree(string $source, string $target, array $snapshot): void
    {
        $this->directory($target);
        foreach ($snapshot as $relative => $entry) {
            if ($entry['type'] === 'directory') { $this->directory($target . '/' . $relative); }
            else {
                $this->directory(dirname($target . '/' . $relative));
                $this->copyFile($source . '/' . $relative, $target . '/' . $relative, $entry);
            }
        }
        if ($this->tree($target) !== $snapshot) { throw new RuntimeException('QR private storage backup did not verify.'); }
    }

    private function copyFile(string $source, string $target, array $entry): void
    {
        $this->safe($source);
        $this->regular($source);
        $this->safe($target);
        $input = fopen($source, 'rb');
        $output = @fopen($target, 'x+b');
        if (!$input || !$output) {
            if (is_resource($input)) { fclose($input); }
            if (is_resource($output)) { fclose($output); }
            throw new RuntimeException('Could not exclusively copy QR private storage; files were preserved.');
        }
        try {
            if (!chmod($target, 0600) || stream_copy_to_stream($input, $output) !== $entry['size'] || !fflush($output)) {
                throw new RuntimeException('Could not copy QR private storage completely.');
            }
        } finally { fclose($input); fclose($output); }
        if (hash_file('sha256', $target) !== $entry['sha256']) { throw new RuntimeException('QR private storage copy checksum failed.'); }
    }

    private function exclusiveFile(string $path, string $bytes): void
    {
        $this->safe($path);
        $file = @fopen($path, 'x+b');
        if (!$file) { throw new RuntimeException('Cannot create protected QR backup metadata.'); }
        try {
            if (!chmod($path, 0600) || fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) {
                throw new RuntimeException('Cannot write protected QR backup metadata.');
            }
        } finally { fclose($file); }
    }

    private function tree(string $root): array
    {
        $this->safe($root);
        if (!is_dir($root)) { throw new RuntimeException('QR private storage directory is missing.'); }
        $tree = [];
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) { throw new RuntimeException('QR private storage contains a link or special file.'); }
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if ($entry->isDir()) { $tree[$relative] = ['type' => 'directory']; }
            else {
                $this->regular($path);
                $size = $entry->getSize();
                $total += $size;
                if ($total > 536870912) { throw new RuntimeException('QR private storage exceeds the safe update-backup limit; review it before updating.'); }
                $tree[$relative] = ['type' => 'file', 'size' => $size, 'sha256' => hash_file('sha256', $path)];
            }
            if (count($tree) > 16384) { throw new RuntimeException('QR private storage has too many members for an update backup.'); }
        }
        ksort($tree);
        return $tree;
    }

    private function regular(string $path): void
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || ($stat['nlink'] ?? 1) !== 1) {
            throw new RuntimeException('QR private storage requires regular files without hard links.');
        }
    }

    private function safe(string $path): void
    {
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) { throw new RuntimeException('QR update cleanup refuses symbolic-link paths.'); }
        }
    }

    private function directory(string $path): void
    {
        $this->safe($path);
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) { throw new RuntimeException('Cannot create protected QR update storage.'); }
        if (!is_writable($path) || !chmod($path, 0700)) { throw new RuntimeException('Cannot protect QR update storage.'); }
        if (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) { throw new RuntimeException('QR update storage must belong to the application user.'); }
    }
}
