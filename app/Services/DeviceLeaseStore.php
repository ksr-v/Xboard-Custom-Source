<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

/** Atomic per-account IP leases shared by REST and WebSocket nodes. */
class DeviceLeaseStore
{
    public function keys(int $userId): array
    {
        return array_map(fn ($prefix) => $prefix . $userId, [
            'user_devices:', 'user_device_expiry:', 'user_device_lease:',
            'user_device_pending:', 'user_device_released:',
        ]);
    }

    public function run(int $userId, int $nodeId, string $operation, array $ips = [], int $ttl = 300, int $limit = 0, string $token = '', bool $force = false): array
    {
        $keys = [...$this->keys($userId), 'node_device_users:' . $nodeId];
        $script = <<<'LUA'
local now, ttl = tonumber(ARGV[1]), tonumber(ARGV[2])
local node, op, uid = ARGV[3], ARGV[4], ARGV[5]
local incoming, limit, token = cjson.decode(ARGV[6]), tonumber(ARGV[7]), ARGV[8]
local force = ARGV[9] == '1'
local prefix = node .. ':'
local function remove(field)
    for key = 1,4 do redis.call('HDEL', KEYS[key], field) end
end
local fields = redis.call('HGETALL', KEYS[1])
for i = 1,#fields,2 do
    local expires = tonumber(redis.call('HGET', KEYS[2], fields[i])) or (tonumber(fields[i+1]) + 300)
    if expires <= now then remove(fields[i]) end
end
local function count()
    local seen, total = {}, 0
    for _,field in ipairs(redis.call('HKEYS', KEYS[1])) do
        local ip = string.match(field, '^[^:]+:(.*)$')
        if ip and not seen[ip] then seen[ip] = true; total = total + 1 end
    end
    return total, seen
end
local allowed, released = 1, 0
if op == 'acquire' then
    local ip = incoming[1]
    local total, seen = count()
    if not seen[ip] and limit > 0 and total >= limit then
        allowed = 0
    else
        local field = prefix .. ip
        redis.call('HSET', KEYS[1], field, now)
        redis.call('HSET', KEYS[2], field, now + ttl)
        redis.call('HSET', KEYS[3], field, token)
        redis.call('HSET', KEYS[4], field, now + 10)
        redis.call('HDEL', KEYS[5], field)
        redis.call('SADD', KEYS[6], uid)
    end
elseif op == 'release' or op == 'release-retain' then
    local field = prefix .. incoming[1]
    if redis.call('HGET', KEYS[3], field) == token then
        if op == 'release-retain' then
            -- Older, previously unlimited connections may still use this IP.
            -- Remove only the generation, never extend or recreate the IP.
            redis.call('HDEL', KEYS[3], field)
            redis.call('HDEL', KEYS[4], field)
        else
            remove(field)
            redis.call('HSET', KEYS[5], field, now + ttl)
            redis.call('EXPIRE', KEYS[5], ttl + 1)
        end
        released = 1
    end
elseif op == 'snapshot' then
    local present = {}
    for _,ip in ipairs(incoming) do present[prefix .. ip] = true end
    for _,field in ipairs(redis.call('HKEYS', KEYS[1])) do
        if string.sub(field, 1, #prefix) == prefix and not present[field] then
            local guard = tonumber(redis.call('HGET', KEYS[4], field)) or 0
            local claimed = redis.call('HEXISTS', KEYS[3], field) == 1
            -- A delayed empty snapshot must not revoke a newer live admission.
            -- Claimed IPs leave via token-CAS release or lease expiry instead.
            if force or (not claimed and guard <= now) then
                remove(field)
                if claimed then
                    redis.call('HSET', KEYS[5], field, now + ttl)
                    redis.call('EXPIRE', KEYS[5], ttl + 1)
                end
            end
        end
    end
    for field,_ in pairs(present) do
        local tombstone = tonumber(redis.call('HGET', KEYS[5], field)) or 0
        if tombstone <= now then
            redis.call('HSET', KEYS[1], field, now)
            redis.call('HSET', KEYS[2], field, now + ttl)
            redis.call('HDEL', KEYS[4], field)
            redis.call('HDEL', KEYS[5], field)
        end
    end
    if next(present) then redis.call('SADD', KEYS[6], uid) end
end
local maxExpiry, localFields = now, 0
fields = redis.call('HGETALL', KEYS[1])
for i = 1,#fields,2 do
    local expires = tonumber(redis.call('HGET', KEYS[2], fields[i])) or (tonumber(fields[i+1]) + 300)
    if expires > maxExpiry then maxExpiry = expires end
    if string.sub(fields[i],1,#prefix) == prefix then localFields = localFields + 1 end
end
if maxExpiry > now then
    for key = 1,4 do redis.call('EXPIRE', KEYS[key], maxExpiry - now + 1) end
end
if node ~= '0' then
    if localFields == 0 then redis.call('SREM', KEYS[6], uid) end
    redis.call('EXPIRE', KEYS[6], 86400)
end
local total = count()
return {allowed, total, released}
LUA;
        $arguments = [...$keys, time(), max(30, min(900, $ttl)), $nodeId, $operation, $userId,
            json_encode(array_values($ips), JSON_THROW_ON_ERROR), max(0, $limit), $token, $force ? '1' : '0'];
        return Redis::eval($script, count($keys), ...$arguments);
    }

    public function count(int $userId): int
    {
        return (int) $this->run($userId, 0, 'read')[1];
    }
}
