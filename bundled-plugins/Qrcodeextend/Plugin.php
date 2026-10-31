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
        if ($this->publishedAssetsMatch($source, $target)) {
            return;
        }
        File::ensureDirectoryExists($target);
        if (!File::copyDirectory($source, $target)) {
            throw new \RuntimeException('Could not publish QRCode Extend assets.');
        }
    }

    /** Skip repeated publication only after comparing every owned file. */
    private function publishedAssetsMatch(string $source, string $target): bool
    {
        if (!is_dir($source) || !is_dir($target) || is_link($source) || is_link($target)) {
            return false;
        }
        try {
            $files = 0;
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($entries as $entry) {
                $published = $target . '/' . substr($entry->getPathname(), strlen($source) + 1);
                if ($entry->isLink() || is_link($published)) { return false; }
                if ($entry->isDir()) {
                    if (!is_dir($published)) { return false; }
                    continue;
                }
                if (!$entry->isFile() || !is_file($published)
                    || !$entry->isReadable() || !is_readable($published)
                    || $entry->getSize() !== filesize($published)) { return false; }
                $sourceHash = hash_file('sha256', $entry->getPathname());
                $publishedHash = hash_file('sha256', $published);
                if (!is_string($sourceHash) || !is_string($publishedHash)
                    || !hash_equals($sourceHash, $publishedHash)) { return false; }
                $files++;
            }
            return $files > 0;
        } catch (\Throwable) {
            // Preserve the original publisher's handling of missing or
            // unreadable paths instead of treating them as installed assets.
            return false;
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
