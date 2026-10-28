<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DeviceStateService;
use Illuminate\Console\Command;

class CleanupOnlineStatus extends Command
{
    protected $signature = 'cleanup:online-status {--user-id=* : Restrict reconciliation to specific users}';

    protected $description = 'Reset stale online_count for users whose devices have expired from Redis';

    public function handle(): void
    {
        $affected = 0;
        $query = User::where('online_count', '>', 0);
        if ($ids = $this->option('user-id')) { $query->whereIn('id', array_map('intval', $ids)); }
        $query->select('id')->chunkById(200, function ($users) use (&$affected) {
            $affected += app(DeviceStateService::class)->refreshUserCounts($users->pluck('id')->all());
        });

        if ($affected > 0) {
            $this->info("Reconciled online_count for {$affected} users.");
        }
    }
}
