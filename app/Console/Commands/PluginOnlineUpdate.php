<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Services\Plugin\BundledPluginInstaller;
use App\Services\Plugin\OnlinePluginUpdater;
use App\Services\Plugin\PluginManager;
use Illuminate\Console\Command;

final class PluginOnlineUpdate extends Command
{
    protected $signature = 'xboard:plugin-online-update {code} {--activate= : Internal: activate staged version} {--enabled= : Internal: original enabled state} {--deactivate : Internal: clean up old source before replacement} {--restore : Internal: boot restored source}';
    protected $description = 'Update a plugin from the latest verified official release as the site user';

    public function handle(OnlinePluginUpdater $updater, PluginManager $manager): int
    {
        try {
            BundledPluginInstaller::assertApplicationUser();
            $code = (string) $this->argument('code');
            if (!$updater->supports($code)) { throw new \RuntimeException('Unsupported online plugin source.'); }
            if ($this->option('restore')) { $manager->enable($code); }
            elseif ($this->option('deactivate')) { $manager->disable($code); }
            elseif ($this->option('activate') !== null) {
                $expected = (string) $this->option('activate');
                $config = json_decode(file_get_contents($manager->getPluginPath($code) . '/config.json'), true, 32, JSON_THROW_ON_ERROR);
                if (($config['code'] ?? '') !== $code || ($config['version'] ?? '') !== $expected
                    || !in_array($this->option('enabled'), ['0', '1'], true)) { throw new \RuntimeException('Staged plugin identity or state changed.'); }
                $installed = Plugin::where('code', $code)->firstOrFail();
                if (version_compare($expected, $installed->version, '>')) { $manager->update($code); }
                elseif ($expected !== $installed->version) { throw new \RuntimeException('Refusing a plugin downgrade.'); }
                if ($this->option('enabled') === '1') { $manager->enable($code); }
                if ((bool) Plugin::where('code', $code)->value('is_enabled') !== ($this->option('enabled') === '1')) {
                    throw new \RuntimeException('Plugin enabled state changed during activation.');
                }
                if (Plugin::where('code', $code)->value('version') !== $expected) { throw new \RuntimeException('Plugin version activation failed.'); }
            } else {
                $result = $updater->update($code);
                $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
