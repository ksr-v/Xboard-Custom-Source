<?php

/** Run with the tested site's PHP 8.3 CLI: php backend_checks.php /path/to/site. */
declare(strict_types=1);

use App\Models\User;
use App\Models\Plan;
use App\Services\TrafficResetService;
use App\Services\UserService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugin\SubinfoCustomizer\Controllers\SettingsController;
use Plugin\SubinfoCustomizer\Services\CoreBridgePatcher;
use Plugin\SubinfoCustomizer\Services\Settings;
use Plugin\SubinfoCustomizer\Services\TemplateService;

$root = $argv[1] ?? dirname(__DIR__);
require $root . '/vendor/autoload.php';
$application = require $root . '/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
$results = [];
$test = static function (string $name, callable $body) use (&$results): void {
    try {
        $body();
        $results[] = ['name' => $name, 'passed' => true];
    } catch (Throwable $exception) {
        $results[] = ['name' => $name, 'passed' => false, 'error' => $exception->getMessage()];
    }
};
$same = static function (mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException('expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE)
            . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
};
$reject = static function (callable $operation): void {
    try { $operation(); }
    catch (InvalidArgumentException|RuntimeException|JsonException) { return; }
    throw new RuntimeException('invalid operation was accepted');
};
$settings = static fn (array $rows, string $format = 'MM-DD'): array => ['date_format' => $format, 'rows' => $rows];
$row = static fn (string $template, bool $enabled = true): array => compact('template', 'enabled');

$test('template counts 0–4, order and disabled row', static function () use ($same, $settings, $row): void {
    $sample = TemplateService::sampleVariables();
    for ($count = 0; $count <= 4; ++$count) {
        $rows = [];
        for ($index = 0; $index < $count; ++$index) { $rows[] = $row('行' . $index . ':{uid}'); }
        $names = TemplateService::render($settings($rows), $sample);
        $same($count, count($names));
        foreach ($names as $index => $name) { $same('行' . $index . ':1', $name); }
    }
    $same(['后', '前'], TemplateService::render($settings([$row('后'), $row('隐藏', false), $row('前')]), $sample));
});

$test('all whitelisted variables, Unicode and plain text', static function () use ($same, $settings, $row): void {
    $sample = TemplateService::sampleVariables();
    foreach ($sample as $key => $value) {
        $same(['中文🌍👨‍👩‍👧 café & = | ' . $value], TemplateService::render($settings([$row('中文🌍👨‍👩‍👧 café & = | {' . $key . '}')]), $sample));
    }
});

$test('server validation rejects over-limit and malformed settings', static function () use ($reject, $settings, $row): void {
    foreach ([
        $settings(array_fill(0, 5, $row('a'))),
        $settings([$row(str_repeat('界', 161))]),
        $settings([$row(' ')]),
        $settings([$row('{unknown}')]),
        $settings([$row('{{7*7}}')]),
        $settings([$row('<?php system("id"); ?>')]),
        $settings([$row('<img src=x onerror=alert(1)>')]),
        $settings([$row("a\nb")]),
        $settings([$row("a\0b")]),
        $settings([$row("a\u{202E}b")]),
        $settings([$row("bad\xC3\x28")]),
        $settings([['enabled' => 1, 'template' => 'a']]),
        $settings([['enabled' => true, 'template' => 'a', 'code' => 'x']]),
        $settings([2 => $row('a')]),
        $settings([], 'DD-MM'),
        ['rows' => []],
        ['date_format' => 'MM-DD', 'rows' => [], 'php' => 'x'],
    ] as $input) {
        $reject(static fn () => TemplateService::validate($input));
    }
});

$test('binary traffic formatting and negative clamp', static function () use ($same): void {
    foreach ([-1 => '0B', 0 => '0B', 1 => '1B', 1023 => '1023B', 1024 => '1K', 1048576 => '1M',
        1073741824 => '1G', 1099511627776 => '1T', 1125899906842624 => '1P'] as $bytes => $text) {
        $same($text, TemplateService::formatTraffic($bytes));
    }
    $same('97.66T', TemplateService::formatTraffic(100000 * 1073741824));
});

$test('real User accounting and dates; reset boundary uses an explicit service stub', static function () use ($same): void {
    $originalService = app(UserService::class);
    $user = new User();
    $user->forceFill(['id' => 42, 'u' => 2 * 1073741824, 'd' => 3 * 1073741824,
        'transfer_enable' => 10 * 1073741824, 'expired_at' => null]);
    try {
        app()->instance(UserService::class, new class extends UserService {
            public function getResetDay(User $user): ?int { return null; }
        });
        $variables = TemplateService::variables($user, 'MM-DD');
        $same('42', $variables['uid']);
        $same('5G', $variables['remaining_traffic']);
        $same('10G', $variables['total_traffic']);
        $same('5G', $variables['used_traffic']);
        $same('2G', $variables['upload_traffic']);
        $same('3G', $variables['download_traffic']);
        $same('长期有效', $variables['expire_date']);
        $same('无', $variables['reset_days']);
        $same(now(config('app.timezone'))->format('m-d'), $variables['current_date']);
        app()->instance(UserService::class, new class extends UserService {
            public function getResetDay(User $user): ?int { return 0; }
        });
        $user->expired_at = Carbon\Carbon::create(2031, 1, 2, 0, 0, 0, config('app.timezone'))->timestamp;
        $user->transfer_enable = 4 * 1073741824;
        $variables = TemplateService::variables($user, 'YYYY-MM-DD');
        $same('0', $variables['reset_days']);
        $same('2031-01-02', $variables['expire_date']);
        $same('0B', $variables['remaining_traffic']);
        $same(now(config('app.timezone'))->format('Y-m-d'), $variables['current_date']);
    } finally { app()->instance(UserService::class, $originalService); }
});

$test('native UserService/TrafficResetService reset=0 vs null under a controlled CLI Carbon clock (not natural FPM)', static function () use ($same): void {
    $timezone = (string) config('app.timezone');
    $realNow = Carbon\Carbon::createFromTimestamp(time(), $timezone);
    $oldTestNow = Carbon\Carbon::getTestNow();
    $plan = new Plan();
    $plan->forceFill(['reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]);
    $user = new User();
    $user->forceFill(['id' => 43, 'u' => 0, 'd' => 0, 'transfer_enable' => 1073741824,
        'expired_at' => $realNow->copy()->addYear()->day(15)->startOfDay()->timestamp]);
    $user->setRelation('plan', $plan);
    try {
        // Only this CLI process's Carbon clock is shifted. PHP time() and the
        // VM system clock remain untouched. Both production services are real.
        Carbon\Carbon::setTestNow($realNow->copy()->subMonthsNoOverflow(2)->startOfMonth()->addDays(4));
        $next = app(TrafficResetService::class)->calculateNextResetTime($user);
        $same(true, $next !== null && $next->timestamp <= time());
        $same(0, app(UserService::class)->getResetDay($user));
        $same('0', TemplateService::variables($user, 'MM-DD')['reset_days']);
        $user->expired_at = null;
        $same(null, app(UserService::class)->getResetDay($user));
        $same('无', TemplateService::variables($user, 'MM-DD')['reset_days']);
    } finally { Carbon\Carbon::setTestNow($oldTestNow); }
});

$test('pseudo-node collision handling preserves every real node', static function () use ($same, $settings, $row): void {
    $originalService = app(UserService::class);
    try {
        app()->instance(UserService::class, new class extends UserService {
            public function getResetDay(User $user): ?int { return null; }
        });
        $user = new User();
        $user->forceFill(['id' => 1, 'u' => 0, 'd' => 0, 'transfer_enable' => 100, 'expired_at' => null]);
        $servers = [
            ['id' => 1, 'name' => '真实🌍节点', 'host' => 'example.test', 'port' => 443, 'password' => 'test-only'],
            ['id' => 2, 'name' => '真实🌍节点 · 信息1', 'host' => 'second.test', 'port' => 8443],
        ];
        $output = TemplateService::prepend($servers, $user, $settings([$row('真实🌍节点'), $row('真实🌍节点')]));
        $same($servers, array_slice($output, 2));
        $same(true, $output[0]['__subinfo_customizer']);
        $same(4, count(array_unique(array_column($output, 'name'))));
        $same($servers, TemplateService::prepend($servers, $user, $settings([])));
        $same([], TemplateService::prepend([], $user, $settings([$row('a')])));
    } finally { app()->instance(UserService::class, $originalService); }
});

// Fixtures are isolated copies. Never mutate the site's real ClientController.
$directory = sys_get_temp_dir() . '/subinfo-backend-' . bin2hex(random_bytes(8));
$controllerDirectory = $directory . '/app/Http/Controllers/V1/Client';
mkdir($controllerDirectory, 0775, true);
mkdir($directory . '/storage/framework', 0775, true);
$path = $directory . '/' . CoreBridgePatcher::RELATIVE_PATH;
$source = str_replace("\r\n", "\n", (string) file_get_contents($root . '/' . CoreBridgePatcher::RELATIVE_PATH));
$source = str_replace(CoreBridgePatcher::insertion(), '', $source);
$patcher = new CoreBridgePatcher($directory);
try {
    $test('bridge LF/CRLF/mixed apply/remove and repeated lifecycle are byte-exact', static function () use ($same, $source, $path, $patcher): void {
        $mixed = preg_replace('/\n/', "\r\n", $source, 5);
        foreach ([$source, str_replace("\n", "\r\n", $source), $mixed] as $original) {
            file_put_contents($path, $original);
            $same('source', $patcher->inspect());
            $same('patched', $patcher->apply());
            $same('already patched', $patcher->apply());
            $same(2, substr_count((string) file_get_contents($path), CoreBridgePatcher::MARKER));
            $same('removed', $patcher->remove());
            $same('not patched', $patcher->remove());
            $same($original, file_get_contents($path));
        }
    });

    $test('unknown core changes and corrupt ownership markers refuse writes', static function () use ($same, $reject, $source, $path, $patcher): void {
        $unknown = $source . "\n// future-core-change\n";
        file_put_contents($path, $unknown);
        $reject(static fn () => $patcher->apply());
        $same($unknown, file_get_contents($path));
        file_put_contents($path, $source);
        $patcher->apply();
        $changed = (string) file_get_contents($path) . "\n// another owner changed this file\n";
        file_put_contents($path, $changed);
        $reject(static fn () => $patcher->remove());
        $same($changed, file_get_contents($path));
        $corrupt = str_replace('return;', 'return; // corrupted', (string) str_replace("\n// another owner changed this file\n", '', $changed));
        file_put_contents($path, $corrupt);
        $reject(static fn () => $patcher->apply());
        $same($corrupt, file_get_contents($path));
    });

    $test('injected lock-path fault leaves core bytes untouched', static function () use ($same, $reject, $directory, $path, $source, $patcher): void {
        file_put_contents($path, $source);
        $lock = $directory . '/storage/framework/xboard-admin-patch.lock';
        if (is_file($lock)) { unlink($lock); }
        if (!symlink($path, $lock)) { throw new RuntimeException('unable to construct symlink fault'); }
        try {
            $reject(static fn () => $patcher->apply());
            $same($source, file_get_contents($path));
        } finally { unlink($lock); }
    });
} finally {
    foreach (glob($controllerDirectory . '/.subinfo-customizer-*') ?: [] as $temporary) { unlink($temporary); }
    if (is_file($path)) { unlink($path); }
    $lock = $directory . '/storage/framework/xboard-admin-patch.lock';
    if (is_file($lock) || is_link($lock)) { unlink($lock); }
    foreach (['/app/Http/Controllers/V1/Client', '/app/Http/Controllers/V1', '/app/Http/Controllers', '/app/Http', '/app',
        '/storage/framework', '/storage', ''] as $relative) { rmdir($directory . $relative); }
}

$test('settings persist valid data and controller offers recovery for corrupt JSON (DB transaction rolled back)', static function () use ($same, $reject): void {
    DB::beginTransaction();
    try {
        $valid = ['date_format' => 'YYYY-MM-DD', 'rows' => [['enabled' => true, 'template' => 'UID:{uid}']]];
        Settings::write($valid);
        $same($valid, Settings::read());
        DB::table(Settings::TABLE)->where('id', 1)->update(['settings' => '{bad json']);
        $reject(static fn () => Settings::read());
        $response = (new SettingsController())->show()->getData(true);
        $same(TemplateService::defaults(), $response['data']['settings']);
        $same(true, is_string($response['data']['warning']) && $response['data']['warning'] !== '');
        Settings::write(TemplateService::defaults());
        $same(TemplateService::defaults(), Settings::read());
    } finally { DB::rollBack(); }
});

$passed = count(array_filter($results, static fn (array $result): bool => $result['passed']));
echo json_encode(['type' => 'Laravel CLI targeted backend checks; not HTTP/FPM acceptance',
    'php_version' => PHP_VERSION, 'laravel_version' => $application->version(),
    'passed' => $passed, 'total' => count($results), 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed === count($results) ? 0 : 1);
