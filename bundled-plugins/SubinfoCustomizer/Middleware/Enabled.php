<?php

namespace Plugin\SubinfoCustomizer\Middleware;

use Closure;
use Plugin\SubinfoCustomizer\Services\Settings;

final class Enabled
{
    public function handle($request, Closure $next)
    {
        if (!Settings::isEnabled()) {
            return response()->json(['message' => 'SubInfoCustomizer 未启用。'], 404);
        }
        return $next($request);
    }
}
