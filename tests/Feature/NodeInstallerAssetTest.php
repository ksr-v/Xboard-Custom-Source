<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class NodeInstallerAssetTest extends TestCase
{
    private string $assetDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\InitializePlugins::class);

        $this->assetDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xboard-node-assets-' . bin2hex(random_bytes(8));
        mkdir($this->assetDirectory, 0700, true);
        file_put_contents($this->assetDirectory . DIRECTORY_SEPARATOR . 'install.sh', '#!/bin/sh\nexit 0\n');
        config()->set('orphan.node_installer_dir', $this->assetDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->assetDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->assetDirectory);

        parent::tearDown();
    }

    public function test_valid_temporary_signature_downloads_an_allowlisted_asset(): void
    {
        $url = URL::temporarySignedRoute(
            'node-installer.asset',
            now()->addMinutes(5),
            ['asset' => 'install.sh']
        );

        $this->get($url)->assertOk()->assertDownload('install.sh');
    }

    public function test_invalid_signature_and_non_allowlisted_asset_are_rejected(): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'node-installer.asset',
            now()->addMinutes(5),
            ['asset' => 'install.sh']
        );
        $tamperedUrl = preg_replace('/signature=[^&]+/', 'signature=invalid', $signedUrl);

        $this->get($tamperedUrl)->assertForbidden();

        $unlistedUrl = URL::temporarySignedRoute(
            'node-installer.asset',
            now()->addMinutes(5),
            ['asset' => '..%2F.env']
        );
        $this->get($unlistedUrl)->assertNotFound();
    }
}