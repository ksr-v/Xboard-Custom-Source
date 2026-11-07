<?php

declare(strict_types=1);

// Exact regular-file graphs and the updater's source matcher only. No Laravel,
// database, plugin boot, network access, or production directory is initialized.
use App\Services\Plugin\BundledPluginInstaller;

$sourceRoot = dirname(__DIR__, 2);
require $sourceRoot . '/app/Services/Plugin/BundledPluginInstaller.php';

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

// Original reviewed native QR 1.3.1 archive graph, independent of the current
// bundled manifest so source-policy regressions cannot rewrite the baseline.
$official = [
    'Commands/PatchCommand.php' => '445a3271dcdc6cb5c49a3ac69cbe9c71d751dee1743e8369f48fbb8fe2be4851',
    'Commands/RestoreCommand.php' => '145bf82c5d0023fcdf16652428c52dcd0dd7d238ed9f701a1b4190a8cf337828',
    'Commands/StatusCommand.php' => '7d8116585b7a4794e8288f333d5b53029fb99ca5dbca2fdfc45b78ba3b432518',
    'Plugin.php' => '2d73596c562a7c4bcb5bbae0229aff75918b3626d950e5089296389b4b65e8ad',
    'README.md' => '1aae97a43a5a6f06fdcfe78256636d5f17c101864c246b952aa6d8206fa96aec',
    'Services/AdminBundlePatcher.php' => '08ce494ab50c3011c6e9c0740d1149d1d561a7dd3562b044cc68a804fd09621a',
    'config.json' => 'a65566ecd26ce70fb3d36c5c07a81a4c2fd71c0a8f08aa68cddd23cf9cc148f5',
    'resources/assets/qrcodeextend.css' => '7f1a7cc3d4c086dc37f4ec9c8e8782e67bbf1d6f3a9415512c0fb563e2b428de',
    'resources/assets/qrcodeextend.js' => '535e930509857a6c054cb4158b1085deef53ccf24fd9b6ddf66792f7461e901f',
    'resources/assets/vendor/LICENSE.nayuki' => 'f9f4bba35e6ee9161fa917a4cb9943c463be6a6d447c7ce370239769c699ad57',
    'resources/assets/vendor/README.txt' => 'f347f88ec2f668653c4cd5687785994e7b6a4f92309bed6d272fad19273462bc',
    'resources/assets/vendor/qrcodegen.js' => '782dfb84800af8ba2fd946a79a561f51e06612e3a0c2b8d493a45729f119447c',
];
ksort($official);
$pluginRoot = $sourceRoot . '/bundled-plugins/Qrcodeextend';
$actual = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginRoot, FilesystemIterator::SKIP_DOTS)) as $entry) {
    check($entry->isFile() && !$entry->isLink(), 'Unexpected non-regular source member.');
    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($pluginRoot) + 1));
    $actual[$relative] = hash_file('sha256', $entry->getPathname());
}
ksort($actual);
$manifest = json_decode(file_get_contents($sourceRoot . '/bundled-plugins/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
$current = array_values(array_filter($manifest['plugins'], static fn (array $item): bool => $item['code'] === 'qrcodeextend'))[0];
$currentFiles = $actual;
$actual = null;
foreach ($current['accepted_previous'] as $previous) {
    if ($previous['version'] === '1.3.1' && ($previous['source_provenance']['kind'] ?? null) === 'panel-working-tree-overlay') {
        $actual = $previous['files']; ksort($actual);
    }
}
check(is_array($actual), 'Historical local QR 1.3.1 overlay is missing from accepted_previous.');
$matches = static fn (array $live, array $release = [], string $version = '1.3.1', string $code = 'qrcodeextend'): bool =>
    BundledPluginInstaller::matchesApprovedRuntimeSource($code, $version, $live, $release ?: $official);
$checks = [];
$test = static function (string $name, callable $body) use (&$checks): void {
    try { $body(); $checks[] = ['name' => $name, 'passed' => true]; }
    catch (Throwable $error) { $checks[] = ['name' => $name, 'passed' => false, 'error' => $error->getMessage()]; }
};

$test('official QR 1.3.2 runtime exactly matches its native source manifest', static function () use ($current, $currentFiles): void {
    check($current['version'] === '1.3.2' && $currentFiles === $current['files'], 'Current official runtime graph differs from its manifest.');
    $provenance = $current['source_provenance'];
    $published = ($provenance['kind'] ?? null) === 'github-release-asset'
        && ($provenance['release_tag'] ?? null) === 'v1.3.2'
        && ($provenance['tag_commit'] ?? null) === $current['source_commit']
        && ($provenance['asset_name'] ?? null) === 'qrcodeextend-1.3.2.zip'
        && ($provenance['archive_sha256'] ?? null) === '0688c4f21d93a022db7433f4d11251c14c31bea42ac1065b2fe74b5f54271aef'
        && ($provenance['archive_size'] ?? null) === 28881
        && is_int($provenance['release_id'] ?? null) && $provenance['release_id'] > 0
        && is_int($provenance['asset_id'] ?? null) && $provenance['asset_id'] > 0;
    check($published, 'Current source lost its verified native release identity.');
    check(BundledPluginInstaller::matchesApprovedRuntimeSource('qrcodeextend', '1.3.2', $currentFiles, $current['files']),
        'Exact current official QR source was rejected.');
});

$test('historical QR 1.3.1 complete two-file overlay matches its original native release', static function () use ($actual, $official, $matches): void {
    $expected = $official;
    $expected['Plugin.php'] = 'ab99c90d1b259bd9bd0a8fd10105362cf7f49ca1b134fcc13c96064a480cb545';
    $expected['Services/AdminBundlePatcher.php'] = '7b8f6f984f52c49e616ec4644baa948db516b13332eee41568fd2d07f1f38380';
    check($actual === $expected, 'Historical local overlay differs from the exact reviewed source bytes.');
    $changes = array_diff_assoc($actual, $official);
    check(array_keys($changes) === ['Plugin.php', 'Services/AdminBundlePatcher.php'], 'Historical source differs outside the reviewed two-file overlay.');
    check($matches($actual), 'Complete reviewed QR 1.3.1 source was treated as an older same-version release.');
    check($matches($official), 'Exact original native source was rejected.');
});

$test('QR 1.3.1 overlay cannot escape its plugin or version boundary', static function () use ($actual, $matches): void {
    foreach (['1.2.0', '1.3.0', '1.3.2'] as $version) {
        check(!$matches($actual, [], $version), 'Overlay was accepted for ' . $version . '.');
    }
    check(!$matches($actual, [], '1.3.1', 'smart_expiry'), 'QR overlay was accepted for another plugin.');
});

$test('either partial QR 1.3.1 overlay is rejected', static function () use ($actual, $official, $matches): void {
    foreach (['Plugin.php', 'Services/AdminBundlePatcher.php'] as $path) {
        $partial = $actual;
        $partial[$path] = $official[$path];
        check(!$matches($partial), 'Partial overlay was accepted with original ' . $path . '.');
    }
});

$test('unknown extras, missing source, and unrelated edits are rejected', static function () use ($actual, $matches): void {
    $extra = $actual + ['unknown.php' => hash('sha256', 'unreviewed extra')];
    check(!$matches($extra), 'Unknown extra file was ignored.');
    $missing = $actual;
    unset($missing['resources/assets/qrcodeextend.js']);
    check(!$matches($missing), 'Missing runtime asset was ignored.');
    foreach (['README.md', 'config.json', 'resources/assets/qrcodeextend.js', 'Plugin.php', 'Services/AdminBundlePatcher.php'] as $path) {
        $modified = $actual;
        $modified[$path] = hash('sha256', 'unreviewed edit of ' . $path);
        check(!$matches($modified), 'Unreviewed edit was accepted: ' . $path . '.');
    }
});

$test('original native file hashes remain pinned before applying the overlay', static function () use ($actual, $official, $matches): void {
    foreach (['Plugin.php', 'Services/AdminBundlePatcher.php', 'config.json'] as $path) {
        $differentRelease = $official;
        $differentRelease[$path] = hash('sha256', 'different official bytes for ' . $path);
        check(!$matches($actual, $differentRelease), 'Different native source was masked: ' . $path . '.');
    }
});

$test('historical QR 1.2.0 published two-file overlay remains accepted unchanged', static function () use ($sourceRoot): void {
    $manifest = json_decode(file_get_contents($sourceRoot . '/bundled-plugins/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    $original = $patched = null;
    foreach ($manifest['plugins'] as $item) {
        if ($item['code'] !== 'qrcodeextend') { continue; }
        foreach ($item['accepted_previous'] as $previous) {
            if ($previous['version'] !== '1.2.0') { continue; }
            $files = $previous['files'];
            if (($files['Services/AdminBundlePatcher.php'] ?? null) === '42f4b1e41f936bde1e340e629c4ca5e6e43d9cf457db1792077362a34f82a4c9') { $original = $files; }
            if (($files['Services/AdminBundlePatcher.php'] ?? null) === '9ae7ed11f431992fb11e83671ab8da731438e1f75985a7b6ae07f77f3afe235e') { $patched = $files; }
        }
    }
    check(is_array($original) && is_array($patched), 'Historical reviewed QR source graphs are missing.');
    check(BundledPluginInstaller::matchesApprovedRuntimeSource('qrcodeextend', '1.2.0', $patched, $original), 'Published QR 1.2.0 overlay was rejected.');
    $partial = $patched;
    $partial['Plugin.php'] = $original['Plugin.php'];
    check(!BundledPluginInstaller::matchesApprovedRuntimeSource('qrcodeextend', '1.2.0', $partial, $original), 'Partial historical overlay was accepted.');
    check(!BundledPluginInstaller::matchesApprovedRuntimeSource('qrcodeextend', '1.3.1', $patched, $original), 'Historical overlay escaped its version boundary.');
});

$passed = count(array_filter($checks, static fn (array $check): bool => $check['passed']));
echo json_encode(['type' => 'standalone source-graph compatibility checks; no Laravel or network',
    'passed' => $passed, 'total' => count($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($passed === count($checks) ? 0 : 1);
