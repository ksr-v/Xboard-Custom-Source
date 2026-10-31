<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Serialize ordinary web mutations with CLI plugin source replacement. */
final class PluginMutationLock
{
    public function handle(Request $request, Closure $next)
    {
        $directory = storage_path('app/private/plugin-bundles');
        for ($cursor = $directory; $cursor !== dirname($cursor); $cursor = dirname($cursor)) {
            if (is_link($cursor)) { return response()->json(['message' => '插件更新锁目录不能使用符号链接。'], 409); }
        }
        if ((!is_dir($directory) && !@mkdir($directory, 0700, true)) || !is_writable($directory)) {
            return response()->json(['message' => '插件更新锁目录不可写，请修复站点用户权限。'], 409);
        }
        $path = $directory . '/sync.lock';
        if (is_link($path)) { return response()->json(['message' => '插件更新锁不能使用符号链接。'], 409); }
        $lock = @fopen($path, 'c+b');
        if (!$lock) { return response()->json(['message' => '无法创建插件更新锁。'], 409); }
        chmod($path, 0600);
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return response()->json(['message' => '另一个插件操作正在进行，请稍后重试。'], 409);
        }
        try { return $next($request); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
