<?php

namespace Plugin\SubinfoCustomizer;

use App\Models\Plugin as PluginModel;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Log;
use Plugin\SubinfoCustomizer\Services\AdminIntegration;
use Plugin\SubinfoCustomizer\Services\CoreBridgePatcher;
use Plugin\SubinfoCustomizer\Services\Settings;
use Plugin\SubinfoCustomizer\Services\TemplateService;

final class Plugin extends AbstractPlugin
{
    public const VERSION = '1.0.1';

    public function install(): void
    {
        // The framework installs disabled. Preflight only; do not activate or
        // change native behavior until PluginManager really enables this plugin.
        $this->assertCompatibility();
        (new CoreBridgePatcher(base_path()))->inspect();
    }

    public function boot(): void
    {
        $patcher = new CoreBridgePatcher(base_path());
        try {
            $this->assertCompatibility();
            $patcher->apply();
            $this->removePublishedCopies();
            $this->filter('client.subscribe.info', [$this, 'customizeServers']);
            app(AdminIntegration::class)->register();
        } catch (\Throwable $exception) {
            // PluginManager sets is_enabled before boot, and has no rollback.
            // Fail disabled while preserving settings and all other plugins.
            PluginModel::where('code', Settings::CODE)->update(['is_enabled' => false, 'updated_at' => now()]);
            HookManager::remove('client.subscribe.info', [$this, 'customizeServers']);
            try { $patcher->remove(); }
            catch (\Throwable $rollback) { Log::error('SubInfoCustomizer 桥接回滚失败：' . $rollback->getMessage()); }
            throw $exception;
        }
    }

    public function customizeServers(mixed $value, array $servers, User $user, int $rejectServerCount = 0): mixed
    {
        if (!Settings::isEnabled() || $value !== null) { return $value; }
        try {
            return TemplateService::prepend($servers, $user, Settings::read());
        } catch (\Throwable $exception) {
            // Preserve every real server and suppress native pseudo-nodes even
            // if corrupt/out-of-band settings cannot be rendered safely.
            Log::error('SubInfoCustomizer 信息渲染失败：' . $exception->getMessage());
            return $servers;
        }
    }

    public function cleanup(): void
    {
        // The actual framework uses cleanup() for BOTH disable and uninstall,
        // and before upgrades. Never erase saved settings in this callback.
        HookManager::remove('client.subscribe.info', [$this, 'customizeServers']);
        (new CoreBridgePatcher(base_path()))->remove();
        $this->removePublishedCopies();
    }

    public function update(string $oldVersion, string $newVersion): void
    {
        $this->assertCompatibility();
        (new CoreBridgePatcher(base_path()))->inspect();
        // No setting reset: native PluginManager enables and applies the bridge
        // only after this preflight succeeds.
    }

    private function assertCompatibility(): void
    {
        if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 80400
            || !str_starts_with(app()->version(), '12.')) {
            throw new \RuntimeException('SubInfoCustomizer 仅支持 PHP 8.2/8.3、Laravel 12 与指定 Xboard-Custom-Source。');
        }
        foreach (['mb_check_encoding', 'mb_strlen', 'mb_substr'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException('SubInfoCustomizer 需要 PHP mbstring 扩展。');
            }
        }
        if (!AdminIntegration::compatibility()['supported']) {
            throw new \RuntimeException('SubInfoCustomizer 无法识别当前 Admin 构建，拒绝启用。');
        }
    }

    /** PluginManager publishes assets but never removes them on uninstall. */
    private function removePublishedCopies(): void
    {
        $parent = public_path('plugins');
        $directory = $parent . '/' . Settings::CODE;
        if (is_link($parent) || is_link($directory)) {
            throw new \RuntimeException('SubInfoCustomizer 拒绝清理符号链接公共资源目录。');
        }
        foreach (['subinfo-customizer.js', 'subinfo-customizer.css'] as $name) {
            $file = $directory . '/' . $name;
            if ((is_file($file) || is_link($file)) && !unlink($file)) {
                throw new \RuntimeException('无法清理 SubInfoCustomizer 自有公共资源：' . $name);
            }
        }
        if (is_dir($directory) && count(scandir($directory)) === 2 && !rmdir($directory)) {
            throw new \RuntimeException('无法清理 SubInfoCustomizer 空公共资源目录。');
        }
    }
}
