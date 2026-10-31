<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Services\Plugin\BundledPluginInstaller;
use App\Services\Plugin\OnlinePluginUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** The SSH upgrader uses one fresh CLI process for the official source snapshot. */
final class PluginsOnlineUpdate extends Command
{
    protected $signature = 'xboard:plugins-online-update {--json : Print the verified version report as JSON}';
    protected $description = 'Update installed official plugins from their latest verified stable sources';

    public function handle(OnlinePluginUpdater $updater): int
    {
        $report = ['plugins' => [], 'failures' => []];
        try {
            BundledPluginInstaller::assertApplicationUser();
            $codes = Plugin::query()->orderBy('code')->pluck('code')->all();
            foreach ($codes as $code) {
                if (!$updater->supports($code)) { continue; }
                $before = Plugin::where('code', $code)->firstOrFail();
                $savedConfig = $before->config;
                $savedEnabled = (bool) $before->is_enabled;
                try {
                    $result = $updater->update($code);
                    $after = Plugin::where('code', $code)->firstOrFail();
                    if (($result['code'] ?? null) !== $code || ($result['version'] ?? null) !== $after->version
                        || !is_bool($result['updated'] ?? null)
                        || $after->config !== $savedConfig || (bool) $after->is_enabled !== $savedEnabled) {
                        throw new \RuntimeException('Plugin version report, configuration or enabled state did not verify.');
                    }
                    $report['plugins'][$code] = ['version' => $after->version, 'updated' => $result['updated']];
                    if (isset($result['source']) && is_array($result['source'])) {
                        $report['plugins'][$code]['source'] = array_intersect_key($result['source'], array_flip([
                            'repository', 'source_repository', 'tag', 'version', 'commit', 'archive_sha256', 'release_url',
                        ]));
                    }
                } catch (\Throwable $failure) {
                    // Error text may contain site-specific data; JSON exposes only its identity.
                    Log::error('Official plugin bulk update failed before completion.', ['plugin_code' => $code, 'exception' => $failure]);
                    $report['failures'][] = ['code' => $code, 'type' => get_class($failure)];
                    if (!$this->option('json')) { $this->error('Official plugin update failed: ' . $code . '. Review its protected update journal.'); }
                    $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    return self::FAILURE;
                }
            }
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable $failure) {
            Log::error('Official plugin bulk update could not start.', ['exception' => $failure]);
            if (!$this->option('json')) { $this->error('Official plugin update could not start.'); }
            $report['failures'][] = ['code' => null, 'type' => get_class($failure)];
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }
}
