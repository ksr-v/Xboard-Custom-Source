<?php

namespace Plugin\SubinfoCustomizer\Services;

use App\Models\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Settings
{
    public const CODE = 'subinfo_customizer';
    public const TABLE = 'subinfo_customizer_settings';

    public static function isEnabled(): bool
    {
        return (bool) Plugin::where('code', self::CODE)->value('is_enabled');
    }

    public static function read(): array
    {
        if (!Schema::hasTable(self::TABLE)) { return TemplateService::defaults(); }
        $json = DB::table(self::TABLE)->where('id', 1)->value('settings');
        if ($json === null) { return TemplateService::defaults(); }
        $settings = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) { throw new \RuntimeException('订阅信息配置损坏，请在管理页面恢复默认。'); }
        return TemplateService::validate($settings);
    }

    public static function write(array $settings): void
    {
        $settings = TemplateService::validate($settings);
        // No cache: a new subscription request observes the latest committed row.
        DB::transaction(function () use ($settings): void {
            DB::table(self::TABLE)->updateOrInsert(['id' => 1], [
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
