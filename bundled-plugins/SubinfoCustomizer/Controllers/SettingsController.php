<?php

namespace Plugin\SubinfoCustomizer\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Plugin\SubinfoCustomizer\Plugin;
use Plugin\SubinfoCustomizer\Services\Settings;
use Plugin\SubinfoCustomizer\Services\TemplateService;

final class SettingsController extends Controller
{
    public function show()
    {
        try {
            $settings = Settings::read();
            $warning = null;
        } catch (\Throwable) {
            $settings = TemplateService::defaults();
            $warning = '已保存配置无法读取。当前显示默认草稿，请核对后保存以修复。';
        }
        return response()->json(['data' => [
            'version' => Plugin::VERSION, 'settings' => $settings,
            'defaults' => TemplateService::defaults(), 'warning' => $warning,
            'variables' => array_map(static fn (string $name, string $label): array => compact('name', 'label'),
                array_keys(TemplateService::VARIABLES), array_values(TemplateService::VARIABLES)),
            'sample_variables' => TemplateService::sampleVariables(),
            'sample_date' => now((string) config('app.timezone', 'Asia/Shanghai'))->format('Y-m-d'),
            'sample_note' => '预览使用示例用户与流量；正式订阅只使用当前用户真实数据。',
        ]], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function save(Request $request)
    {
        try {
            $settings = TemplateService::validate($request->all());
            TemplateService::render($settings, TemplateService::sampleVariables());
            Settings::write($settings);
            return response()->json(['data' => ['settings' => $settings]], 200, ['Cache-Control' => 'no-store, private']);
        } catch (\InvalidArgumentException|\JsonException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function defaults()
    {
        return response()->json(['data' => TemplateService::defaults()], 200, ['Cache-Control' => 'no-store, private']);
    }
}
