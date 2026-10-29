<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Copies verified release sources into the ordinary, upload-compatible plugin
 * directory. It never runs as root or during an HTTP request.
 */
final class BundledPluginInstaller
{
    private const DIRECTORIES = [
        'node_endpoint' => 'NodeEndpoint', 'smart_expiry' => 'SmartExpiry',
        'qrcodeextend' => 'Qrcodeextend', 'flowscope' => 'Flowscope',
        'subinfo_customizer' => 'SubinfoCustomizer',
    ];
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? base_path(), '/\\');
    }

    public static function assertApplicationUser(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Bundled plugins must be installed by the application CLI, never by a Web request.');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            throw new RuntimeException('Do not run Artisan as root. Use the Root installer, which runs application steps as the site user.');
        }
    }

    public function stage(): array
    {
        return $this->locked(fn (array $plugins, string $private) => $this->stageLocked($plugins, $private));
    }

    private function locked(callable $operation): array
    {
        self::assertApplicationUser();
        $plugins = $this->manifest();
        $private = $this->root . '/storage/app/private/plugin-bundles';
        $this->directory($private, 0700);
        $lockPath = $private . '/sync.lock';
        $this->safe($lockPath);
        $lock = fopen($lockPath, 'c+b');
        if (!$lock) { throw $this->permissions(); }
        chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another bundled-plugin installation is already running.');
        }
        try {
            return $operation($plugins, $private);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function synchronize(bool $enableAll = false): array
    {
        return $this->locked(fn (array $plugins, string $private) => $this->synchronizeLocked($plugins, $private, $enableAll));
    }

    private function synchronizeLocked(array $plugins, string $private, bool $enableAll): array
    {
        $report = $this->stageLocked($plugins, $private);
        $manager = app(PluginManager::class);
        $snapshot = [];
        foreach (self::DIRECTORIES as $code => $directory) {
            $row = Plugin::where('code', $code)->first();
            $snapshot[$code] = $row?->toArray();
        }
        $operation = $this->root . '/storage/app/private/plugin-bundles/lifecycle-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.json';
        $this->writeJson($operation, ['schema_version' => 1, 'before' => $snapshot, 'status' => 'started']);
        $activeCode = null;
        try {
            foreach (self::DIRECTORIES as $code => $directory) {
                $activeCode = $code;
                $row = Plugin::where('code', $code)->first();
                if ($report[$code]['action'] === 'preserved-newer') {
                    if (!$row || $row->version !== $report[$code]['version']) {
                        throw new RuntimeException("Newer {$code} files and database version do not agree. Complete their native installation before retrying.");
                    }
                    if ($enableAll && $row && !$row->is_enabled) { $manager->enable($code); }
                    $report[$code]['lifecycle'] = 'newer version preserved';
                    continue;
                }
                $version = $report[$code]['version'];
                if (!$row) {
                    $manager->install($code);
                    $manager->enable($code);
                    $report[$code]['lifecycle'] = 'installed and enabled';
                } elseif (version_compare($row->version, $version, '>')) {
                    throw new RuntimeException("Installed {$code} is newer than its deployed files. Restore its matching plugin files before continuing.");
                } elseif (version_compare($row->version, $version, '<')) {
                    $enabled = (bool) $row->is_enabled;
                    $manager->update($code);
                    if (!$enableAll && !$enabled) { $manager->disable($code); }
                    $report[$code]['lifecycle'] = $enabled || $enableAll ? 'upgraded and enabled' : 'upgraded; disabled state preserved';
                } else {
                    // A core update can restore the original Admin bundle.
                    // Reapply integration for enabled plugins in this fresh CLI.
                    if ($enableAll || $row->is_enabled) { $manager->enable($code); }
                    $report[$code]['lifecycle'] = $enableAll ? 'current; enable requested' : 'current; administrator state preserved';
                }
            }
            $activeCode = null;
            $build = $this->root . '/build.json';
            if (is_file($build)) {
                $this->safe($build);
                if (filesize($build) > 16384) { throw new RuntimeException('Invalid release build metadata.'); }
                $metadata = json_decode(file_get_contents($build), true, 32, JSON_THROW_ON_ERROR);
                if (!preg_match('/^[a-f0-9]{40}$/D', $metadata['commit'] ?? '')
                    || !preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $metadata['version'] ?? '')) {
                    throw new RuntimeException('Invalid release build metadata.');
                }
                $this->writeJson($this->root . '/storage/app/private/xboard-build.json', [
                    'commit' => $metadata['commit'], 'version' => $metadata['version'],
                    'updated_at' => gmdate('c'),
                ]);
            }
            $this->writeJson($operation, ['schema_version' => 1, 'before' => $snapshot, 'status' => 'complete', 'result' => $report]);
            return $report;
        } catch (\Throwable $error) {
            // Native enable marks the row before boot. Do not leave a failed
            // integration appearing enabled or claim that every plugin is ready.
            if ($activeCode !== null) {
                Plugin::where('code', $activeCode)->update(['is_enabled' => false]);
            }
            $this->writeJson($operation, ['schema_version' => 1, 'before' => $snapshot, 'status' => 'failed',
                'message' => 'Plugin lifecycle did not finish. Source backups and pre-operation plugin records have been retained.']);
            throw $error;
        }
    }

    private function manifest(): array
    {
        $path = $this->root . '/bundled-plugins/manifest.json';
        $this->safe($path);
        if (!is_file($path) || filesize($path) > 2097152) {
            throw new RuntimeException('Bundled plugin manifest is missing or too large. Use the complete independent Xboard release.');
        }
        $manifest = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== 1 || !is_string($manifest['bundle_version'] ?? null)
            || !is_array($manifest['plugins'] ?? null) || count($manifest['plugins']) !== count(self::DIRECTORIES)) {
            throw new RuntimeException('Invalid bundled plugin manifest.');
        }
        $result = [];
        foreach ($manifest['plugins'] as $item) {
            $code = $item['code'] ?? '';
            if (!is_string($code) || !isset(self::DIRECTORIES[$code]) || isset($result[$code])
                || ($item['directory'] ?? null) !== self::DIRECTORIES[$code]
                || !preg_match('/^\d+\.\d+\.\d+$/D', $item['version'] ?? '')
                || !preg_match('/^[a-f0-9]{40}$/iD', $item['source_commit'] ?? '')
                || !is_string($item['source_repository'] ?? null) || $item['source_repository'] === '') {
                throw new RuntimeException('Invalid or duplicate bundled plugin identity.');
            }
            $source = $this->root . '/bundled-plugins/' . $item['directory'];
            $files = $this->fileMap($item['files'] ?? null);
            $actual = $this->tree($source);
            if ($actual !== $files) { throw new RuntimeException("Bundled source integrity failed for {$code}."); }
            $config = json_decode(file_get_contents($source . '/config.json'), true, 32, JSON_THROW_ON_ERROR);
            if (($config['code'] ?? null) !== $code || ($config['version'] ?? null) !== $item['version']
                || !isset($files['Plugin.php'], $files['config.json'])) {
                throw new RuntimeException("Bundled config does not match its manifest: {$code}.");
            }
            $previous = [];
            foreach ($item['accepted_previous'] ?? [] as $old) {
                if (!is_array($old) || !preg_match('/^\d+\.\d+\.\d+$/D', $old['version'] ?? '')
                    || !preg_match('/^[a-f0-9]{40}$/iD', $old['source_commit'] ?? '')) {
                    throw new RuntimeException('Invalid accepted previous plugin release.');
                }
                $previous[$old['version']] = $this->fileMap($old['files'] ?? null);
            }
            $result[$code] = $item + ['accepted_previous' => []];
            $result[$code]['files'] = $files;
            $result[$code]['previous_files'] = $previous;
            $result[$code]['bundle_version'] = $manifest['bundle_version'];
        }
        return $result;
    }

    private function stageLocked(array $plugins, string $private): array
    {
        $ownershipPath = $private . '/ownership.json';
        $this->safe($ownershipPath);
        $ownership = ['schema_version' => 1, 'plugins' => []];
        if (file_exists($ownershipPath)) {
            if (!is_file($ownershipPath) || filesize($ownershipPath) > 2097152) {
                throw new RuntimeException('Invalid bundled plugin ownership record.');
            }
            $ownership = json_decode(file_get_contents($ownershipPath), true, 64, JSON_THROW_ON_ERROR);
            if (($ownership['schema_version'] ?? null) !== 1 || !is_array($ownership['plugins'] ?? null)) {
                throw new RuntimeException('Invalid bundled plugin ownership record.');
            }
        }
        $plans = $report = [];
        $next = $ownership;
        foreach ($plugins as $code => $item) {
            $destination = $this->root . '/plugins/' . $item['directory'];
            $this->safe($destination);
            $actual = is_dir($destination) ? $this->tree($destination) : [];
            if (file_exists($destination) && !is_dir($destination)) { throw new RuntimeException("Plugin destination is not a directory: {$code}."); }
            $currentVersion = null;
            if (isset($actual['config.json'])) {
                $config = json_decode(file_get_contents($destination . '/config.json'), true, 32, JSON_THROW_ON_ERROR);
                if (($config['code'] ?? null) !== $code || !preg_match('/^\d+\.\d+\.\d+$/D', $config['version'] ?? '')) {
                    throw new RuntimeException("Existing plugin identity is invalid: {$code}.");
                }
                $currentVersion = $config['version'];
            }
            if ($currentVersion !== null && version_compare($currentVersion, $item['version'], '>')) {
                $report[$code] = ['action' => 'preserved-newer', 'version' => $currentVersion];
                continue;
            }
            $owned = $ownership['plugins'][$code] ?? null;
            if ($owned !== null && ($owned['directory'] ?? null) !== $item['directory']) {
                throw new RuntimeException("Plugin ownership directory changed: {$code}.");
            }
            // Native plugin deletion removes the complete runtime directory,
            // while the bundle ownership record intentionally stays private.
            // A missing directory can be staged again; an existing partial
            // tree must still pass every owned-file check below.
            $baseline = $owned !== null && is_dir($destination) ? $this->fileMap($owned['files'] ?? null) : [];
            if (!$baseline && $actual) {
                $baseline = $currentVersion === $item['version'] ? $item['files']
                    : ($item['previous_files'][$currentVersion ?? ''] ?? []);
                if (!$baseline) {
                    throw new RuntimeException("Existing {$code} is not a verified managed release. Review/upgrade it through native plugin management before retrying; files were preserved.");
                }
            }
            foreach ($baseline as $file => $hash) {
                if (($actual[$file] ?? null) !== $hash) {
                    throw new RuntimeException("Existing {$code} has unowned or modified files; review it before retrying. No plugin files were changed.");
                }
            }
            foreach ($item['files'] as $file => $hash) {
                if (isset($actual[$file]) && !isset($baseline[$file]) && $actual[$file] !== $hash) {
                    throw new RuntimeException("Bundled {$code} would overwrite an unknown file. Existing files were preserved.");
                }
            }
            $writes = array_filter($item['files'], fn ($hash, $file) => ($actual[$file] ?? null) !== $hash, ARRAY_FILTER_USE_BOTH);
            $removes = array_diff_key($baseline, $item['files']);
            if (($writes || $removes || ($currentVersion !== null && $currentVersion !== $item['version'])) && $this->namespaceLoaded($code)) {
                throw new RuntimeException("Old {$code} classes are already loaded. Run xboard:bundled-plugins --stage-only in a fresh application-user CLI process before loading plugins.");
            }
            foreach (array_merge(array_keys($writes), array_keys($removes)) as $file) {
                $this->writableDestination($destination . '/' . $file);
            }
            $plans[$code] = ['item' => $item, 'destination' => $destination, 'writes' => $writes, 'removes' => $removes, 'actual' => $actual];
            $report[$code] = ['action' => $actual ? ($writes || $removes ? 'upgraded-files' : ($owned ? 'unchanged' : 'adopted')) : 'installed-files',
                'version' => $item['version']];
            $next['plugins'][$code] = ['directory' => $item['directory'], 'version' => $item['version'],
                'source_commit' => $item['source_commit'], 'source_repository' => $item['source_repository'],
                'bundle_version' => $item['bundle_version'], 'files' => $item['files']];
        }
        $operation = $private . '/files-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5));
        $this->directory($operation, 0700);
        $this->writeJson($operation . '/ownership-before.json', $ownership);
        $changed = $created = [];
        try {
            foreach ($plans as $code => $plan) {
                $this->directory($plan['destination'], 0755);
                foreach (array_unique(array_merge(array_keys($plan['writes']), array_keys($plan['removes']))) as $file) {
                    $destination = $plan['destination'] . '/' . $file;
                    $backup = $operation . '/' . $code . '/' . $file;
                    $this->safe($destination);
                    if (isset($plan['actual'][$file])) {
                        if (!is_file($destination) || hash_file('sha256', $destination) !== $plan['actual'][$file]) {
                            throw new RuntimeException('Plugin files changed during staging. Retry only after reviewing the concurrent change.');
                        }
                        $this->directory(dirname($backup), 0700);
                        if (!copy($destination, $backup)) { throw $this->permissions(); }
                        chmod($backup, 0600);
                        $changed[] = [$destination, $backup, fileperms($destination) & 0777];
                    } else {
                        if (file_exists($destination)) { throw new RuntimeException('An unknown file appeared during staging.'); }
                        $created[] = $destination;
                    }
                    if (isset($plan['writes'][$file])) {
                        $this->directory(dirname($destination), 0755);
                        $source = $this->root . '/bundled-plugins/' . $plan['item']['directory'] . '/' . $file;
                        $this->replaceFile($source, $destination, 0644);
                    } elseif (!unlink($destination)) { throw $this->permissions(); }
                }
            }
            $this->writeJson($ownershipPath, $next);
            $this->writeJson($operation . '/result.json', ['status' => 'complete', 'plugins' => $report]);
            return $report;
        } catch (\Throwable $error) {
            foreach (array_reverse($created) as $file) { if (is_file($file) && !is_link($file)) { unlink($file); } }
            foreach (array_reverse($changed) as [$destination, $backup, $mode]) { $this->replaceFile($backup, $destination, $mode); }
            $this->writeJson($ownershipPath, $ownership);
            $this->writeJson($operation . '/result.json', ['status' => 'failed; source files restored']);
            throw $error;
        }
    }

    private function namespaceLoaded(string $code): bool
    {
        $prefix = 'Plugin\\' . Str::studly($code) . '\\';
        foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
            if (strncasecmp($class, $prefix, strlen($prefix)) === 0) { return true; }
        }
        return false;
    }

    private function fileMap(mixed $files): array
    {
        if (!is_array($files) || !$files || count($files) > 4096) { throw new RuntimeException('Invalid bundled plugin file manifest.'); }
        foreach ($files as $file => $hash) {
            if (!is_string($file) || strlen($file) > 512 || str_contains($file, '\\') || str_contains($file, ':')
                || str_contains($file, "\0") || preg_match('~(?:^|/)(?:\.{0,2}|\.git|\.env)(?:/|$)~', $file)
                || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Unsafe bundled plugin member.');
            }
        }
        ksort($files);
        return $files;
    }

    private function tree(string $root): array
    {
        $this->safe($root);
        if (!is_dir($root)) { throw new RuntimeException('Bundled plugin directory is missing.'); }
        $files = [];
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) { throw new RuntimeException('Plugin trees may not contain links or special files.'); }
            if ($entry->isDir()) { continue; }
            $total += $entry->getSize();
            if ($entry->getSize() > 16777216 || $total > 67108864 || count($files) >= 4096) { throw new RuntimeException('Plugin source exceeds the release size limit.'); }
            $file = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            $files[$file] = hash_file('sha256', $entry->getPathname());
        }
        ksort($files);
        return $files;
    }

    private function safe(string $path): void
    {
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) { throw new RuntimeException('Refusing a symbolic link in a plugin installation path.'); }
        }
    }

    private function directory(string $path, int $mode): void
    {
        $this->safe($path);
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) { throw $this->permissions(); }
        if (!is_writable($path)) { throw $this->permissions(); }
        if ($mode === 0700 && !chmod($path, 0700)) { throw $this->permissions(); }
    }

    private function writableDestination(string $path): void
    {
        $this->safe($path);
        for ($parent = dirname($path); !file_exists($parent); $parent = dirname($parent)) {}
        if (!is_dir($parent) || !is_writable($parent) || (file_exists($path) && (!is_file($path) || !is_writable($path)))) { throw $this->permissions(); }
    }

    private function replaceFile(string $source, string $destination, int $mode): void
    {
        $this->safe($destination);
        $temporary = tempnam(dirname($destination), '.bundled-');
        if (!$temporary) { throw $this->permissions(); }
        try {
            if (!copy($source, $temporary) || !chmod($temporary, $mode) || !rename($temporary, $destination)) { throw $this->permissions(); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }

    private function writeJson(string $path, array $data): void
    {
        $this->safe($path);
        $temporary = tempnam(dirname($path), '.record-');
        if (!$temporary) { throw $this->permissions(); }
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
                || !rename($temporary, $path)) { throw $this->permissions(); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }

    private function permissions(): RuntimeException
    {
        return new RuntimeException('Plugin installation paths are not writable by the application user. Run the Root installer permission preparation; do not run Artisan as root or chmod the whole site 777.');
    }
}
