<?php

declare(strict_types=1);

namespace Plugin\SubinfoCustomizer\Services;

use Illuminate\View\View;
use Plugin\SubinfoCustomizer\Plugin;

/** Compose the named Admin view without modifying any shared Admin asset. */
final class AdminIntegration
{
    private const EVENT = 'composing: admin';
    private const LISTENER = self::class . '@compose';
    public const BUILD = 'index-CEIYH7i8.js';

    public function register(): void
    {
        $events = app('events');
        if (!in_array(self::LISTENER, $events->getRawListeners()[self::EVENT] ?? [], true)) {
            $events->listen(self::EVENT, self::LISTENER);
        }
    }

    public function compose(View $view): void
    {
        if (!Settings::isEnabled()) {
            return;
        }
        $wrapper = dirname(__DIR__) . '/resources/views/admin.blade.php';
        $original = $view->getPath();
        if ($original === $wrapper || !is_file($original) || !is_file($wrapper)) {
            return;
        }
        // Preserve preceding composers and their data. Rendering by file path
        // does not recursively invoke the named "admin" view composer.
        $view->with('__subInfoCustomizerOriginalPath', $original);
        $view->with('__subInfoCustomizerOriginalData', $view->getData());
        $view->setPath($wrapper);
    }

    public static function compatibility(?string $root = null): array
    {
        $root ??= base_path();
        $result = ['supported' => false, 'build' => null];
        $manifestPath = $root . '/public/assets/admin/manifest.json';
        $manifest = is_readable($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        $entry = is_array($manifest) ? ($manifest['index.html']['file'] ?? null) : null;
        if (!is_string($entry)) {
            return $result;
        }
        $result['build'] = basename($entry);
        if ($entry !== 'assets/' . self::BUILD) {
            return $result;
        }
        $file = $root . '/public/assets/admin/' . $entry;
        $bundle = is_readable($file) ? file_get_contents($file) : false;
        if ($bundle === false) {
            return $result;
        }
        // Structural checks allow the four bundled plugins' unrelated patches.
        foreach ([
            'function Jlt({links:e,isCollapsed:t,className:n,closeNav:i})',
            'getKey(e){return`${this.prefixKey}${e}`.toUpperCase()}',
            'Q.jsx("main",{id:"content",className:',
            'id:"dashboard",title:"nav:dashboard",label:"",href:"/"',
        ] as $signature) {
            if (substr_count($bundle, $signature) !== 1) {
                return $result;
            }
        }
        $result['supported'] = true;
        return $result;
    }

    public static function publicConfiguration(): array
    {
        return [
            'owner' => 'subinfo_customizer',
            'version' => Plugin::VERSION,
            'enabled' => Settings::isEnabled(),
            'apiBase' => '/api/v2/subinfo-customizer',
            'timezone' => (string) config('app.timezone', 'UTC'),
            'compatibility' => self::compatibility(),
        ];
    }

    public static function inject(string $html): string
    {
        if (!Settings::isEnabled() || str_contains($html, 'data-subinfo-customizer-loader')) {
            return $html;
        }
        $position = strripos($html, '</body>');
        if ($position === false) {
            return $html;
        }
        $configuration = self::publicConfiguration();
        if (!$configuration['compatibility']['supported']) {
            return $html;
        }
        $encoded = json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $version = rawurlencode(Plugin::VERSION);
        $addition = "\n" . '<script data-subinfo-customizer-loader data-subinfo-customizer-asset="1">window.__SUBINFO_CUSTOMIZER__=' . $encoded . ';</script>'
            . "\n" . '<link data-subinfo-customizer-asset="1" rel="stylesheet" href="/api/v2/subinfo-customizer/assets/style?v=' . $version . '">'
            . "\n" . '<script data-subinfo-customizer-asset="1" defer src="/api/v2/subinfo-customizer/assets/script?v=' . $version . '"></script>' . "\n";
        return substr($html, 0, $position) . $addition . substr($html, $position);
    }
}
