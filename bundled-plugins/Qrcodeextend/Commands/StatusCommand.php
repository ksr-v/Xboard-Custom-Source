<?php

namespace Plugin\Qrcodeextend\Commands;

use App\Models\Plugin;
use Illuminate\Console\Command;
use Plugin\Qrcodeextend\Services\AdminBundlePatcher;

class StatusCommand extends Command
{
    protected $signature = 'qrcodeextend:status';
    protected $description = 'Show qrcodeextend plugin and Admin bridge status';

    public function handle(AdminBundlePatcher $patcher): int
    {
        $status = $patcher->status();
        $this->table(
            ['Field', 'Value'],
            collect($status)->map(fn ($value, $key) => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? 'not available')])->values()->all()
        );

        return self::SUCCESS;
    }
}
