<?php

namespace Plugin\SubinfoCustomizer\Services;

use RuntimeException;

/** Owns one marked insertion; removal never restores an old whole-file backup. */
final class CoreBridgePatcher
{
    public const BASELINE_COMMIT = 'd1c3bb575b60bb74f78fadc7b21ef718dcee8f15';
    public const MARKER = 'subinfo-customizer-core-v1';
    public const RELATIVE_PATH = 'app/Http/Controllers/V1/Client/ClientController.php';
    private const CLEAN_SHA256 = '3303d9696a44268e42e0f39371ff42aed87597da68d207c0847fccb98d270ece';
    private const ANCHOR = "    private function setSubscribeInfoToServers(&\$servers, \$user, \$rejectServerCount = 0)\n    {\n";

    public function __construct(private readonly string $basePath) {}

    public static function insertion(): string
    {
        return <<<'PHP'
        // <subinfo-customizer-core-v1>
        $customInfoServers = HookManager::filter('client.subscribe.info', null, $servers, $user, $rejectServerCount);
        if (is_array($customInfoServers)) {
            $servers = $customInfoServers;
            return;
        }
        // </subinfo-customizer-core-v1>

PHP;
    }

    public function inspect(): string
    {
        return $this->withLock(fn (): string => $this->readState()[2]);
    }

    public function apply(): string
    {
        // Ordinary requests only need to verify the existing bridge. Do not
        // queue them behind other plugins' Admin asset writers unnecessarily.
        try {
            if ($this->readState()[2] === 'patched') { return 'already patched'; }
        } catch (\Throwable) {
            // A concurrent writer may be replacing the file. Recheck under the
            // shared lock before rejecting it; this never accepts invalid core.
        }

        return $this->withLock(function (): string {
            [$original, , $state] = $this->readState();
            if ($state === 'patched') { return 'already patched'; }
            $anchor = $this->rawFragment($original, self::ANCHOR);
            $insertion = str_contains($anchor, "\r\n") ? str_replace("\n", "\r\n", self::insertion()) : self::insertion();
            $updated = str_replace($anchor, $anchor . $insertion, $original);
            $this->replaceAndVerify($original, $updated, 'patched');
            return 'patched';
        });
    }

    public function remove(): string
    {
        return $this->withLock(function (): string {
            [$original, , $state] = $this->readState();
            if ($state === 'source') { return 'not patched'; }
            // Delete exactly our known insertion in the CURRENT file. Never use
            // an install-time backup that could overwrite another owner's edit.
            $updated = str_replace($this->rawFragment($original, self::insertion()), '', $original);
            $this->replaceAndVerify($original, $updated, 'source');
            return 'removed';
        });
    }

    /** @return array{string,string,string} */
    private function readState(): array
    {
        $path = $this->path();
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('SubInfoCustomizer 无法读取受支持的 ClientController。未修改任何文件。');
        }
        $original = file_get_contents($path);
        if (!is_string($original)) { throw new RuntimeException('无法读取 ClientController。'); }
        $normalized = str_replace("\r\n", "\n", $original);
        $count = substr_count($normalized, self::insertion());
        $clean = $count === 1 ? str_replace(self::insertion(), '', $normalized) : $normalized;
        $markerCount = substr_count($normalized, self::MARKER);
        if (!hash_equals(self::CLEAN_SHA256, hash('sha256', $clean))
            || substr_count($clean, self::ANCHOR) !== 1
            || ($count === 0 && $markerCount !== 0) || ($count === 1 && $markerCount !== 2) || $count > 1) {
            throw new RuntimeException('SubInfoCustomizer 核心版本或桥接标记不匹配；仅支持 ' . self::BASELINE_COMMIT . '。未修改任何文件。');
        }
        return [$original, $normalized, $count === 1 ? 'patched' : 'source'];
    }

    private function rawFragment(string $original, string $normalized): string
    {
        $pattern = '~' . str_replace("\n", "\\r?\\n", preg_quote($normalized, '~')) . '~';
        if (preg_match_all($pattern, $original, $matches) !== 1) {
            throw new RuntimeException('SubInfoCustomizer 无法唯一匹配核心桥接片段。未修改任何文件。');
        }
        return $matches[0][0];
    }

    private function replaceAndVerify(string $original, string $content, string $expected): void
    {
        try {
            $this->atomicWrite($content);
            if ($this->readState()[2] !== $expected) {
                throw new RuntimeException('SubInfoCustomizer 桥接写入后验证失败。');
            }
        } catch (\Throwable $exception) {
            // This snapshot was captured under the shared lock in THIS operation.
            // It includes all changes present when this operation began.
            if (is_file($this->path()) && file_get_contents($this->path()) === $content) {
                $this->atomicWrite($original);
            }
            throw $exception;
        }
    }

    private function atomicWrite(string $content): void
    {
        $path = $this->path();
        $temporary = tempnam(dirname($path), '.subinfo-customizer-');
        if ($temporary === false) { throw new RuntimeException('无法创建核心桥接临时文件，请检查站点用户权限。'); }
        try {
            if (file_put_contents($temporary, $content) !== strlen($content)) {
                throw new RuntimeException('无法完整写入核心桥接临时文件。');
            }
            $permissions = fileperms($path);
            if ($permissions !== false && !chmod($temporary, $permissions & 0777)) {
                throw new RuntimeException('无法保留核心文件权限。');
            }
            if (!hash_equals(hash('sha256', $content), (string) hash_file('sha256', $temporary))) {
                throw new RuntimeException('核心桥接临时文件校验失败。');
            }
            if (!rename($temporary, $path)) { throw new RuntimeException('无法原子替换核心文件，请检查站点用户权限。'); }
            clearstatcache(true, $path);
            if (function_exists('opcache_invalidate')) { opcache_invalidate($path, true); }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    private function withLock(callable $operation): mixed
    {
        // Same lock as SmartExpiry and QRCodeExtend, allowing cooperative writes.
        $directory = $this->basePath . '/storage/framework';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('无法创建共享补丁锁目录。');
        }
        $lockPath = $directory . '/xboard-admin-patch.lock';
        if (is_link($lockPath)) { throw new RuntimeException('拒绝符号链接补丁锁。'); }
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) { fclose($lock); }
            throw new RuntimeException('无法获取共享补丁锁。');
        }
        try { return $operation(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function path(): string { return $this->basePath . '/' . self::RELATIVE_PATH; }
}
