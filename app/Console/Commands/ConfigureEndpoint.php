<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Services\Plugin\BundledPluginInstaller;
use Illuminate\Console\Command;
use Plugin\NodeEndpoint\Services\{Endpoint, Settings};

final class ConfigureEndpoint extends Command
{
    protected $signature = 'xboard:configure-endpoint {--host= : Independent IP or DNS host} {--port=8443 : HTTPS port} {--ws-mode=rest : rewrite or rest}';
    protected $description = 'Save the independent HTTPS endpoint as the application user without handling credentials';

    public function handle(): int
    {
        try {
            BundledPluginInstaller::assertApplicationUser();
            if (!Plugin::where('code', 'node_endpoint')->where('is_enabled', true)->exists()) {
                throw new \RuntimeException('Install and enable NodeEndpoint before configuring its endpoint.');
            }
            $host = $this->option('host');
            $port = $this->option('port');
            if (!is_string($host) || $host === '' || !is_string($port) || !ctype_digit($port)
                || !in_array($this->option('ws-mode'), ['rewrite', 'rest'], true)) {
                throw new \InvalidArgumentException('Use --host=HOST --port=1..65535 --ws-mode=rewrite|rest.');
            }
            $existing = Settings::read();
            $settings = Endpoint::validate(array_replace($existing, ['enabled' => true, 'host' => $host,
                'port' => (int) $port, 'https' => true, 'http_acknowledged' => false, 'ws_mode' => $this->option('ws-mode')]),
                (string) config('app.url'));
            Settings::write(array_replace($existing, $settings, ['last_probe' => null]));
            $this->info('Saved independent HTTPS endpoint: ' . Endpoint::urls($settings)['url']);
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }
    }
}
