<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NodeInstallerAssetController extends Controller
{
    private const ALLOWED_ASSETS = [
        'install.sh',
        'xboard-node-linux-amd64',
        'xboard-node-linux-arm64',
        'xbctl-linux-amd64',
        'xbctl-linux-arm64',
    ];

    public function download(string $asset): BinaryFileResponse
    {
        abort_unless(in_array($asset, self::ALLOWED_ASSETS, true), 404);

        $directory = config('orphan.node_installer_dir', storage_path('app/private/node-installer'));
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $asset;
        abort_unless(is_file($path) && is_readable($path), 404, 'Installer asset is not installed.');

        return response()->download($path, $asset, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}