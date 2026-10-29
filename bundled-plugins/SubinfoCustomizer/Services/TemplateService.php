<?php

namespace Plugin\SubinfoCustomizer\Services;

use App\Models\User;
use App\Services\UserService;
use InvalidArgumentException;

/** Plain-text substitution only: no PHP, Blade, HTML or expression evaluator. */
final class TemplateService
{
    public const MAX_ROWS = 4;
    public const MAX_TEMPLATE_LENGTH = 160;
    public const MAX_NAME_LENGTH = 240;
    public const VARIABLES = [
        'current_date' => '当前获取订阅日期',
        'uid' => '用户 ID',
        'remaining_traffic' => '剩余流量',
        'total_traffic' => '套餐总流量',
        'used_traffic' => '已使用流量',
        'upload_traffic' => '上传流量',
        'download_traffic' => '下载流量',
        'expire_date' => '到期日期',
        'reset_days' => '距下次重置剩余天数',
    ];

    public static function defaults(): array
    {
        return ['date_format' => 'MM-DD', 'rows' => [
            ['enabled' => true, 'template' => '订阅:{current_date}|UID:{uid}'],
            ['enabled' => true, 'template' => '流量:{remaining_traffic}/{total_traffic}'],
            ['enabled' => true, 'template' => '到期:{expire_date}|重置:{reset_days}'],
        ]];
    }

    public static function validate(array $input): array
    {
        if (array_diff(array_keys($input), ['date_format', 'rows'])
            || !isset($input['date_format']) || !in_array($input['date_format'], ['MM-DD', 'YYYY-MM-DD'], true)
            || !isset($input['rows']) || !is_array($input['rows']) || !array_is_list($input['rows'])
            || count($input['rows']) > self::MAX_ROWS) {
            throw new InvalidArgumentException('设置必须包含合法日期格式和 0–4 行信息。');
        }
        $rows = [];
        foreach ($input['rows'] as $index => $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['enabled', 'template'])
                || !array_key_exists('enabled', $row) || !is_bool($row['enabled'])
                || !isset($row['template']) || !is_string($row['template'])) {
                throw new InvalidArgumentException('第 ' . ($index + 1) . ' 行格式无效。');
            }
            self::validateTemplate($row['template'], $index + 1);
            $rows[] = ['enabled' => $row['enabled'], 'template' => trim($row['template'])];
        }
        return ['date_format' => $input['date_format'], 'rows' => $rows];
    }

    private static function validateTemplate(string $template, int $line): void
    {
        $controlCheck = str_replace(["\u{200C}", "\u{200D}"], '', $template);
        if (!mb_check_encoding($template, 'UTF-8') || mb_strlen($template, 'UTF-8') > self::MAX_TEMPLATE_LENGTH
            || trim($template) === '' || preg_match('/^[\s\x{200C}\x{200D}]*$/u', $template)
            || preg_match('/[\p{Cc}\p{Cs}\p{Cf}\x{2028}\x{2029}<>]/u', $controlCheck)) {
            throw new InvalidArgumentException("第 {$line} 行须为 1–160 个有效字符，不允许 HTML、代码标签、换行或控制字符。");
        }
        $text = preg_replace_callback('/\{([a-z_]+)\}/u', function (array $match) use ($line): string {
            if (!array_key_exists($match[1], self::VARIABLES)) {
                throw new InvalidArgumentException("第 {$line} 行包含未知变量：{$match[0]}。");
            }
            return '';
        }, $template);
        if ($text === null || str_contains($text, '{') || str_contains($text, '}')) {
            throw new InvalidArgumentException("第 {$line} 行包含非法变量或模板表达式。");
        }
    }

    /** Reuse the User model's accounting; never reset or write user traffic here. */
    public static function variables(User $user, string $dateFormat): array
    {
        $format = $dateFormat === 'YYYY-MM-DD' ? 'Y-m-d' : 'm-d';
        $timezone = (string) config('app.timezone', 'Asia/Shanghai');
        $reset = app(UserService::class)->getResetDay($user);
        return [
            'current_date' => now($timezone)->format($format),
            'uid' => (string) $user->id,
            'remaining_traffic' => self::formatTraffic($user->getRemainingTraffic()),
            'total_traffic' => self::formatTraffic($user->transfer_enable ?? 0),
            'used_traffic' => self::formatTraffic($user->getTotalUsedTraffic()),
            'upload_traffic' => self::formatTraffic($user->u ?? 0),
            'download_traffic' => self::formatTraffic($user->d ?? 0),
            'expire_date' => $user->expired_at === null ? '长期有效'
                : \Carbon\Carbon::createFromTimestamp((int) $user->expired_at, $timezone)->format($format),
            'reset_days' => $reset === null ? '无' : (string) $reset,
        ];
    }

    /** Binary units, matching the panel's byte accounting, with compact T/G/M names. */
    public static function formatTraffic(int|float $bytes): string
    {
        $value = max(0, $bytes);
        $units = ['B', 'K', 'M', 'G', 'T', 'P', 'E'];
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            ++$index;
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . $units[$index];
    }

    public static function render(array $settings, array $variables): array
    {
        $settings = self::validate($settings);
        $replace = [];
        foreach (array_keys(self::VARIABLES) as $name) {
            if (!array_key_exists($name, $variables)) {
                throw new InvalidArgumentException('缺少动态变量：' . $name);
            }
            $replace['{' . $name . '}'] = (string) $variables[$name];
        }
        $names = [];
        foreach ($settings['rows'] as $row) {
            if (!$row['enabled']) { continue; }
            $name = strtr($row['template'], $replace);
            if (!mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
                throw new InvalidArgumentException('变量展开后的信息超过 240 个字符。');
            }
            $names[] = $name;
        }
        return $names;
    }

    /** The caller invokes this before native pseudo-nodes exist; real nodes stay intact. */
    public static function prepend(array $servers, User $user, array $settings): array
    {
        if (!$servers) { return $servers; }
        $names = self::render($settings, self::variables($user, $settings['date_format']));
        $usedNames = array_fill_keys(array_map(static fn (array $server): string => (string) ($server['name'] ?? ''), $servers), true);
        $rows = [];
        foreach ($names as $index => $name) {
            $baseName = $name;
            $suffix = 1;
            while (isset($usedNames[$name])) {
                $label = ' · 信息' . ($index + 1) . ($suffix > 1 ? '-' . $suffix : '');
                $name = mb_substr($baseName, 0, self::MAX_NAME_LENGTH - mb_strlen($label, 'UTF-8'), 'UTF-8') . $label;
                ++$suffix;
            }
            $usedNames[$name] = true;
            // Same representation as the native info rows: a copy of the first
            // surviving node. No connection fields on any real node are changed.
            $rows[] = array_replace($servers[0], ['name' => $name, '__subinfo_customizer' => true]);
        }
        return array_merge($rows, $servers);
    }

    public static function sampleVariables(): array
    {
        return [
            'current_date' => now((string) config('app.timezone', 'Asia/Shanghai'))->format('m-d'),
            'uid' => '1', 'remaining_traffic' => '97.52T', 'total_traffic' => '97.66T',
            'used_traffic' => '0.14T', 'upload_traffic' => '0.02T', 'download_traffic' => '0.12T',
            'expire_date' => '长期有效', 'reset_days' => '无',
        ];
    }
}
