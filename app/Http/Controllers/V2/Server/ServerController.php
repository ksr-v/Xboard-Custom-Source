<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Models\User;
use App\Services\DeviceStateService;
use App\WebSocket\NodeWorker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    /**
     * server handshake api
     */
    public function handshake(Request $request): JsonResponse
    {
        $websocket = ['enabled' => false];

        if ((bool) admin_setting('server_ws_enable', 1) && Cache::has(NodeWorker::HEARTBEAT_CACHE_KEY)) {
            $customUrl = trim((string) admin_setting('server_ws_url', ''));

            if ($customUrl !== '') {
                $wsUrl = rtrim($customUrl, '/');
            } else {
                $wsScheme = $request->isSecure() ? 'wss' : 'ws';
                $wsUrl = "{$wsScheme}://{$request->getHttpHost()}/ws";
            }

            $websocket = [
                'enabled' => true,
                'ws_url' => $wsUrl,
            ];
        }

        return response()->json([
            'websocket' => $websocket,
            'device_admission' => ['version' => 1, 'heartbeat_interval' => 5, 'retain_ip' => true],
        ]);
    }

    /**
     * node report api - merge traffic + alive + status + metrics
     */
    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');

        ServerService::touchNode($node);

        $interval = $request->input('metrics.device_report_interval');
        if (is_numeric($interval)) { app(DeviceStateService::class)->rememberReportInterval($node->id, (int) $interval); }

        $traffic = $request->input('traffic');
        if (is_array($traffic) && !empty($traffic)) {
            ServerService::processTraffic($node, $traffic);
        }

        $alive = $request->input('alive');
        if (is_array($alive)) {
            ServerService::processAlive($node->id, $alive);
        }

        $online = $request->input('online');
        if (is_array($online) && !empty($online)) {
            ServerService::processOnline($node, $online);
        }

        $status = $request->input('status');
        if (is_array($status) && !empty($status)) {
            ServerService::processStatus($node, $status);
        }

        $metrics = $request->input('metrics');
        if (is_array($metrics) && !empty($metrics)) {
            ServerService::updateMetrics($node, $metrics);
        }

        return response()->json(['data' => true]);
    }

    private function admissionUser(Request $request, bool $releasing = false): User
    {
        $request->validate([
            'user_uuid' => 'required|uuid', 'ip' => 'required|ip',
            'lease_token' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'report_interval' => 'sometimes|integer|min:1|max:300',
            'retain_ip' => 'sometimes|boolean',
        ]);
        $node = $request->attributes->get('node_info');
        $user = User::where('uuid', $request->input('user_uuid'))->first();
        abort_unless($node && $node->enabled && $user
            && in_array((string) $user->group_id, array_map('strval', $node->group_ids ?? []), true), 403);
        // Match the existing Node user feed, which also supports manually
        // assigned groups/traffic without a subscription plan.
        abort_unless($releasing || (!$user->banned
            && ($user->expired_at === null || $user->expired_at >= time())
            && $user->u + $user->d < $user->transfer_enable), 403);
        if ($request->has('report_interval')) {
            app(DeviceStateService::class)->rememberReportInterval($node->id, (int) $request->input('report_interval'));
        }
        return $user;
    }

    public function acquireDevice(Request $request): JsonResponse
    {
        $user = $this->admissionUser($request);
        $node = $request->attributes->get('node_info');
        return response()->json(['data' => app(DeviceStateService::class)->acquire($user, $node->id,
            $request->input('ip'), $request->input('lease_token'))]);
    }

    public function releaseDevice(Request $request): JsonResponse
    {
        $user = $this->admissionUser($request, true);
        $node = $request->attributes->get('node_info');
        return response()->json(['data' => ['released' => app(DeviceStateService::class)->release($user, $node->id,
            $request->input('ip'), $request->input('lease_token'), $request->boolean('retain_ip'))]]);
    }
}
