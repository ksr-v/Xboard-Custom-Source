<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/** Five maintained plugin releases and seven core sources from the official panel. */
final class OnlinePluginUpdater
{
    private const REPOSITORY = 'ksr-v/Xboard-Custom-Source';
    private const SOURCES = [
        'node_endpoint' => 'bundled-plugins/NodeEndpoint',
        'smart_expiry' => 'bundled-plugins/SmartExpiry',
        'qrcodeextend' => 'bundled-plugins/Qrcodeextend',
        'flowscope' => 'bundled-plugins/Flowscope',
        'subinfo_customizer' => 'bundled-plugins/SubinfoCustomizer',
        'alipay_f2f' => 'plugins-core/AlipayF2f',
        'btcpay' => 'plugins-core/Btcpay',
        'coinbase' => 'plugins-core/Coinbase',
        'coin_payments' => 'plugins-core/CoinPayments',
        'epay' => 'plugins-core/Epay',
        'mgate' => 'plugins-core/Mgate',
        'telegram' => 'plugins-core/Telegram',
    ];
    private const SOURCE_REPOSITORIES = [
        'node_endpoint' => 'https://github.com/ksr-v/Xboard-Independent-NodeEndpoint',
        'smart_expiry' => 'https://github.com/ksr-v/xb-SmartExpiry',
        'qrcodeextend' => 'https://github.com/ksr-v/xb-qrcode-extend',
        'flowscope' => 'https://github.com/ksr-v/xb-flowscope',
        'subinfo_customizer' => 'https://github.com/ksr-v/xb-SubInfoCustomizer',
    ];
    private string $root;
    private ?OfficialPluginReleaseSource $updateNativeSource = null;
    private ?array $updatePanelPackage = null;
    private ?\Throwable $updatePanelFailure = null;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? base_path(), '/\\');
    }

    public function supports(string $code): bool
    {
        return isset(self::SOURCES[$code]);
    }

    public function check(array $codes): array
    {
        $result = [];
        $codes = array_values(array_unique($codes));
        $sources = [];
        foreach ($codes as $code) {
            $row = Plugin::where('code', $code)->first();
            $entry = ['supported' => $this->supports($code), 'mode' => $this->supports($code) ? 'online' : 'manual',
                'current_version' => $row?->version, 'latest_version' => null, 'has_update' => false, 'is_ahead' => false];
            if ($this->supports($code)) {
                $key = $this->sourceKey($code);
                $sources[$key] ??= $this->checkedPackage($code);
                $package = $sources[$key]['package'] ?? null;
                $error = $sources[$key]['error'] ?? null;
                if ($error !== null) { $entry['error'] = $error; }
                elseif (!isset($package['configs'][$code])) { $entry['error'] = '最新正式发行缺少此插件，未判断为最新版。'; }
                else {
                    $version = $package['configs'][$code]['version'];
                    $entry['latest_version'] = $version;
                    $entry['release_url'] = $package['release_url'];
                    $entry['release_tag'] = $package['tag'];
                    $entry['is_ahead'] = $row !== null && version_compare($row->version, $version, '>');
                    try {
                        $entry['has_update'] = $row !== null && (version_compare($version, $row->version, '>')
                            || ($version === $row->version && !$this->sourceMatches($code, $package['files'][$code], $version, $package['archive_sha256'])));
                    } catch (\Throwable $failure) { $entry['error'] = $failure->getMessage(); }
                }
            }
            $result[$code] = $entry;
        }
        return $result;
    }

    /** Run outside the HTTP worker so newly downloaded PHP classes can load. */
    public function run(string $code): array
    {
        if (!$this->supports($code)) { throw new RuntimeException('此插件尚未配置可信在线来源，请使用上传升级。'); }
        $output = trim($this->process(['xboard:plugin-online-update', $code], 900));
        $lines = preg_split('/\r?\n/', $output);
        $result = json_decode(end($lines), true, 16, JSON_THROW_ON_ERROR);
        if (($result['code'] ?? null) !== $code || !is_bool($result['updated'] ?? null)) {
            throw new RuntimeException('Plugin update command returned an invalid result.');
        }
        return $result;
    }

    public function update(string $code): array
    {
        BundledPluginInstaller::assertApplicationUser();
        if (!$this->supports($code)) { throw new RuntimeException('Unsupported online plugin source.'); }
        $private = $this->privateDirectory();
        // Serialize with ordinary bundled-plugin synchronization as well.
        $this->directory($this->root . '/storage/app/private/plugin-bundles', 0700);
        $lockPath = $this->root . '/storage/app/private/plugin-bundles/sync.lock';
        $this->safe($lockPath);
        $lock = fopen($lockPath, 'c+b');
        if (!$lock) { throw new RuntimeException('Cannot create plugin update lock.'); }
        chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException('另一个插件正在更新，请稍后重试。'); }
        try {
            $result = $this->updateLocked($code, $private);
            try { Cache::forget($this->checkCacheKey($code)); }
            catch (\Throwable $ignored) { /* A cache outage must not turn a committed plugin update into a reported failure. */ }
            return $result;
        }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function updateLocked(string $code, string $private): array
    {
        $row = Plugin::where('code', $code)->first();
        if (!$row) { throw new RuntimeException('请先安装插件再在线更新。'); }
        // An update always resolves fresh metadata, then keeps this exact package through staging.
        $package = $this->updatePackageFor($code);
        $config = $package['configs'][$code] ?? null;
        if (!$config) { throw new RuntimeException('Latest release does not contain the selected plugin.'); }
        if (str_starts_with(self::SOURCES[$code], 'bundled-plugins/')
            && (($package['manifest'][$code]['files'] ?? null) !== $package['files'][$code])) {
            throw new RuntimeException('Downloaded plugin source lock differs from its files.');
        }
        if (version_compare($config['version'], $row->version, '<')
            || ($config['version'] === $row->version && $this->sourceMatches($code, $package['files'][$code], $config['version'], $package['archive_sha256']))) {
            return ['code' => $code, 'version' => $row->version, 'updated' => false, 'source' => $this->resultSource($code, $package)];
        }
        $manager = app(PluginManager::class);
        $target = $manager->getPluginPath($code);
        $this->safe($target);
        if (!is_dir($target) || !is_writable(dirname($target))) {
            throw new RuntimeException('插件目录不可写，请按安装文档修复站点用户权限。');
        }
        $this->assertTree($target);
        // Ownership must be usable before staging or running any plugin lifecycle code.
        $before = $row->toArray();
        $ownershipPath = $this->root . '/storage/app/private/plugin-bundles/ownership.json';
        $this->safe($ownershipPath);
        $ownershipBefore = null;
        $ownershipStateBefore = ['schema_version' => 1, 'plugins' => []];
        if (file_exists($ownershipPath)) {
            if (!is_file($ownershipPath) || filesize($ownershipPath) > 2097152
                || ($ownershipBefore = file_get_contents($ownershipPath)) === false) {
                throw new RuntimeException('Invalid plugin ownership record.');
            }
            if (strlen($ownershipBefore) > 2097152) { throw new RuntimeException('Invalid plugin ownership record.'); }
            try { $ownershipStateBefore = json_decode($ownershipBefore, true, 64, JSON_THROW_ON_ERROR); }
            catch (\Throwable $invalid) { throw new RuntimeException('Invalid plugin ownership record.', 0, $invalid); }
            if (!is_array($ownershipStateBefore) || ($ownershipStateBefore['schema_version'] ?? null) !== 1
                || !is_array($ownershipStateBefore['plugins'] ?? null)) {
                throw new RuntimeException('Invalid plugin ownership record.');
            }
        }
        $operation = $private . '/backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $this->directory($operation, 0700);
        $this->record($operation . '/ownership-before.json', $ownershipStateBefore);
        // Keep staging on the target filesystem for atomic rename.
        $stage = dirname($target) . '/.online-' . bin2hex(random_bytes(8));
        $this->directory($stage, 0755);
        $backup = dirname($target) . '/.previous-' . bin2hex(random_bytes(8));
        $backupLocation = $backup;
        $this->record($operation . '/state.json', ['code' => $code, 'before' => $before, 'release' => $package['tag'],
            'ownership_existed' => $ownershipBefore !== null, 'status' => 'staging']);
        $swapped = false;
        $backupCreated = false;
        $lifecycleStarted = false;
        try {
            $this->readPluginArchive($code, $package, function (string $relative, string $data) use ($stage): void {
                $destination = $stage . '/' . $relative;
                $this->directory(dirname($destination), 0755);
                if (file_put_contents($destination, $data) !== strlen($data) || !chmod($destination, 0644)) {
                    throw new RuntimeException('Cannot stage plugin source.');
                }
            });
            $this->assertTree($stage);
            if (!is_file($stage . '/Plugin.php')) { throw new RuntimeException('Plugin entry point is missing.'); }
            if ($before['is_enabled']) {
                $lifecycleStarted = true;
                $this->process(['xboard:plugin-online-update', $code, '--deactivate']);
            }
            if (!rename($target, $backup)) { throw new RuntimeException('Cannot back up installed plugin source.'); }
            $backupCreated = true;
            if (!rename($stage, $target)) {
                throw new RuntimeException('Cannot activate downloaded plugin source.');
            }
            $swapped = true;
            $this->process(['xboard:plugin-online-update', $code, '--activate=' . $config['version'], '--enabled=' . ((bool) $row->is_enabled ? '1' : '0')]);
            if (str_starts_with(self::SOURCES[$code], 'bundled-plugins/')) {
                $ownership = $ownershipStateBefore;
                $item = $package['manifest'][$code] ?? null;
                if (!$item || ($item['files'] ?? null) !== $package['files'][$code]) { throw new RuntimeException('Downloaded plugin source lock differs from its files.'); }
                $ownership['plugins'][$code] = ['directory' => $item['directory'], 'version' => $config['version'],
                    'source_commit' => $item['source_commit'], 'source_repository' => $item['source_repository'],
                    'source_provenance' => $item['source_provenance'], 'files' => $package['files'][$code]];
                $this->record($ownershipPath, $ownership);
            }
            // The target filesystem may differ from storage. Retain its backup
            // in place if moving to the private operation directory is unavailable.
            $backupLocation = @rename($backup, $operation . '/source') ? $operation . '/source' : $backup;
            $this->record($operation . '/state.json', ['code' => $code, 'before' => $before, 'release' => $package['tag'], 'status' => 'complete', 'source_backup' => $backupLocation]);
            return ['code' => $code, 'version' => $config['version'], 'updated' => true, 'source' => $this->resultSource($code, $package)];
        } catch (\Throwable $failure) {
            $restored = false;
            $ownershipRestored = null;
            if ($backupCreated && is_dir($backupLocation)) {
                $available = !file_exists($target);
                if (!$available && is_dir($target) && !is_link($target)) {
                    // Retain failed candidate files too; never delete a directory
                    // before confirming the original source can be restored.
                    $available = @rename($target, dirname($target) . '/.failed-' . bin2hex(random_bytes(8)));
                }
                if ($available) { $restored = @rename($backupLocation, $target); }
            }
            if ($restored || (!$backupCreated && $lifecycleStarted && is_file($target . '/Plugin.php'))) {
                try {
                    if ($ownershipBefore === null) {
                        $this->safe($ownershipPath);
                        if (file_exists($ownershipPath) && (!is_file($ownershipPath) || !unlink($ownershipPath))) {
                            throw new RuntimeException('Cannot restore absent plugin ownership record.');
                        }
                    } else { $this->record($ownershipPath, $ownershipStateBefore); }
                    $ownershipRestored = true;
                } catch (\Throwable $ignored) {
                    // Source/DB recovery must continue; the original graph is retained privately.
                    $ownershipRestored = false;
                }
                Plugin::where('code', $code)->update(['version' => $before['version'], 'config' => $before['config'], 'is_enabled' => false]);
                try {
                    if ($before['is_enabled']) { $this->process(['xboard:plugin-online-update', $code, '--restore']); }
                } catch (\Throwable $ignored) { /* Keep disabled if recovery boot fails. */ }
            }
            if ($backupCreated && !$restored) { Plugin::where('code', $code)->update(['is_enabled' => false]); }
            try {
                $this->record($operation . '/state.json', ['code' => $code, 'before' => $before, 'status' => 'failed',
                    'source_restored' => $restored, 'source_backup' => $backupLocation,
                    'ownership_existed' => $ownershipBefore !== null, 'ownership_restored' => $ownershipRestored]);
            } catch (\Throwable $ignored) { /* Preserve the original failure and retained source backup. */ }
            throw new RuntimeException('插件更新失败：' . $failure->getMessage() . ($restored ? ' 已恢复原插件源码；迁移记录与备份已保留。' : ' 备份记录已保留，请检查插件状态。'), 0, $failure);
        } finally { if (is_dir($stage)) { $this->removeTree($stage); } }
    }

    private function sourceKey(string $code): string
    {
        return isset(self::SOURCE_REPOSITORIES[$code]) ? 'native-' . $code : 'panel';
    }

    private function checkCacheKey(string $code): string
    {
        return 'plugin-online:verified-source:v2:' . hash('sha256', $this->root) . ':' . $this->sourceKey($code);
    }

    /** Cache check metadata briefly; failed sources never become a stale successful response. */
    private function checkedPackage(string $code): array
    {
        $key = $this->checkCacheKey($code);
        try {
            $cached = Cache::get($key);
            if (is_array($cached) && (isset($cached['package']) || isset($cached['error']))) { return $cached; }
            try { $result = ['package' => $this->packageFor($code)]; }
            catch (\Throwable $failure) { $result = ['error' => $failure->getMessage()]; }
            Cache::put($key, $result, isset($result['error']) ? 15 : 300);
            return $result;
        } catch (\Throwable $failure) { return ['error' => $failure->getMessage()]; }
    }

    private function officialSource(): OfficialPluginReleaseSource
    {
        $cache = $this->privateDirectory() . '/cache';
        $this->directory($cache, 0700);
        return new OfficialPluginReleaseSource(fn ($url, $limit, $destination = null) => $this->download($url, $limit, $destination), $cache);
    }

    private function packageFor(string $code): array
    {
        return isset(self::SOURCE_REPOSITORIES[$code]) ? $this->officialSource()->package($code) : $this->package();
    }

    /** One fresh snapshot per source in this CLI operation, including sequential bulk updates. */
    private function updatePackageFor(string $code): array
    {
        if (isset(self::SOURCE_REPOSITORIES[$code])) {
            $this->updateNativeSource ??= $this->officialSource();
            return $this->updateNativeSource->package($code);
        }
        if ($this->updatePanelFailure !== null) { throw $this->updatePanelFailure; }
        try { return $this->updatePanelPackage ??= $this->package(); }
        catch (\Throwable $failure) { $this->updatePanelFailure = $failure; throw $failure; }
    }

    private function resultSource(string $code, array $package): array
    {
        $source = ['source_repository' => self::SOURCE_REPOSITORIES[$code] ?? 'https://github.com/' . self::REPOSITORY,
            'tag' => $package['tag'], 'version' => $package['configs'][$code]['version'], 'archive_sha256' => $package['archive_sha256']];
        if (isset(self::SOURCE_REPOSITORIES[$code])) { $source['commit'] = $package['manifest'][$code]['source_commit']; }
        return $source;
    }

    private function readPluginArchive(string $code, array $package, callable $member): void
    {
        if (isset(self::SOURCE_REPOSITORIES[$code])) { $this->officialSource()->readArchive($package, $member); return; }
        $prefix = self::SOURCES[$code] . '/';
        $this->readArchive($package['archive'], static function (string $name, string $data) use ($prefix, $member): void {
            if (str_starts_with($name, $prefix)) { $member(substr($name, strlen($prefix)), $data); }
        });
    }

    /** Refresh latest metadata, but reuse the already verified content-addressed archive. */
    private function package(): array
    {
        $response = Http::acceptJson()->withHeaders(['User-Agent' => 'Xboard-Plugin-Updater'])
            ->connectTimeout(10)->timeout(25)->get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest');
        if (!$response->successful() || strlen($response->body()) > 2097152) { throw new RuntimeException('无法读取最新正式发行，请稍后重试。'); }
        $release = $response->json();
        $tag = $release['tag_name'] ?? '';
        if (!is_string($tag) || !preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $tag)
            || ($release['draft'] ?? true) || ($release['prerelease'] ?? true) || !is_array($release['assets'] ?? null)) {
            throw new RuntimeException('Invalid latest stable release metadata.');
        }
        $assets = [];
        foreach ($release['assets'] as $asset) {
            if (in_array($asset['name'] ?? null, ['xboard.tar.gz', 'SHA256SUMS'], true)) {
                $name = $asset['name'];
                $expected = 'https://github.com/' . self::REPOSITORY . '/releases/download/' . $tag . '/' . $name;
                if (isset($assets[$name]) || ($asset['browser_download_url'] ?? '') !== $expected) { throw new RuntimeException('Invalid release asset origin.'); }
                $assets[$name] = $asset;
            }
        }
        if (count($assets) !== 2) { throw new RuntimeException('最新正式发行缺少 xboard.tar.gz 或 SHA256SUMS。'); }
        $sums = $this->download($assets['SHA256SUMS']['browser_download_url'], 1048576);
        if (isset($assets['SHA256SUMS']['digest']) && $assets['SHA256SUMS']['digest'] !== 'sha256:' . hash('sha256', $sums)) {
            throw new RuntimeException('Release checksum document API digest mismatch.');
        }
        $hash = self::checksum($sums, 'xboard.tar.gz');
        $apiDigest = $assets['xboard.tar.gz']['digest'] ?? null;
        if ($apiDigest !== null && $apiDigest !== 'sha256:' . $hash) { throw new RuntimeException('Release API digest and SHA256SUMS disagree.'); }
        $size = $assets['xboard.tar.gz']['size'] ?? 0;
        if (!is_int($size) || $size < 1 || $size > 262144000) { throw new RuntimeException('Invalid release archive size.'); }
        $directory = $this->privateDirectory() . '/cache';
        $this->directory($directory, 0700);
        $archive = $directory . '/' . $hash . '.tar.gz';
        $this->safe($archive);
        if (!is_file($archive) || filesize($archive) !== $size || !hash_equals($hash, hash_file('sha256', $archive))) {
            $temporary = tempnam($directory, '.download-');
            if (!$temporary) { throw new RuntimeException('Cannot stage release download.'); }
            try {
                chmod($temporary, 0600);
                $this->download($assets['xboard.tar.gz']['browser_download_url'], $size, $temporary);
                clearstatcache(true, $temporary);
                if (filesize($temporary) !== $size || !hash_equals($hash, hash_file('sha256', $temporary))) { throw new RuntimeException('Release archive checksum mismatch.'); }
                if (!rename($temporary, $archive)) { throw new RuntimeException('Cannot retain verified release archive.'); }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
        }
        $configs = $files = $manifest = [];
        $bundleVersion = null;
        $paths = array_flip(array_map(fn ($path) => $path . '/config.json', self::SOURCES));
        $this->readArchive($archive, function (string $name, string $data) use ($paths, &$configs, &$files, &$manifest, &$bundleVersion): void {
            if ($name === 'bundled-plugins/manifest.json') {
                $lock = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                if (($lock['schema_version'] ?? null) !== 1 || !is_array($lock['plugins'] ?? null) || !is_string($lock['bundle_version'] ?? null)) {
                    throw new RuntimeException('Invalid downloaded plugin source lock.');
                }
                $bundleVersion = $lock['bundle_version'];
                foreach ($lock['plugins'] as $item) {
                    if (!is_string($item['code'] ?? null) || isset($manifest[$item['code']])) { throw new RuntimeException('Invalid downloaded plugin source lock.'); }
                    if (is_array($item['files'] ?? null)) { ksort($item['files']); }
                    $manifest[$item['code']] = $item;
                }
            }
            foreach (self::SOURCES as $code => $prefix) {
                if (str_starts_with($name, $prefix . '/')) { $files[$code][substr($name, strlen($prefix) + 1)] = hash('sha256', $data); break; }
            }
            if (!isset($paths[$name])) { return; }
            $config = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
            $code = $paths[$name];
            if (($config['code'] ?? null) !== $code || !is_string($config['version'] ?? null)
                || !preg_match('/^\d+\.\d+\.\d+$/D', $config['version'])) { throw new RuntimeException('Plugin release identity is invalid.'); }
            $configs[$code] = $config;
        });
        foreach ($files as &$map) { ksort($map); }
        unset($map);
        if (!is_string($bundleVersion) || !preg_match('/^\d+(?:\.\d+){2,4}$/D', $bundleVersion) || strlen($bundleVersion) > 64) {
            throw new RuntimeException('Invalid downloaded plugin bundle version.');
        }
        foreach (self::SOURCE_REPOSITORIES as $code => $repository) {
            if (!isset($configs[$code])) { continue; }
            $item = $manifest[$code] ?? null;
            if (!$item || ($item['directory'] ?? null) !== basename(self::SOURCES[$code])
                || ($item['version'] ?? null) !== $configs[$code]['version']
                || ($item['source_repository'] ?? null) !== $repository
                || !is_string($item['source_commit'] ?? null) || !preg_match('/^[a-f0-9]{40}$/iD', $item['source_commit'])
                || ($item['files'] ?? null) !== $files[$code]) {
                throw new RuntimeException('Downloaded plugin source lock identity or files are invalid.');
            }
        }
        return ['archive' => $archive, 'archive_sha256' => $hash, 'configs' => $configs, 'files' => $files, 'manifest' => $manifest, 'bundle_version' => $bundleVersion, 'tag' => $tag,
            'release_url' => 'https://github.com/' . self::REPOSITORY . '/releases/tag/' . $tag];
    }

    public static function checksum(string $text, string $filename): string
    {
        $matches = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (preg_match('/^([a-fA-F0-9]{64}) [ *](.+)$/D', $line, $parts) && $parts[2] === $filename) { $matches[] = strtolower($parts[1]); }
        }
        if (count($matches) !== 1) { throw new RuntimeException('Release checksum must contain exactly one matching archive.'); }
        return $matches[0];
    }

    private function download(string $url, int $limit, ?string $destination = null): string
    {
        $request = Http::withHeaders(['User-Agent' => 'Xboard-Plugin-Updater'])->connectTimeout(10)->timeout(180)
            ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']],
                'progress' => function ($total, $received) use ($limit): void {
                    if ($received > $limit || $total > $limit) { throw new RuntimeException('Release download exceeds its size limit.'); }
                }]);
        if ($destination !== null) { $request = $request->sink($destination); }
        $response = $request->get($url);
        if (!$response->successful()) { throw new RuntimeException('无法下载正式发行文件，请稍后重试。'); }
        $body = $destination === null ? $response->body() : '';
        if (strlen($body) > $limit) { throw new RuntimeException('Release download exceeds its size limit.'); }
        return $body;
    }

    /** Read the USTAR format emitted by build-private-release.py without extracting arbitrary paths. */
    public function readArchive(string $archive, callable $member): void
    {
        $stream = gzopen($archive, 'rb');
        if (!$stream) { throw new RuntimeException('Cannot open release archive.'); }
        $seen = [];
        $total = 0;
        $read = static function (int $length) use ($stream): string {
            $data = '';
            while (strlen($data) < $length && !gzeof($stream)) {
                $part = gzread($stream, min(1048576, $length - strlen($data)));
                if ($part === false || $part === '') { break; }
                $data .= $part;
            }
            if (strlen($data) !== $length) { throw new RuntimeException('Truncated release archive.'); }
            return $data;
        };
        try {
            while (true) {
                $header = $read(512);
                if ($header === str_repeat("\0", 512)) {
                    if ($read(512) !== str_repeat("\0", 512)) { throw new RuntimeException('Invalid archive terminator.'); }
                    break;
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $prefix = rtrim(substr($header, 345, 155), "\0");
                if ($prefix !== '') { $name = $prefix . '/' . $name; }
                $type = substr($header, 156, 1);
                $sizeText = trim(substr($header, 124, 12), " \0");
                $sumText = trim(substr($header, 148, 8), " \0");
                if (!preg_match('/^[0-7]+$/D', $sizeText) || !preg_match('/^[0-7]+$/D', $sumText)
                    || !in_array($type, ["\0", '0', '5'], true)
                    || !str_starts_with(substr($header, 257, 6), 'ustar')) { throw new RuntimeException('Unsupported release archive member.'); }
                $check = substr_replace($header, str_repeat(' ', 8), 148, 8);
                if (array_sum(unpack('C*', $check)) !== octdec($sumText)) { throw new RuntimeException('Invalid archive header checksum.'); }
                self::assertMember($name);
                if (isset($seen[$name]) || count($seen) >= 30000) { throw new RuntimeException('Duplicate or excessive archive members.'); }
                $seen[$name] = true;
                $size = octdec($sizeText);
                $total += $size;
                if ($size > 33554432 || $total > 536870912 || ($type === '5' && $size !== 0)) { throw new RuntimeException('Release archive exceeds its size limit.'); }
                $data = $size ? $read((int) $size) : '';
                if ($size % 512) { $read(512 - ($size % 512)); }
                if ($type !== '5') { $member($name, $data); }
            }
        } finally { gzclose($stream); }
    }

    public static function assertMember(string $name): void
    {
        if ($name === '' || strlen($name) > 512 || str_starts_with($name, '/') || str_contains($name, '\\')
            || str_contains($name, ':') || preg_match('/[\x00-\x1f\x7f]/', $name)
            || preg_match('~(?:^|/)(?:\.{1,2}|\.git|\.env)(?:/|$)~', $name) || str_contains($name, '//')) {
            throw new RuntimeException('Unsafe release archive path.');
        }
    }

    private function process(array $arguments, int $timeout = 180): string
    {
        $php = (new PhpExecutableFinder())->find(false);
        if (!$php) { throw new RuntimeException('找不到站点 PHP CLI，请配置 PHP_BINARY。'); }
        $process = new Process(array_merge([$php, $this->root . '/artisan'], $arguments), $this->root);
        $process->setTimeout($timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput() . "\n" . $process->getOutput());
            throw new RuntimeException(mb_substr($message ?: 'Plugin update command failed.', 0, 2000));
        }
        return $process->getOutput();
    }

    private function privateDirectory(): string
    {
        $path = $this->root . '/storage/app/private/plugin-online';
        $this->directory($path, 0700);
        return $path;
    }

    private function sourceMatches(string $code, array $files, ?string $version = null, ?string $archiveSha256 = null): bool
    {
        $target = app(PluginManager::class)->getPluginPath($code);
        $this->safe($target);
        if (!is_dir($target)) { return false; }
        $this->assertTree($target);
        $actual = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile()) {
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($target) + 1));
                $actual[$relative] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($actual);
        if ($actual === $files) { return true; }
        return isset(self::SOURCE_REPOSITORIES[$code]) && $version !== null
            && BundledPluginInstaller::matchesApprovedRuntimeSource($code, $version, $actual, $files, $archiveSha256);
    }

    private function safe(string $path): void
    {
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) { throw new RuntimeException('Refusing a symbolic link in plugin update paths.'); }
        }
    }

    private function directory(string $path, int $mode): void
    {
        $this->safe($path);
        if ((!is_dir($path) && !mkdir($path, $mode, true)) || !is_writable($path)) { throw new RuntimeException('Plugin update path is not writable by the site user.'); }
        if ($mode === 0700) { chmod($path, $mode); }
    }

    private function assertTree(string $path): void
    {
        $this->safe($path);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) { throw new RuntimeException('Plugin sources may not contain links or special files.'); }
        }
    }

    private function removeTree(string $path): void
    {
        $this->assertTree($path);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            if ($entry->isDir()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    }

    private function record(string $path, array $value): void
    {
        $this->safe($path);
        $temporary = tempnam(dirname($path), '.record-');
        if (!$temporary) { throw new RuntimeException('Cannot write plugin update recovery record.'); }
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
                || !rename($temporary, $path)) { throw new RuntimeException('Cannot write plugin update recovery record.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}
