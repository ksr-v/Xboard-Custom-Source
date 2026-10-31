<?php

namespace App\Services\Plugin;

use Closure;
use RuntimeException;
use ZipArchive;

/**
 * Verified release ZIPs from five fixed maintainer repositories.
 * Transport/cache ownership and the installer's existing sync.lock remain with its caller.
 * The download callable has OnlinePluginUpdater::download's (url, limit, destination) contract.
 */
final class OfficialPluginReleaseSource
{
    private const SOURCES = [
        'node_endpoint' => ['ksr-v/Xboard-Independent-NodeEndpoint', 'NodeEndpoint-%s.zip', 'NodeEndpoint', 'NodeEndpoint', 'SHA256SUMS'],
        'smart_expiry' => ['ksr-v/xb-SmartExpiry', 'SmartExpiry-%s-full.zip', 'SmartExpiry', 'SmartExpiry', null],
        'qrcodeextend' => ['ksr-v/xb-qrcode-extend', 'qrcodeextend-%s.zip', 'Qrcodeextend', 'Qrcodeextend', 'SHA256SUMS'],
        'flowscope' => ['ksr-v/xb-flowscope', 'flowscope-%s.zip', 'Flowscope', 'Flowscope', 'SHA256SUMS-v%s'],
        'subinfo_customizer' => ['ksr-v/xb-SubInfoCustomizer', 'SubInfoCustomizer-v%s.zip', 'SubInfoCustomizer', 'SubinfoCustomizer', 'SHA256SUMS'],
    ];
    private const MAX_ARCHIVE = 33554432;
    private const MAX_FILE = 16777216;
    private const MAX_TOTAL = 134217728;
    private Closure $download;
    private array $packages = [];

    public function __construct(callable $download, private string $cacheDirectory)
    {
        $this->download = Closure::fromCallable($download);
        $this->safeCachePath($cacheDirectory);
        if (!is_dir($cacheDirectory) || !is_writable($cacheDirectory)) {
            throw new RuntimeException('Pass the existing private, site-owned online-update cache directory.');
        }
    }

    public function supports(string $code): bool
    {
        return isset(self::SOURCES[$code]);
    }

    /** Resolve once per operation; readArchive() never resolves latest again. */
    public function package(string $code): array
    {
        if (!$this->supports($code)) { throw new RuntimeException('This plugin uses the panel archive or a manual source.'); }
        if (isset($this->packages[$code])) { return $this->packages[$code]; }
        [$repository, $pattern, $root, $directory, $sumPattern] = self::SOURCES[$code];
        $release = $this->latest($repository);
        $tag = $release['tag_name'];
        $version = substr($tag, 1);
        $filename = sprintf($pattern, $version);
        $asset = $this->asset($release, $repository, $filename, self::MAX_ARCHIVE);
        $resolved = $this->resolveTag($repository, $tag);
        $digest = $this->digest($asset);
        if ($sumPattern !== null) {
            $sumName = sprintf($sumPattern, $version);
            $sumAsset = $this->asset($release, $repository, $sumName, 262144);
            $sums = ($this->download)($sumAsset['browser_download_url'], 262144, null);
            $this->verifyBytes($sums, $sumAsset);
            $sha = OnlinePluginUpdater::checksum($sums, $filename);
            if ($digest !== null && !hash_equals($digest, $sha)) {
                throw new RuntimeException('Release checksum and GitHub archive digest disagree.');
            }
            $checksumSource = $sumName;
        } else {
            // SmartExpiry currently publishes no checksum sidecar; GitHub supplies its SHA256 digest.
            if ($digest === null) { throw new RuntimeException('Official release has no SHA256 verification source.'); }
            $sha = $digest;
            $checksumSource = 'github-asset-digest';
        }
        $archive = $this->cachedArchive($asset, $sha);
        $package = ['archive' => $archive, 'archive_sha256' => $sha, 'archive_size' => $asset['size'],
            'code' => $code, 'root' => $root, 'directory' => $directory, 'version' => $version,
            'tag' => $tag, 'release_url' => $release['html_url']];
        $files = [];
        $config = null;
        $this->readArchive($package, static function (string $name, string $data) use (&$files, &$config): void {
            $files[$name] = hash('sha256', $data);
            if ($name === 'config.json') { $config = json_decode($data, true, 32, JSON_THROW_ON_ERROR); }
        });
        if (!is_array($config) || ($config['code'] ?? null) !== $code || ($config['version'] ?? null) !== $version
            || !isset($files['Plugin.php'])) { throw new RuntimeException('Official plugin config identity or version is invalid.'); }
        if ($resolved !== $this->resolveTag($repository, $tag)) {
            throw new RuntimeException('Official release tag changed during verification; retry with a fresh operation.');
        }
        ksort($files);
        $package['configs'] = [$code => $config];
        $package['files'] = [$code => $files];
        $package['manifest'] = [$code => ['code' => $code, 'directory' => $directory, 'version' => $version,
            'source_repository' => 'https://github.com/' . $repository,
            // This is a real resolved Git commit, never a digest-derived invented commit.
            // Built ZIPs may include generated files: provenance explicitly describes an artifact.
            'source_commit' => $resolved['commit'], 'files' => $files,
            'source_provenance' => ['kind' => 'github-release-asset', 'release_id' => $release['id'],
                'release_tag' => $tag, 'tag_object' => $resolved['object'], 'tag_commit' => $resolved['commit'],
                'asset_id' => $asset['id'], 'asset_name' => $filename, 'archive_sha256' => $sha,
                'archive_size' => $asset['size'], 'checksum_source' => $checksumSource]]];
        return $this->packages[$code] = $package;
    }

    /** Return validated relative regular files, never extract ZIP paths onto the filesystem. */
    public function readArchive(array $package, callable $member): void
    {
        $code = $package['code'] ?? '';
        if (!$this->supports($code) || ($package['root'] ?? null) !== self::SOURCES[$code][2]
            || !is_string($package['archive_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $package['archive_sha256'])
            || !is_int($package['archive_size'] ?? null)) { throw new RuntimeException('Invalid pinned plugin package.'); }
        $this->safeCachePath($package['archive']);
        $this->verifyArchive($package['archive'], $package['archive_size'], $package['archive_sha256']);
        $zip = new ZipArchive();
        if ($zip->open($package['archive'], ZipArchive::CHECKCONS) !== true) { throw new RuntimeException('Invalid plugin ZIP archive.'); }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > 5000) { throw new RuntimeException('Invalid plugin ZIP member count.'); }
            $seen = [];
            $casing = [];
            $regular = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_UNCHANGED);
                if ($stat === false) { throw new RuntimeException('Unreadable ZIP member.'); }
                $name = $stat['name'];
                OnlinePluginUpdater::assertMember($name);
                OnlinePluginUpdater::assertMember(strtolower($name));
                if (!preg_match('/^[\x20-\x7e]+$/D', $name)) { throw new RuntimeException('Non-canonical ZIP member name.'); }
                $isDirectory = str_ends_with($name, '/');
                $canonical = rtrim($name, '/');
                $folded = strtolower($canonical);
                if (isset($seen[$folded])) { throw new RuntimeException('Duplicate or case-colliding ZIP member.'); }
                $seen[$folded] = $isDirectory;
                for ($cursor = $canonical; $cursor !== '.'; $cursor = dirname($cursor)) {
                    $key = strtolower($cursor);
                    if (isset($casing[$key]) && $casing[$key] !== $cursor) {
                        throw new RuntimeException('ZIP has inconsistent parent-directory casing.');
                    }
                    $casing[$key] = $cursor;
                }
                foreach (explode('/', $canonical) as $segment) {
                    if (preg_match('/[. ]$/D', $segment) || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/iD', $segment)) {
                        throw new RuntimeException('Non-canonical ZIP path segment.');
                    }
                }
                if ($canonical !== $package['root'] && !str_starts_with($canonical, $package['root'] . '/')) {
                    throw new RuntimeException('ZIP contains files outside the expected plugin root.');
                }
                if (!$isDirectory && $canonical === $package['root']) { throw new RuntimeException('ZIP root must be a directory.'); }
                if (!$zip->getExternalAttributesIndex($i, $system, $attributes)) { throw new RuntimeException('Cannot inspect ZIP member type.'); }
                $mode = ($attributes >> 16) & 0170000;
                if (!in_array($mode, [0, 0100000, 0040000], true)
                    || ($mode === 0100000 && $isDirectory) || ($mode === 0040000 && !$isDirectory)
                    || (($attributes & 0x10) !== 0 && !$isDirectory)
                    || ($stat['encryption_method'] ?? 0) !== 0 || !in_array($stat['comp_method'], [0, 8], true)) {
                    throw new RuntimeException('ZIP links, special files, encryption, and unsupported compression are forbidden.');
                }
                $size = $stat['size'];
                if (!is_int($size) || $size < 0 || $size > self::MAX_FILE || ($total += $size) > self::MAX_TOTAL
                    || ($isDirectory && $size !== 0)) { throw new RuntimeException('Plugin ZIP exceeds its expanded size limit.'); }
                if (!$isDirectory) { $regular[] = [$i, $canonical, $size, $stat['crc']]; }
            }
            // Validate all names and parent/file conflicts before handing any content to the caller.
            foreach ($regular as [, $name]) {
                for ($parent = dirname($name); $parent !== '.'; $parent = dirname($parent)) {
                    if (array_key_exists(strtolower($parent), $seen) && $seen[strtolower($parent)] === false) {
                        throw new RuntimeException('ZIP has conflicting file and directory paths.');
                    }
                }
            }
            foreach ($regular as [$index, $name, $size, $crc]) {
                $data = $zip->getFromIndex($index, 0, ZipArchive::FL_UNCHANGED);
                if ($data === false || strlen($data) !== $size || hash('crc32b', $data) !== sprintf('%08x', $crc & 0xffffffff)) {
                    throw new RuntimeException('Corrupt plugin ZIP member.');
                }
                $member(substr($name, strlen($package['root']) + 1), $data);
            }
        } finally { $zip->close(); }
    }

    private function latest(string $repository): array
    {
        $latest = null;
        // The Node repository also publishes tools/root-installer releases. Only vX.Y.Z is a plugin release.
        // Scan to completion or fail: a truncated list must never silently select an older plugin.
        for ($page = 1; $page <= 10; $page++) {
            $releases = $this->json('https://api.github.com/repos/' . $repository . '/releases?per_page=100&page=' . $page);
            if (!array_is_list($releases) || count($releases) > 100) { throw new RuntimeException('Invalid official release list.'); }
            foreach ($releases as $release) {
                if (!is_array($release) || ($release['draft'] ?? null) !== false || ($release['prerelease'] ?? null) !== false
                    || !is_string($release['tag_name'] ?? null)
                    || strlen($release['tag_name']) > 64
                    || !preg_match('/^v(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/D', $release['tag_name'])) { continue; }
                if ($latest === null || version_compare(substr($release['tag_name'], 1), substr($latest['tag_name'], 1), '>')) { $latest = $release; }
            }
            if (count($releases) < 100) {
                if ($latest === null || !is_int($latest['id'] ?? null) || $latest['id'] <= 0
                    || ($latest['html_url'] ?? null) !== 'https://github.com/' . $repository . '/releases/tag/' . $latest['tag_name']
                    || !is_array($latest['assets'] ?? null)) { throw new RuntimeException('No valid stable official plugin release.'); }
                return $latest;
            }
        }
        throw new RuntimeException('Official release listing exceeded the supported bound; latest is undetermined.');
    }

    private function asset(array $release, string $repository, string $name, int $limit): array
    {
        $matches = array_values(array_filter($release['assets'], static fn ($asset) => is_array($asset) && ($asset['name'] ?? null) === $name));
        if (count($matches) !== 1) { throw new RuntimeException('Newest official plugin release is missing a unique required asset: ' . $name); }
        $asset = $matches[0];
        if (!is_int($asset['id'] ?? null) || $asset['id'] <= 0 || ($asset['state'] ?? null) !== 'uploaded'
            || !is_int($asset['size'] ?? null) || $asset['size'] <= 0 || $asset['size'] > $limit
            || ($asset['browser_download_url'] ?? null) !== 'https://github.com/' . $repository . '/releases/download/' . $release['tag_name'] . '/' . $name) {
            throw new RuntimeException('Invalid official release asset identity, URL, state, or size.');
        }
        return $asset;
    }

    private function resolveTag(string $repository, string $tag): array
    {
        $ref = $this->json('https://api.github.com/repos/' . $repository . '/git/ref/tags/' . rawurlencode($tag));
        if (($ref['ref'] ?? null) !== 'refs/tags/' . $tag) { throw new RuntimeException('Invalid official tag reference.'); }
        $object = $ref['object'] ?? [];
        $initial = $object['sha'] ?? null;
        $seen = [];
        for ($depth = 0; $depth < 6; $depth++) {
            $sha = $object['sha'] ?? '';
            $type = $object['type'] ?? '';
            if (!is_string($sha) || !preg_match('/^[a-f0-9]{40}$/D', $sha) || isset($seen[$sha])) { break; }
            $seen[$sha] = true;
            if ($type === 'commit') { return ['commit' => $sha, 'object' => $initial]; }
            if ($type !== 'tag') { break; }
            $annotated = $this->json('https://api.github.com/repos/' . $repository . '/git/tags/' . $sha);
            if (($annotated['sha'] ?? null) !== $sha) { break; }
            $object = $annotated['object'] ?? [];
        }
        throw new RuntimeException('Official tag cannot be resolved to a real Git commit.');
    }

    private function json(string $url): array
    {
        $result = json_decode(($this->download)($url, 4194304, null), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result)) { throw new RuntimeException('Invalid official GitHub response.'); }
        return $result;
    }

    private function digest(array $asset): ?string
    {
        $digest = $asset['digest'] ?? null;
        if ($digest === null) { return null; }
        if (!is_string($digest) || !preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $match)) {
            throw new RuntimeException('Invalid official asset SHA256 digest.');
        }
        return $match[1];
    }

    private function verifyBytes(string $bytes, array $asset): void
    {
        $digest = $this->digest($asset);
        if (strlen($bytes) !== $asset['size'] || ($digest !== null && !hash_equals($digest, hash('sha256', $bytes)))) {
            throw new RuntimeException('Official checksum file size or SHA256 is invalid.');
        }
    }

    private function cachedArchive(array $asset, string $sha): string
    {
        $path = rtrim($this->cacheDirectory, '/\\') . '/' . $sha . '.zip';
        $this->safeCachePath($path);
        if (file_exists($path)) { $this->verifyArchive($path, $asset['size'], $sha); return $path; }
        $temporary = tempnam($this->cacheDirectory, '.release-');
        if ($temporary === false) { throw new RuntimeException('Cannot create private plugin download.'); }
        try {
            chmod($temporary, 0600);
            ($this->download)($asset['browser_download_url'], self::MAX_ARCHIVE, $temporary);
            $this->safeCachePath($temporary);
            $this->verifyArchive($temporary, $asset['size'], $sha);
            $this->safeCachePath($path);
            if (file_exists($path)) { $this->verifyArchive($path, $asset['size'], $sha); }
            elseif (!rename($temporary, $path)) { throw new RuntimeException('Cannot cache verified official plugin.'); }
            return $path;
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }

    private function verifyArchive(string $path, int $size, string $sha): void
    {
        clearstatcache(true, $path);
        if (!is_file($path) || $size <= 0 || $size > self::MAX_ARCHIVE || filesize($path) !== $size
            || !hash_equals($sha, hash_file('sha256', $path))) { throw new RuntimeException('Official plugin archive size or SHA256 is invalid.'); }
    }

    private function safeCachePath(string $path): void
    {
        for ($cursor = $path; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) { throw new RuntimeException('Plugin release cache may not contain symlinks.'); }
        }
    }
}
