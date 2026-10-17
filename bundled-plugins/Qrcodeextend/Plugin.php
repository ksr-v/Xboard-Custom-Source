<?php

namespace Plugin\Qrcodeextend;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\File;
use Plugin\Qrcodeextend\Services\AdminBundlePatcher;

class Plugin extends AbstractPlugin
{
    public function install(): void
    {
        $this->ensureIntegration();
    }

    public function boot(): void
    {
        $this->ensureIntegration();
    }

    public function cleanup(): void
    {
        app(AdminBundlePatcher::class)->removeOwnedChanges();
        $this->removeOwnedFiles();
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        $this->ensureIntegration();
    }

    private function ensureIntegration(): void
    {
        $this->publishOwnedAssets();
        app(AdminBundlePatcher::class)->patch();
    }

    private function publishOwnedAssets(): void
    {
        $source = $this->basePath . '/resources/assets';
        $target = public_path('plugins/qrcodeextend');
        File::ensureDirectoryExists($target);
        if (!File::copyDirectory($source, $target)) {
            throw new \RuntimeException('Could not publish QRCode Extend assets.');
        }
    }

    private function removeOwnedFiles(): void
    {
        $published = public_path('plugins/qrcodeextend');
        if (File::isDirectory($published) && !File::deleteDirectory($published)) {
            throw new \RuntimeException('Could not remove published QRCode Extend assets.');
        }

        $privateStorage = storage_path('qrcodeextend');
        if (File::isDirectory($privateStorage) && !File::deleteDirectory($privateStorage)) {
            throw new \RuntimeException('Could not remove private QRCode Extend storage.');
        }
    }
}
