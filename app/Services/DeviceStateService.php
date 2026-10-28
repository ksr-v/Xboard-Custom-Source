<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

class DeviceStateService
{
    public function __construct(private readonly DeviceLeaseStore $leases = new DeviceLeaseStore()) {}

    /** Old nodes retain their 300-second grace; patched five-second heartbeats use 60. */
    public function leaseTtl(int $nodeId): int
    {
        $interval = Cache::get('device:report-interval:' . $nodeId);
        return $interval === null ? 300 : max(60, min(900, 3 * (int) $interval));
    }

    public function rememberReportInterval(int $nodeId, int $seconds): void
    {
        if ($seconds >= 1 && $seconds <= 300) { Cache::put('device:report-interval:' . $nodeId, $seconds, 3600); }
    }

    private function normalizeIPs(array $ips): array
    {
        $result = [];
        foreach ($ips as $ip) {
            if (!is_string($ip)) { throw ValidationException::withMessages(['alive' => 'Device addresses must be IP strings.']); }
            $ip = self::normalizeIP($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) { throw ValidationException::withMessages(['alive' => 'Invalid device IP address.']); }
            $result[$ip] = true;
        }
        return array_keys($result);
    }

    public function setDevices(int $userId, int $nodeId, array $ips): void
    {
        $ips = $this->normalizeIPs($ips);
        $this->leases->run($userId, $nodeId, 'snapshot', $ips, $this->leaseTtl($nodeId));
        $this->notifyUpdate($userId);
    }

    /** Bootstrap the index once per node, rather than scanning every user on each heartbeat. */
    private function nodeUserIds(int $nodeId): array
    {
        if (!Cache::has('device:node-index-ready:' . $nodeId)) {
            $redisPrefix = config('database.redis.options.prefix', '');
            foreach (Redis::keys('user_devices:*') as $key) {
                $key = $redisPrefix !== '' && str_starts_with($key, $redisPrefix) ? substr($key, strlen($redisPrefix)) : $key;
                $userId = (int) substr($key, strlen('user_devices:'));
                foreach (Redis::hkeys($key) as $field) {
                    if (str_starts_with($field, $nodeId . ':')) { Redis::sadd('node_device_users:' . $nodeId, (string) $userId); break; }
                }
            }
            Cache::put('device:node-index-ready:' . $nodeId, true, 3600);
        }
        return array_map('intval', Redis::smembers('node_device_users:' . $nodeId));
    }

    /** Present empty snapshot means offline. A missing report field never calls this. */
    public function replaceNodeSnapshot(int $nodeId, array $devices): void
    {
        $normalized = [];
        foreach ($devices as $userId => $ips) {
            if (!ctype_digit((string) $userId) || (int) $userId < 1 || !is_array($ips)) {
                throw ValidationException::withMessages(['alive' => 'Invalid full device snapshot.']);
            }
            $normalized[(int) $userId] = $this->normalizeIPs($ips);
        }
        Cache::lock('device:node-snapshot:' . $nodeId, 10)->block(3, function () use ($nodeId, $normalized): void {
            $oldUsers = $this->nodeUserIds($nodeId);
            foreach (array_unique([...$oldUsers, ...array_keys($normalized)]) as $userId) {
                $this->setDevices((int) $userId, $nodeId, $normalized[$userId] ?? []);
            }
        });
    }

    public function getNodeDevices(int $nodeId): array
    {
        $result = [];
        foreach ($this->nodeUserIds($nodeId) as $userId) {
            $this->leases->count($userId);
            foreach (Redis::hkeys('user_devices:' . $userId) as $field) {
                if (str_starts_with($field, $nodeId . ':')) { $result[$userId][] = substr($field, strlen($nodeId . ':')); }
            }
        }
        return $result;
    }

    public function removeNodeDevices(int $nodeId, int $userId, bool $force = false): void
    {
        $this->leases->run($userId, $nodeId, 'snapshot', [], $this->leaseTtl($nodeId), force: $force);
    }

    /** A control-channel reconnect must not revoke active data-plane admission leases. */
    public function clearAllNodeDevices(int $nodeId, bool $force = false): array
    {
        $userIds = $this->nodeUserIds($nodeId);
        foreach ($userIds as $userId) { $this->removeNodeDevices($nodeId, $userId, $force); $this->notifyUpdate($userId); }
        return $userIds;
    }

    public function getDeviceCount(int $userId): int { return $this->leases->count($userId); }

    public function getAliveList(Collection $users): array
    {
        $result = [];
        foreach ($users as $user) { if (($count = $this->getDeviceCount($user->id)) > 0) { $result[$user->id] = $count; } }
        return $result;
    }

    public function getUsersDevices(array $userIds): array
    {
        $result = [];
        foreach ($userIds as $userId) {
            $this->leases->count((int) $userId);
            foreach (Redis::hkeys('user_devices:' . $userId) as $field) { $result[$userId][] = substr($field, strpos($field, ':') + 1); }
            if (isset($result[$userId])) { $result[$userId] = array_values(array_unique($result[$userId])); }
        }
        return $result;
    }

    public function acquire(User $user, int $nodeId, string $ip, string $token): array
    {
        $ip = $this->normalizeIPs([$ip])[0];
        $ttl = $this->leaseTtl($nodeId);
        $result = $this->leases->run($user->id, $nodeId, 'acquire', [$ip], $ttl, (int) $user->device_limit, $token);
        $this->notifyUpdate($user->id);
        return ['allowed' => (bool) $result[0], 'lease_token' => $token, 'ttl' => $ttl];
    }

    public function release(User $user, int $nodeId, string $ip, string $token, bool $retainIp = false): bool
    {
        $ip = $this->normalizeIPs([$ip])[0];
        $result = $this->leases->run($user->id, $nodeId, $retainIp ? 'release-retain' : 'release', [$ip], $this->leaseTtl($nodeId), token: $token);
        $this->notifyUpdate($user->id);
        return (bool) $result[2];
    }

    public static function normalizeIP(string $ip): string
    {
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $match) || preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $match)) { $ip = $match[1]; }
        $packed = @inet_pton($ip);
        return $packed === false ? $ip : inet_ntop($packed);
    }

    public function notifyUpdate(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            if (!User::whereKey($userId)->lockForUpdate()->first(['id'])) { return; }
            $count = $this->getDeviceCount($userId);
            $values = ['online_count' => $count];
            if ($count > 0) { $values['last_online_at'] = now(); }
            User::whereKey($userId)->update($values);
        }, 3);
    }

    /** Expiry reconciliation never advances last_online_at or clears another node's IPs. */
    public function refreshUserCounts(array $userIds): int
    {
        $changed = 0;
        foreach (array_unique($userIds) as $userId) {
            $changed += DB::transaction(function () use ($userId): int {
                $user = User::whereKey($userId)->lockForUpdate()->first(['id', 'online_count']);
                if (!$user) { return 0; }
                $count = $this->getDeviceCount((int) $userId);
                return $user->online_count === null || (int) $user->online_count !== $count
                    ? User::whereKey($userId)->update(['online_count' => $count]) : 0;
            }, 3);
        }
        return $changed;
    }
}
