<?php

namespace Plugin\Qrcodeextend\Services;

use App\Models\Plugin;
use RuntimeException;

/** Generic build detection, locking and ownership reversal can later move to xb-extension-runtime. */
class AdminBundlePatcher
{
    public const TARGET_BUNDLE = 'assets/index-CEIYH7i8.js';
    public const LOCK_FILE = 'storage/framework/xboard-admin-patch.lock';
    private const ANCHOR = 'Q.jsxs($st,{className:"",onSelect:()=>{lT(t.original.id)';
    private const START_MARKER = '/*qrcodeextend:start:v3*/';
    private const END_MARKER = '/*qrcodeextend:end:v3*/';
    private const ANY_START = '/*qrcodeextend:start:';
    private const ANY_END = '/*qrcodeextend:end:';
    private string $root;

    public function __construct(?string $root = null) { $this->root = $root ?? base_path(); }

    public function status(): array
    {
        return $this->withLock(function (): array {
            $contents = $this->readBundle();
            $check = $this->inspect($contents);
            try {
                $plugin = Plugin::query()->where('code', 'qrcodeextend')->first();
                $enabled = (bool) ($plugin?->is_enabled ?? false);
            } catch (\Throwable) { $enabled = null; }
            return [
                'plugin_version' => $this->pluginVersion(), 'target_bundle' => self::TARGET_BUNDLE,
                'current_sha256' => hash('sha256', $contents), 'supported' => $check['valid'],
                'patched' => $check['state'] === 'patched', 'diagnostic' => $check['diagnostic'],
                'plugin_assets_published' => is_file($this->root . '/public/plugins/qrcodeextend/qrcodeextend.js')
                    && is_file($this->root . '/public/plugins/qrcodeextend/qrcodeextend.css'),
                'plugin_enabled' => $enabled,
            ];
        });
    }

    public function patch(): string
    {
        return $this->withLock(function (): string {
            $current = $this->readBundle();
            $check = $this->inspect($current);
            if (!$check['valid']) throw new RuntimeException($check['diagnostic']);
            if ($check['state'] === 'patched') return 'Admin bridge is already patched; no changes made.';
            if ($check['state'] !== 'clean') throw new RuntimeException('Remove the unowned legacy qrcodeextend bridge before installing v1.1.0.');
            $patched = str_replace(self::ANCHOR, $this->ownedBlock() . self::ANCHOR, $current, $count);
            if ($count !== 1) throw new RuntimeException("Expected one Admin action anchor; found {$count}.");
            $this->writeAndVerify($current, $patched, 'patched');
            return 'Admin bridge patched; foreign modifications were preserved.';
        });
    }

    public function removeOwnedChanges(): string
    {
        return $this->withLock(function (): string {
            $current = $this->readBundle();
            $check = $this->inspect($current);
            if (!$check['valid']) throw new RuntimeException($check['diagnostic']);
            if ($check['state'] === 'clean') return 'Admin bridge is already absent; no changes made.';
            if ($check['state'] !== 'patched') throw new RuntimeException('Only a complete owned v3 bridge can be removed automatically.');
            $clean = str_replace($this->ownedBlock(), '', $current, $count);
            if ($count !== 1) throw new RuntimeException("Expected one owned qrcodeextend block; found {$count}.");
            $this->writeAndVerify($current, $clean, 'clean');
            return 'Removed only the owned qrcodeextend Admin bridge.';
        });
    }

    public function restore(): string { return $this->removeOwnedChanges(); }

    private function inspect(string $contents): array
    {
        if (!$this->isSupportedBuild()) return $this->invalid('Unsupported Admin build: manifest does not select ' . self::TARGET_BUNDLE . '.');
        $starts = substr_count($contents, self::ANY_START); $ends = substr_count($contents, self::ANY_END);
        if ($starts !== $ends || $starts > 1) return $this->invalid("qrcodeextend markers are partial or duplicated (start={$starts}, end={$ends}).");
        $anchors = substr_count($contents, self::ANCHOR);
        if ($anchors !== 1) return $this->invalid("Admin action anchor count is {$anchors}; expected exactly one.");
        if ($starts === 1) {
            if (substr_count($contents, self::START_MARKER) !== 1 || substr_count($contents, self::END_MARKER) !== 1)
                return $this->invalid('A foreign or obsolete qrcodeextend ownership marker was found.');
            if (substr_count($contents, $this->ownedBlock()) !== 1)
                return $this->invalid('The owned qrcodeextend block was modified; refusing to overwrite or remove it.');
            if (strpos($contents, $this->ownedBlock() . self::ANCHOR) === false)
                return $this->invalid('The owned qrcodeextend block moved away from its anchor.');
            return $this->valid('patched', 'Managed qrcodeextend v3 bridge is valid.');
        }
        if ($this->containsLegacyBridge($contents)) return $this->valid('legacy', 'An unowned legacy qrcodeextend bridge was detected.');
        return $this->valid('clean', 'Supported build and one stable anchor found.');
    }

    private function ownedBlock(): string { return self::START_MARKER . $this->bridgeMenuItem() . self::END_MARKER; }

    private function bridgeMenuItem(): string
    {
        return 'Q.jsx($st,{onSelect:()=>{let e=()=>window.Qrcodeextend?.open?.(t.original);if(window.Qrcodeextend?.isReady?.())return e();window.addEventListener("qrcodeextend:ready",e,{once:!0});if(!document.querySelector("link[data-qrcodeextend]")){let e=document.createElement("link");e.rel="stylesheet",e.href="/plugins/qrcodeextend/qrcodeextend.css",e.dataset.qrcodeextend="",document.head.append(e)}if(!document.querySelector("script[data-qrcodeextend]")){let e=document.createElement("script");e.type="module",e.src="/plugins/qrcodeextend/qrcodeextend.js",e.dataset.qrcodeextend="",document.head.append(e)}},className:"p-0",children:Q.jsxs(xtt,{variant:"ghost",className:"w-full justify-start px-2 py-1.5",children:[Q.jsx("span",{className:"mr-2",children:"QR"}),r("columns.actions_menu.generate_qrcode")==="columns.actions_menu.generate_qrcode"?"生成订阅二维码":r("columns.actions_menu.generate_qrcode")]})}),';
    }

    private function containsLegacyBridge(string $contents): bool
    {
        return str_contains($contents, 'window.Qrcodeextend?.isReady?.()') || str_contains($contents, 'qrcodeextend:ready')
            || str_contains($contents, '/plugins/qrcodeextend/qrcodeextend.js');
    }

    private function isSupportedBuild(): bool
    {
        $path = $this->root . '/public/assets/admin/manifest.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($manifest) && ($manifest['index.html']['file'] ?? null) === self::TARGET_BUNDLE;
    }

    private function readBundle(): string
    {
        $path = $this->bundlePath();
        if (!is_file($path)) throw new RuntimeException('Admin bundle is missing: ' . self::TARGET_BUNDLE);
        $contents = file_get_contents($path);
        if ($contents === false) throw new RuntimeException('Could not read Admin bundle.');
        return $contents;
    }

    private function writeAndVerify(string $before, string $after, string $state): void
    {
        try {
            $this->atomicWrite($after);
            $written = $this->readBundle(); $check = $this->inspect($written);
            if (!hash_equals(hash('sha256', $after), hash('sha256', $written)) || !$check['valid'] || $check['state'] !== $state)
                throw new RuntimeException('Admin bridge post-write verification failed.');
        } catch (\Throwable $exception) {
            if (is_file($this->bundlePath()) && !hash_equals(hash('sha256', $before), hash_file('sha256', $this->bundlePath())))
                $this->atomicWrite($before);
            throw $exception;
        }
    }

    protected function atomicWrite(string $contents): void
    {
        $path = $this->bundlePath(); $temporary = $path . '.qrcodeextend-' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $contents) === false || !rename($temporary, $path)) {
            @unlink($temporary); throw new RuntimeException('Could not atomically replace the Admin bundle.');
        }
    }

    private function withLock(callable $operation): mixed
    {
        $path = $this->root . '/' . self::LOCK_FILE;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path)))
            throw new RuntimeException('Could not create the shared Admin patch lock directory.');
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle); throw new RuntimeException('Could not acquire shared Xboard Admin patch lock.');
        }
        try { return $operation(); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function bundlePath(): string { return $this->root . '/public/assets/admin/' . self::TARGET_BUNDLE; }
    private function pluginVersion(): ?string
    {
        $path = $this->root . '/plugins/Qrcodeextend/config.json';
        $config = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($config) ? ($config['version'] ?? null) : null;
    }
    private function valid(string $state, string $diagnostic): array { return compact('state', 'diagnostic') + ['valid' => true]; }
    private function invalid(string $diagnostic): array { return ['valid' => false, 'state' => 'invalid', 'diagnostic' => $diagnostic]; }
}
