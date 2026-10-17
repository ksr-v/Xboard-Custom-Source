<?php

namespace App\Services;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\File;

class UpdateService
{
    const UPDATE_CHECK_INTERVAL = 86400; // 24 hours
    const CACHE_UPDATE_INFO = 'UPDATE_INFO';
    const CACHE_LAST_CHECK = 'LAST_UPDATE_CHECK';
    const CACHE_UPDATE_LOCK = 'UPDATE_LOCK';
    const CACHE_VERSION = 'CURRENT_VERSION';
    const CACHE_VERSION_DATE = 'CURRENT_VERSION_DATE';
    
    /**
     * Get current version from cache or generate new one
     */
    public function getCurrentVersion(): string
    {
        $date = Cache::get(self::CACHE_VERSION_DATE) ?? date('Ymd');
        $hash = Cache::rememberForever(self::CACHE_VERSION, function () {
            return $this->getCurrentCommit();
        });
        return $date . '-' . $hash;
    }

    /**
     * Update version cache
     */
    public function updateVersionCache(): void
    {
        $markerPath = storage_path('app/private/xboard-build.json');
        if (config('orphan.xboard_update_mode') === 'archive' && File::isFile($markerPath)) {
            $marker = json_decode(File::get($markerPath), true);
            if (is_array($marker) && preg_match('/^[a-f0-9]{40}$/iD', $marker['commit'] ?? '')) {
                preg_match('/^(\d{8})[-_]/', (string) ($marker['version'] ?? ''), $date);
                Cache::forever(self::CACHE_VERSION_DATE, $date[1] ?? date('Ymd'));
                Cache::forever(self::CACHE_VERSION, substr(strtolower($marker['commit']), 0, 7));
                return;
            }
        }
        try {
            $result = Process::run('git log -1 --format=%cd:%H --date=format:%Y%m%d');
            if ($result->successful()) {
                list($date, $hash) = explode(':', trim($result->output()));
                Cache::forever(self::CACHE_VERSION_DATE, $date);
                Cache::forever(self::CACHE_VERSION, substr($hash, 0, 7));
                // Log::info('Version cache updated: ' . $date . '-' . substr($hash, 0, 7));
                return;
            }
        } catch (\Exception $e) {
            Log::error('Failed to get version with date: ' . $e->getMessage());
        }

        // Fallback
        Cache::forever(self::CACHE_VERSION_DATE, date('Ymd'));
        $fallbackHash = $this->getCurrentCommit();
        Cache::forever(self::CACHE_VERSION, $fallbackHash);
        Log::info('Version cache updated (fallback): ' . date('Ymd') . '-' . $fallbackHash);
    }

    public function checkForUpdates(): array
    {
        if (config('orphan.xboard_update_mode') === 'archive') {
            return app(PrivateArchiveUpdateService::class)->checkForUpdates();
        }

        $currentCommit = 'unknown';
        try {
            // Get current version commit
            $currentCommit = $this->getCurrentCommit();
            if ($currentCommit === 'unknown') {
                // If unable to get current commit, try to get the first commit
                $currentCommit = $this->getFirstCommit();
            }
            // Get local git logs
            $localLogs = $this->getLocalGitLogs();
            if (empty($localLogs)) {
                Log::error('Failed to get local git logs');
                return $this->getUnavailableUpdateInfo($currentCommit);
            }

            $repository = trim((string) config('orphan.xboard_repository', ''));
            $ref = trim((string) config('orphan.xboard_ref', 'master'));
            if ($repository === '' || $currentCommit === 'unknown') {
                return $this->getUnavailableUpdateInfo($currentCommit);
            }

            $request = Http::withHeaders([
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Xboard-Private-Update-Checker'
            ]);
            $token = (string) config('orphan.xboard_update_token', '');
            if ($token !== '') {
                $request = $request->withToken($token);
            }
            $response = $request->get(
                'https://api.github.com/repos/' . $repository . '/commits',
                ['sha' => $ref, 'per_page' => 50]
            );

            if ($response->successful()) {
                $commits = $response->json();
                
                if (empty($commits) || !is_array($commits)) {
                    Log::error('Invalid GitHub response format');
                    return $this->getUnavailableUpdateInfo($currentCommit);
                }
                
                $latestCommit = $this->formatCommitHash($commits[0]['sha']);
                $currentIndex = -1;
                $updateLogs = [];
                
                // First, find the current version position in remote commit history
                foreach ($commits as $index => $commit) {
                    $shortSha = $this->formatCommitHash($commit['sha']);
                    if ($shortSha === $currentCommit) {
                        $currentIndex = $index;
                        break;
                    }
                }
                
                // Check local version status
                $isLocalNewer = false;
                if ($currentIndex === -1) {
                    // Current version not found in remote history, check local commits
                    foreach ($localLogs as $localCommit) {
                        $localHash = $this->formatCommitHash($localCommit['hash']);
                        // If latest remote commit found, local is not newer
                        if ($localHash === $latestCommit) {
                            $isLocalNewer = false;
                            break;
                        }
                        // Record additional local commits
                        $updateLogs[] = [
                            'version' => $localHash,
                            'message' => $localCommit['message'],
                            'author' => $localCommit['author'],
                            'date' => $localCommit['date'],
                            'is_local' => true
                        ];
                        $isLocalNewer = true;
                    }
                }
                
                // If local is not newer, collect commits that need to be updated
                if (!$isLocalNewer && $currentIndex > 0) {
                    $updateLogs = [];
                    // Collect all commits between current version and latest version
                    for ($i = 0; $i < $currentIndex; $i++) {
                        $commit = $commits[$i];
                        $updateLogs[] = [
                            'version' => $this->formatCommitHash($commit['sha']),
                            'message' => $commit['commit']['message'],
                            'author' => $commit['commit']['author']['name'],
                            'date' => $commit['commit']['author']['date'],
                            'is_local' => false
                        ];
                    }
                }

                $hasUpdate = !$isLocalNewer && $currentIndex > 0;
                
                $updateInfo = [
                    'has_update' => $hasUpdate,
                    'is_local_newer' => $isLocalNewer,
                    'latest_version' => $isLocalNewer ? $currentCommit : $latestCommit,
                    'current_version' => $currentCommit,
                    'update_logs' => $updateLogs,
                    'download_url' => $commits[0]['html_url'] ?? '',
                    'published_at' => $commits[0]['commit']['author']['date'] ?? '',
                    'author' => $commits[0]['commit']['author']['name'] ?? '',
                ];

                // Cache check results
                $this->setLastCheckTime();
                Cache::put(self::CACHE_UPDATE_INFO, $updateInfo, now()->addHours(24));

                return $updateInfo;
            }
            
            return $this->getUnavailableUpdateInfo($currentCommit);
        } catch (\Exception $e) {
            Log::error('Update check failed: ' . $e->getMessage());
            return $this->getUnavailableUpdateInfo($currentCommit);
        }
    }

    public function executeUpdate(): array
    {
        // Check for new version first
        $updateInfo = $this->checkForUpdates();
        if (($updateInfo['is_local_newer'] ?? false)) {
            return [
                'success' => false,
                'message' => __('update.local_newer')
            ];
        }
        if (!($updateInfo['has_update'] ?? false)) {
            return [
                'success' => false,
                'message' => __('update.already_latest')
            ];
        }

        // Check for update lock
        if (Cache::get(self::CACHE_UPDATE_LOCK)) {
            return [
                'success' => false,
                'message' => __('update.process_running')
            ];
        }

        $versionState = null;
        try {
            // Set update lock
            Cache::put(self::CACHE_UPDATE_LOCK, true, now()->addMinutes(30));
            if (config('orphan.xboard_update_mode') === 'archive') {
                $versionState = $this->captureVersionState();
            }

            // 1. Backup database
            $this->backupDatabase();

            // 2. Pull latest code
            $result = $this->pullLatestCode($updateInfo);
            if (!$result['success']) {
                throw new \Exception($result['message']);
            }

            // 3. Run database migrations
            $this->runMigrations();

            // 4. Clear cache
            $this->clearCache();

            // 5. Create update flag
            $this->createUpdateFlag();

            if ($versionState !== null) {
                app(PrivateArchiveUpdateService::class)->completeUpdate($updateInfo);
                $this->updateVersionCache();
            }

            // 6. Restart Octane if running
            $this->restartOctane();

            // Remove update lock
            Cache::forget(self::CACHE_UPDATE_LOCK);

            // Format update logs
            $logMessages = array_map(function($log) {
                return sprintf("- %s (%s): %s", 
                    $log['version'],
                    date('Y-m-d H:i', strtotime($log['date'])),
                    $log['message']
                );
            }, $updateInfo['update_logs']);

            return [
                'success' => true,
                'message' => __('update.success', [
                    'from' => $updateInfo['current_version'],
                    'to' => $updateInfo['latest_version']
                ]),
                'version' => $updateInfo['latest_version'],
                'update_info' => [
                    'from_version' => $updateInfo['current_version'],
                    'to_version' => $updateInfo['latest_version'],
                    'update_logs' => $logMessages,
                    'author' => $updateInfo['author'],
                    'published_at' => $updateInfo['published_at']
                ]
            ];

        } catch (\Throwable $e) {
            if ($versionState !== null) {
                try {
                    // The child may have written a candidate build marker before
                    // a later application step failed. Keep this release retryable.
                    $this->restoreVersionState($versionState);
                } catch (\Throwable $restoreError) {
                    Log::error('Could not restore the pre-update version state; review protected release metadata before retrying.');
                }
            }
            Log::error('Update execution failed: ' . $e->getMessage());
            Cache::forget(self::CACHE_UPDATE_LOCK);
            
            return [
                'success' => false,
                'message' => __('update.failed', ['error' => $e->getMessage()])
            ];
        }
    }

    private function captureVersionState(): array
    {
        $path = storage_path('app/private/xboard-build.json');
        if (is_link($path) || is_link(dirname($path))
            || (File::exists($path) && (!File::isFile($path) || File::size($path) > 16384))) {
            throw new \RuntimeException('Invalid private release build metadata path.');
        }
        $cache = [];
        foreach ([self::CACHE_VERSION, self::CACHE_VERSION_DATE, self::CACHE_UPDATE_INFO, self::CACHE_LAST_CHECK] as $key) {
            $cache[$key] = ['exists' => Cache::has($key), 'value' => Cache::get($key)];
        }
        return ['exists' => File::exists($path), 'contents' => File::exists($path) ? File::get($path) : null,
            'mode' => File::exists($path) ? fileperms($path) & 0777 : 0600, 'cache' => $cache];
    }

    private function restoreVersionState(array $state): void
    {
        $path = storage_path('app/private/xboard-build.json');
        if (is_link($path) || is_link(dirname($path))) {
            throw new \RuntimeException('Refusing a symbolic link while restoring release metadata.');
        }
        if ($state['exists']) {
            File::ensureDirectoryExists(dirname($path), 0700);
            $temporary = tempnam(dirname($path), '.restore-build-');
            if (!$temporary) { throw new \RuntimeException('Could not stage release metadata recovery.'); }
            try {
                if (!chmod($temporary, $state['mode']) || File::put($temporary, $state['contents']) === false
                    || !rename($temporary, $path)) {
                    throw new \RuntimeException('Could not restore release metadata.');
                }
            } finally { if (is_file($temporary)) { unlink($temporary); } }
        } elseif (File::exists($path) && !File::delete($path)) {
            throw new \RuntimeException('Could not remove an uncommitted release marker.');
        }
        foreach ($state['cache'] as $key => $value) {
            if ($value['exists'] && in_array($key, [self::CACHE_UPDATE_INFO, self::CACHE_LAST_CHECK], true)) {
                Cache::put($key, $value['value'], now()->addHours(24));
            } elseif ($value['exists']) { Cache::forever($key, $value['value']); }
            else { Cache::forget($key); }
        }
    }

    protected function getCurrentCommit(): string
    {
        $buildMarker = storage_path('app/private/xboard-build.json');
        if (config('orphan.xboard_update_mode') === 'archive' && File::exists($buildMarker)) {
            $marker = json_decode(File::get($buildMarker), true);
            if (is_array($marker) && !empty($marker['commit'])) {
                return $this->formatCommitHash((string) $marker['commit']);
            }
        }

        try {
            // Ensure git configuration is correct
            Process::run(sprintf('git config --global --add safe.directory %s', base_path()));
            $result = Process::run('git rev-parse HEAD');
            $fullHash = trim($result->output());
            if ($fullHash !== '') {
                return $this->formatCommitHash($fullHash);
            }
        } catch (\Exception $e) {
            Log::error('Failed to get current commit: ' . $e->getMessage());
        }

        $buildCommit = trim((string) config('orphan.xboard_build_commit', ''));
        return $buildCommit !== '' ? $this->formatCommitHash($buildCommit) : 'unknown';
    }

    protected function getFirstCommit(): string
    {
        try {
            // Get first commit hash
            $result = Process::run('git rev-list --max-parents=0 HEAD');
            $fullHash = trim($result->output());
            return $fullHash ? $this->formatCommitHash($fullHash) : 'unknown';
        } catch (\Exception $e) {
            Log::error('Failed to get first commit: ' . $e->getMessage());
            return 'unknown';
        }
    }

    protected function formatCommitHash(string $hash): string
    {
        // Use 7 characters for commit hash
        return substr($hash, 0, 7);
    }

    protected function backupDatabase(): void
    {
        try {
            // Use existing backup command
            $result = Process::run('php artisan backup:database');
            
            if (!$result->successful()) {
                throw new \Exception(__('update.backup_failed', ['error' => $result->errorOutput()]));
            }
        } catch (\Exception $e) {
            Log::error('Database backup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function pullLatestCode(array $updateInfo = []): array
    {
        if (config('orphan.xboard_update_mode') === 'archive') {
            try {
                app(PrivateArchiveUpdateService::class)->applyUpdate($updateInfo, false);
                return ['success' => true];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => __('update.code_update_failed', ['error' => $e->getMessage()])
                ];
            }
        }

        try {
            // Get current project root directory
            $basePath = base_path();
            
            // Ensure git configuration is correct
            Process::run(sprintf('git config --global --add safe.directory %s', $basePath));
            
            $ref = trim((string) config('orphan.xboard_ref', 'master'));
            $fetch = Process::path($basePath)->run(['git', 'fetch', 'private', $ref]);
            if (!$fetch->successful()) {
                throw new \RuntimeException('Failed to fetch the configured private source.');
            }

            $merge = Process::path($basePath)->run(['git', 'merge', '--ff-only', 'FETCH_HEAD']);
            if (!$merge->successful()) {
                throw new \RuntimeException('Private source update is not a fast-forward; local changes were preserved.');
            }

            // Update dependencies
            $dependencies = Process::path($basePath)->run([
                'composer', 'install', '--no-dev', '--no-interaction', '--optimize-autoloader'
            ]);
            if (!$dependencies->successful()) {
                throw new \RuntimeException('Composer dependency installation failed.');
            }

            // Update version cache after pulling new code
            $this->updateVersionCache();

            return ['success' => true];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('update.code_update_failed', ['error' => $e->getMessage()])
            ];
        }
    }

    protected function runMigrations(): void
    {
        try {
            $binary = PHP_SAPI === 'cli' ? PHP_BINARY : dirname(PHP_BINARY, 2) . '/bin/php';
            if (!is_executable($binary)) { $binary = 'php'; }
            $result = Process::path(base_path())->timeout(600)
                ->run([$binary, 'artisan', 'xboard:update', '--no-interaction']);
            if (!$result->successful()) {
                throw new \RuntimeException('Application migration or bundled-plugin synchronization failed. Inspect the protected application logs.');
            }
        } catch (\Exception $e) {
            Log::error('Migration failed: ' . $e->getMessage());
            throw new \Exception(__('update.migration_failed', ['error' => $e->getMessage()]));
        }
    }

    protected function clearCache(): void
    {
        try {
            $commands = [
                'php artisan config:clear',
                'php artisan cache:clear',
                'php artisan view:clear',
                'php artisan route:clear'
            ];

            foreach ($commands as $command) {
                Process::run($command);
            }
        } catch (\Exception $e) {
            Log::error('Cache clearing failed: ' . $e->getMessage());
            throw new \Exception(__('update.cache_clear_failed', ['error' => $e->getMessage()]));
        }
    }

    protected function createUpdateFlag(): void
    {
        try {
            // Create update flag file for external script to detect and restart container
            $flagFile = storage_path('update_pending');
            File::put($flagFile, date('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            Log::error('Failed to create update flag: ' . $e->getMessage());
            throw new \Exception(__('update.flag_create_failed', ['error' => $e->getMessage()]));
        }
    }

    protected function restartOctane(): void
    {
        try {
            if (!config('octane.server')) {
                return;
            }

            // Check Octane running status
            $statusResult = Process::run('php artisan octane:status');
            if (!$statusResult->successful()) {
                Log::info('Octane is not running, skipping restart.');
                return;
            }

            $output = $statusResult->output();
            if (str_contains($output, 'Octane server is running')) {
                Log::info('Restarting Octane server after update...');
                // Update version cache before restart
                $this->updateVersionCache();
                Process::run('php artisan octane:stop');
                Log::info('Octane server restarted successfully.');
            } else {
                Log::info('Octane is not running, skipping restart.');
            }
        } catch (\Exception $e) {
            Log::error('Failed to restart Octane server: ' . $e->getMessage());
            // Non-fatal error, don't throw exception
        }
    }

    public function getLastCheckTime()
    {
        return Cache::get(self::CACHE_LAST_CHECK, null);
    }

    protected function setLastCheckTime(): void
    {
        Cache::put(self::CACHE_LAST_CHECK, now()->timestamp, now()->addDays(30));
    }

    public function getCachedUpdateInfo(): array
    {
        return Cache::get(self::CACHE_UPDATE_INFO, [
            'has_update' => false,
            'latest_version' => $this->getCurrentCommit(),
            'current_version' => $this->getCurrentCommit(),
            'update_logs' => [],
            'download_url' => '',
            'published_at' => '',
            'author' => '',
        ]);
    }

    protected function getUnavailableUpdateInfo(string $currentCommit): array
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

    protected function getLocalGitLogs(int $limit = 50): array
    {
        try {
            // 获取本地git log
            $result = Process::run(
                sprintf('git log -%d --pretty=format:"%%H||%%s||%%an||%%ai"', $limit)
            );

            if (!$result->successful()) {
                return [];
            }

            $logs = [];
            $lines = explode("\n", trim($result->output()));
            foreach ($lines as $line) {
                $parts = explode('||', $line);
                if (count($parts) === 4) {
                    $logs[] = [
                        'hash' => $parts[0],
                        'message' => $parts[1],
                        'author' => $parts[2],
                        'date' => $parts[3]
                    ];
                }
            }
            return $logs;
        } catch (\Exception $e) {
            Log::error('Failed to get local git logs: ' . $e->getMessage());
            return [];
        }
    }
}
