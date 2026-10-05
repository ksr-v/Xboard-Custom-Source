<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class PrivateArchiveUpdateService
{
    private const MAX_ARCHIVE_BYTES = 262144000;

    public function checkForUpdates(): array
    {
        $currentCommit = $this->currentCommit();
        if ($currentCommit === 'unknown') {
            return $this->unavailable($currentCommit);
        }

        try {
            $baseUrl = $this->baseUrl();
            if ($baseUrl === '') {
                return $this->unavailable($currentCommit);
            }
            $response = $this->request()->get($baseUrl . '/latest.json');
            if (!$response->successful()) {
                return $this->unavailable($currentCommit);
            }

            $manifest = $response->json();
            $this->validateManifest($manifest);
            $commit = strtolower($manifest['commit']);
            $shortCommit = substr($commit, 0, 7);
            $hasUpdate = $shortCommit !== $currentCommit;
            $archiveUrl = $baseUrl . '/releases/' . rawurlencode($manifest['version']) . '/xboard.tar.gz';

            return [
                'has_update' => $hasUpdate,
                'is_local_newer' => false,
                'latest_version' => $hasUpdate ? $shortCommit : $currentCommit,
                'current_version' => $currentCommit,
                'update_logs' => $hasUpdate ? [[
                    'version' => $shortCommit,
                    'message' => $manifest['message'],
                    'author' => $manifest['author'],
                    'date' => $manifest['published_at'],
                    'is_local' => false,
                ]] : [],
                'download_url' => $archiveUrl,
                'published_at' => $manifest['published_at'],
                'author' => $manifest['author'],
                'archive_sha256' => strtolower($manifest['sha256']),
                'archive_size' => (int) $manifest['size'],
                'archive_commit' => $commit,
                'archive_version' => $manifest['version'],
            ];
        } catch (\Throwable $exception) {
            report($exception);
            return $this->unavailable($currentCommit);
        }
    }

    public function applyUpdate(array $updateInfo): void
    {
        $baseUrl = $this->baseUrl();
        $version = (string) ($updateInfo['archive_version'] ?? '');
        $commit = (string) ($updateInfo['archive_commit'] ?? '');
        $expectedHash = (string) ($updateInfo['archive_sha256'] ?? '');
        $expectedSize = (int) ($updateInfo['archive_size'] ?? 0);
        $expectedUrl = $baseUrl . '/releases/' . rawurlencode($version) . '/xboard.tar.gz';

        if ($baseUrl === '' || $version === '' || $updateInfo['download_url'] !== $expectedUrl
            || !preg_match('/^[a-f0-9]{40}$/i', $commit)
            || !preg_match('/^[a-f0-9]{64}$/i', $expectedHash)
            || $expectedSize < 1 || $expectedSize > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Private release metadata is invalid.');
        }

        $workDir = storage_path('app/private/update-' . Str::uuid());
        File::ensureDirectoryExists($workDir, 0700);
        $archivePath = $workDir . '/release.tar.gz';
        $extractPath = $workDir . '/release';
        $backupPath = $workDir . '/backup';
        $basePath = base_path();
        $changedFiles = [];
        $newFiles = [];
        $markerPath = storage_path('app/private/xboard-build.json');
        $markerExisted = File::exists($markerPath);
        $markerBackup = $markerExisted ? File::get($markerPath) : null;

        try {
            $response = $this->request()->withOptions([
                'allow_redirects' => false,
                'sink' => $archivePath,
            ])->get($expectedUrl);
            if (!$response->successful() || !File::exists($archivePath)
                || File::size($archivePath) !== $expectedSize
                || hash_file('sha256', $archivePath) !== strtolower($expectedHash)) {
                throw new RuntimeException('Private release archive failed size or SHA-256 validation.');
            }

            File::ensureDirectoryExists($extractPath, 0700);
            File::ensureDirectoryExists($backupPath, 0700);
            $files = $this->validateArchive($archivePath);
            $extract = Process::run(['tar', '-xzf', $archivePath, '-C', $extractPath, '--no-same-owner']);
            if (!$extract->successful()) {
                throw new RuntimeException('Private release archive could not be extracted.');
            }
            $this->assertDependenciesUnchanged($extractPath, $basePath);

            foreach ($files as $relativePath) {
                $source = $extractPath . '/' . $relativePath;
                if (!File::isFile($source)) {
                    continue;
                }
                $destination = $basePath . '/' . $relativePath;
                $this->assertSafeDestination($basePath, $relativePath);
                if (File::exists($destination)) {
                    $backup = $backupPath . '/' . $relativePath;
                    File::ensureDirectoryExists(dirname($backup), 0700);
                    if (!File::copy($destination, $backup)) {
                        throw new RuntimeException('Could not back up an application file.');
                    }
                    chmod($backup, fileperms($destination) & 0777);
                    $changedFiles[] = $relativePath;
                } else {
                    $newFiles[] = $relativePath;
                }

                File::ensureDirectoryExists(dirname($destination));
                if (!File::copy($source, $destination)) {
                    throw new RuntimeException('Could not install an application file.');
                }
                chmod($destination, (fileperms($source) & 0111) !== 0 ? 0755 : 0644);
            }

            File::ensureDirectoryExists(dirname($markerPath), 0700);
            File::put($markerPath, json_encode([
                'commit' => strtolower($commit),
                'version' => $version,
                'updated_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable $exception) {
            foreach ($newFiles as $relativePath) {
                File::delete($basePath . '/' . $relativePath);
            }
            foreach ($changedFiles as $relativePath) {
                $backup = $backupPath . '/' . $relativePath;
                if (File::exists($backup)) {
                    File::copy($backup, $basePath . '/' . $relativePath);
                    chmod($basePath . '/' . $relativePath, fileperms($backup) & 0777);
                }
            }
            if ($markerExisted) {
                File::put($markerPath, $markerBackup);
            } else {
                File::delete($markerPath);
            }
            throw $exception;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function validateArchive(string $archivePath): array
    {
        $listing = Process::run(['tar', '-tzf', $archivePath]);
        $details = Process::run(['tar', '-tvzf', $archivePath]);
        if (!$listing->successful() || !$details->successful()) {
            throw new RuntimeException('Private release archive is not a valid tar.gz file.');
        }

        $paths = [];
        foreach (preg_split('/\r?\n/', trim($listing->output())) as $path) {
            while (str_starts_with($path, './')) {
                $path = substr($path, 2);
            }
            if ($path === '') {
                continue;
            }
            if (str_contains($path, "\n") || str_contains($path, "\\") || str_starts_with($path, '/')
                || in_array('..', explode('/', rtrim($path, '/')), true)
                || in_array('', explode('/', rtrim($path, '/')), true)
                || $this->isProtectedPath($path)) {
                throw new RuntimeException('Private release archive contains an unsafe path.');
            }
            $paths[] = rtrim($path, '/');
        }

        foreach (preg_split('/\r?\n/', trim($details->output())) as $line) {
            if ($line !== '' && !preg_match('/^[\-d][rwxStTs\-]{9}\s/', $line)) {
                throw new RuntimeException('Private release archive may not contain links or special files.');
            }
        }

        foreach (['artisan', 'composer.json'] as $required) {
            if (!in_array($required, $paths, true)) {
                throw new RuntimeException('Private release archive is missing required application files.');
            }
        }
        foreach (['app/', 'config/', 'routes/'] as $requiredPrefix) {
            if (!collect($paths)->contains(fn(string $path) => str_starts_with($path, $requiredPrefix))) {
                throw new RuntimeException('Private release archive is missing an application directory.');
            }
        }

        return array_values(array_unique(array_filter($paths, fn(string $path) => !str_ends_with($path, '/'))));
    }

    private function assertSafeDestination(string $basePath, string $relativePath): void
    {
        $segments = explode('/', $relativePath);
        $current = $basePath;
        foreach ($segments as $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new RuntimeException('Private release destination contains a symbolic link.');
            }
        }
    }

    private function assertDependenciesUnchanged(string $releasePath, string $basePath): void
    {
        foreach (['composer.json', 'composer.lock'] as $file) {
            $releaseFile = $releasePath . '/' . $file;
            $currentFile = $basePath . '/' . $file;
            if (!File::exists($releaseFile) || !File::exists($currentFile)
                || hash_file('sha256', $releaseFile) !== hash_file('sha256', $currentFile)) {
                throw new RuntimeException('Private release changes Composer dependencies; publish a full dependency bundle instead.');
            }
        }
    }

    private function isProtectedPath(string $path): bool
    {
        $path = trim($path, '/');
        return in_array($path, ['.env', '.git', 'storage', 'vendor', 'public/storage', 'bootstrap/cache'], true)
            || str_starts_with($path, '.env/')
            || str_starts_with($path, '.git/')
            || str_starts_with($path, 'storage/')
            || str_starts_with($path, 'vendor/')
            || str_starts_with($path, 'public/storage/')
            || str_starts_with($path, 'bootstrap/cache/');
    }

    private function validateManifest(mixed $manifest): void
    {
        if (!is_array($manifest)
            || !isset($manifest['version'], $manifest['commit'], $manifest['sha256'], $manifest['size'], $manifest['published_at'], $manifest['author'], $manifest['message'])
            || !preg_match('/^[0-9]{8}-[a-f0-9]{7,40}$/i', (string) $manifest['version'])
            || !preg_match('/^[a-f0-9]{40}$/i', (string) $manifest['commit'])
            || !preg_match('/^[a-f0-9]{64}$/i', (string) $manifest['sha256'])
            || !str_starts_with(strtolower((string) $manifest['commit']), strtolower(substr((string) $manifest['version'], 9)))
            || (int) $manifest['size'] < 1 || (int) $manifest['size'] > self::MAX_ARCHIVE_BYTES
            || !is_string($manifest['published_at']) || strtotime($manifest['published_at']) === false
            || !is_string($manifest['author']) || strlen($manifest['author']) > 200
            || !is_string($manifest['message']) || strlen($manifest['message']) > 4000) {
            throw new RuntimeException('Private release manifest is invalid.');
        }
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::acceptJson()->timeout(30)->withOptions(['allow_redirects' => false]);
        $token = trim((string) config('orphan.xboard_update_token', ''));
        return $token === '' ? $request : $request->withToken($token);
    }

    private function baseUrl(): string
    {
        $url = rtrim(trim((string) config('orphan.xboard_update_base_url', '')), '/');
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? ''), '[]') : '';
        $loopbackHttp = ($parts['scheme'] ?? '') === 'http' && in_array($host, ['127.0.0.1', '::1'], true);
        if (!is_array($parts) || (($parts['scheme'] ?? '') !== 'https' && !$loopbackHttp) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Private update base URL must use HTTPS, or loopback HTTP, without credentials.');
        }
        return $url;
    }

    private function currentCommit(): string
    {
        $markerPath = storage_path('app/private/xboard-build.json');
        if (File::exists($markerPath)) {
            $marker = json_decode(File::get($markerPath), true);
            $commit = is_array($marker) ? (string) ($marker['commit'] ?? '') : '';
        } else {
            $commit = trim((string) config('orphan.xboard_build_commit', ''));
        }
        return preg_match('/^[a-f0-9]{7,40}$/i', $commit) ? substr(strtolower($commit), 0, 7) : 'unknown';
    }

    private function unavailable(string $currentCommit): array
    {
        return [
            'has_update' => false,
            'is_local_newer' => false,
            'latest_version' => $currentCommit,
            'current_version' => $currentCommit,
            'update_logs' => [],
            'download_url' => '',
            'published_at' => '',
            'author' => '',
        ];
    }
}