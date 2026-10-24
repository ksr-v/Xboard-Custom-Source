<?php

namespace App\Observers;

use App\Models\Server;
use App\Models\ServerRoute;
use App\Services\NodeSyncService;

class ServerRouteObserver
{
    public bool $afterCommit = true;

    public function updated(ServerRoute $route): void
    {
        $this->notifyAffectedNodes($route->id);
    }

    public function deleted(ServerRoute $route): void
    {
        $this->notifyAffectedNodes($route->id);
    }

    private function notifyAffectedNodes(int $routeId): void
    {
        // Subscription visibility does not determine whether a machine runs a node.
        $servers = Server::where('enabled', true)->get()->filter(
            fn ($s) => in_array($routeId, $s->route_ids ?? [])
        );

        foreach ($servers as $server) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }
    }
}
