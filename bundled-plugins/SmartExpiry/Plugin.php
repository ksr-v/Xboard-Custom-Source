<?php

namespace Plugin\SmartExpiry;

use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Log;
use Plugin\SmartExpiry\Services\AdminBridgePatcher;
use RuntimeException;

class Plugin extends AbstractPlugin
{
    public function install(): void
    {
        $this->applyAdminBridge();
    }

    public function boot(): void
    {
        $this->applyAdminBridge();
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        $this->applyAdminBridge();
    }

    public function cleanup(): void
    {
        require_once $this->basePath . '/Services/AdminBridgePatcher.php';

        try {
            $result = (new AdminBridgePatcher(base_path()))->remove();
            Log::info('SmartExpiry admin bridge cleanup status: ' . $result);
        } catch (RuntimeException $exception) {
            Log::error($exception->getMessage());
            throw $exception;
        }
    }

    private function applyAdminBridge(): void
    {
        require_once $this->basePath . '/Services/AdminBridgePatcher.php';

        try {
            $result = (new AdminBridgePatcher(base_path()))->apply();
            Log::info('SmartExpiry admin bridge status: ' . $result);
        } catch (RuntimeException $exception) {
            Log::error($exception->getMessage());
            throw $exception;
        }
    }
}
