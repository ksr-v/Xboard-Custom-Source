<?php

declare(strict_types=1);

namespace Plugin\SubinfoCustomizer\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Plugin\SubinfoCustomizer\Services\AdminIntegration;
use Plugin\SubinfoCustomizer\Services\Settings;
use Symfony\Component\HttpFoundation\Response;

final class AssetController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json(AdminIntegration::publicConfiguration(), 200, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(string $asset): Response
    {
        $files = ['script' => ['subinfo-customizer.js', 'application/javascript'], 'style' => ['subinfo-customizer.css', 'text/css']];
        if (!Settings::isEnabled() || !isset($files[$asset])) {
            return response('Not Found', 404);
        }
        [$name, $type] = $files[$asset];
        $file = dirname(__DIR__) . '/resources/assets/' . $name;
        if (!is_file($file)) {
            return response('Not Found', 404);
        }
        return response()->file($file, [
            'Content-Type' => $type . '; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
