<?php

namespace App\Console\Commands;

use App\Services\Plugin\BundledPluginInstaller;
use Illuminate\Console\Command;

final class BundledPlugins extends Command
{
    protected $signature = 'xboard:bundled-plugins {--stage-only : Verify and deploy files before loading plugin classes} {--enable-all : Explicitly enable all bundled plugins}';
    protected $description = 'Verify and synchronize the independent release bundled plugins as the site user';

    public function handle(BundledPluginInstaller $installer): int
    {
        try {
            if ($this->option('stage-only') && $this->option('enable-all')) {
                throw new \RuntimeException('--stage-only cannot be combined with --enable-all.');
            }
            $result = $this->option('stage-only') ? $installer->stage() : $installer->synchronize((bool) $this->option('enable-all'));
            foreach ($result as $code => $entry) {
                $this->line($code . ' ' . $entry['version'] . ': ' . ($entry['lifecycle'] ?? $entry['action']));
            }
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
