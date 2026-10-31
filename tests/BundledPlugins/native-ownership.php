<?php
declare(strict_types=1);
$GLOBALS['__composer_autoload_files']['a03e85e827f2f53ca6b173cf8d947ac6'] = true;
require __DIR__ . '/bootstrap.php';

use App\Services\Plugin\BundledPluginInstaller;

const NATIVE_FIXTURE_SOURCES = [
    'node_endpoint' => ['ksr-v/Xboard-Independent-NodeEndpoint', 'NodeEndpoint-%s.zip', 'SHA256SUMS'],
    'smart_expiry' => ['ksr-v/xb-SmartExpiry', 'SmartExpiry-%s-full.zip', 'github-asset-digest'],
    'qrcodeextend' => ['ksr-v/xb-qrcode-extend', 'qrcodeextend-%s.zip', 'SHA256SUMS'],
    'flowscope' => ['ksr-v/xb-flowscope', 'flowscope-%s.zip', 'SHA256SUMS-v%s'],
    'subinfo_customizer' => ['ksr-v/xb-SubInfoCustomizer', 'SubInfoCustomizer-v%s.zip', 'SHA256SUMS'],
];
function nativeFixture(string $name, string $code = 'smart_expiry'): array {
    $root = fixtureRoot('native-ownership-' . $name);
    $installer = new BundledPluginInstaller($root); $installer->stage();
    $path = $root . '/storage/app/private/plugin-bundles/ownership.json';
    $ownership = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    $owned = &$ownership['plugins'][$code];
    [$repo, $asset, $checksum] = NATIVE_FIXTURE_SOURCES[$code];
    $source = $root . '/plugins/' . $owned['directory'] . '/Plugin.php';
    file_put_contents($source, "\n// exact native same-version artifact fixture\n", FILE_APPEND);
    $owned['files']['Plugin.php'] = hash_file('sha256', $source);
    $owned['source_repository'] = 'https://github.com/' . $repo;
    $owned['source_commit'] = str_repeat('a', 40);
    $owned['source_provenance'] = [
        'kind' => 'github-release-asset', 'release_id' => 23, 'release_tag' => 'v' . $owned['version'],
        'tag_object' => str_repeat('b', 40), 'tag_commit' => $owned['source_commit'], 'asset_id' => 24,
        'asset_name' => sprintf($asset, $owned['version']), 'archive_sha256' => str_repeat('c', 64),
        'archive_size' => 1000, 'checksum_source' => sprintf($checksum, $owned['version']),
    ];
    unset($owned['bundle_version']); unset($owned);
    file_put_contents($path, json_encode($ownership, JSON_THROW_ON_ERROR));
    return [$root, $installer, $path, $ownership];
}
foreach (array_keys(NATIVE_FIXTURE_SOURCES) as $code) {
    test('same-version native source is preserved independently of panel bundle dates: ' . $code, function () use ($code): void {
        [$root, $installer, $path, $ownership] = nativeFixture($code, $code);
        $before = file_get_contents($path);
        $report = $installer->stage();
        check($report[$code]['action'] === 'preserved-newer' && $report[$code]['source'] === 'github-release-asset', 'Native source was replaced by the panel bundle');
        check(json_decode(file_get_contents($path), true)['plugins'][$code] === $ownership['plugins'][$code], 'Native provenance/files were overwritten');
        check($installer->stage()[$code]['action'] === 'preserved-newer', 'Native preservation is not idempotent');
    });
}
foreach (['repository', 'commit', 'tag', 'asset', 'checksum', 'release_id', 'archive_size'] as $invalid) {
    test('invalid native identity is refused before source synchronization: ' . $invalid, function () use ($invalid): void {
        [$root, $installer, $path, $ownership] = nativeFixture('invalid-' . $invalid);
        $owned = &$ownership['plugins']['smart_expiry'];
        match ($invalid) {
            'repository' => $owned['source_repository'] = 'https://github.com/other/xb-SmartExpiry',
            'commit' => $owned['source_provenance']['tag_commit'] = str_repeat('d', 40),
            'tag' => $owned['source_provenance']['release_tag'] = 'latest',
            'asset' => $owned['source_provenance']['asset_name'] = 'source.zip',
            'checksum' => $owned['source_provenance']['checksum_source'] = 'unverified',
            'release_id' => $owned['source_provenance']['release_id'] = 0,
            'archive_size' => $owned['source_provenance']['archive_size'] = 33554433,
        };
        unset($owned); file_put_contents($path, json_encode($ownership, JSON_THROW_ON_ERROR));
        $before = file_get_contents($path);
        expectFailure(fn () => $installer->stage(), 'Invalid native release ownership');
        check(file_get_contents($path) === $before, 'Refused identity changed ownership');
    });
}
foreach (['modified', 'missing', 'extra'] as $change) {
    test('native source preservation requires the complete exact owned graph: ' . $change, function () use ($change): void {
        [$root, $installer, $path] = nativeFixture('graph-' . $change);
        $base = $root . '/plugins/SmartExpiry';
        if ($change === 'modified') { file_put_contents($base . '/Plugin.php', '// changed', FILE_APPEND); }
        elseif ($change === 'missing') { unlink($base . '/Plugin.php'); }
        else { file_put_contents($base . '/operator-extra.txt', 'unowned extra'); }
        $before = file_get_contents($path);
        expectFailure(fn () => $installer->stage(), 'modified or missing files');
        check(file_get_contents($path) === $before, 'Rejected native tree changed ownership');
    });
}

function officialMap(string $archive, string $wrapper): array {
    $zip = new ZipArchive(); check($zip->open($archive) === true, 'Reviewed official ZIP unavailable');
    $files = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if (str_ends_with($name, '/')) { continue; }
        check(str_starts_with($name, $wrapper . '/'), 'Unexpected official ZIP wrapper');
        $files[substr($name, strlen($wrapper) + 1)] = hash('sha256', $zip->getFromIndex($index));
    }
    $zip->close(); ksort($files); return $files;
}
foreach ([
    'smart_expiry' => ['SmartExpiry-1.2.0-full.zip', 'SmartExpiry', '1.2.0', 'b47e8cf11bb55684d820207888cf30bfb6b908010973a1612f70af42dbed8ea3'],
    'subinfo_customizer' => ['SubInfoCustomizer-v1.0.1.zip', 'SubInfoCustomizer', '1.0.1', 'fb9ebfeafe478009934fc68162c1258f4b40a436b350d222bd5f4c4bb8b53f84'],
] as $code => [$archive, $wrapper, $version, $sha]) {
    test('reviewed same-version native ZIP recognizes only complete trusted panel runtime closure: ' . $code, function () use ($code, $archive, $wrapper, $version, $sha): void {
        global $sourceRoot;
        $root = fixtureRoot('native-runtime-' . $code); bootFixture($root);
        $manifest = json_decode(file_get_contents($root . '/bundled-plugins/manifest.json'), true);
        $item = array_values(array_filter($manifest['plugins'], fn ($item) => $item['code'] === $code))[0];
        $actual = $item['files'];
        $archivePath = dirname($sourceRoot) . '/release-work/online-updates-20261031/independent-plugin-source/official-assets/' . $archive;
        check(hash_file('sha256', $archivePath) === $sha, 'Reviewed artifact hash changed');
        $official = officialMap($archivePath, $wrapper);
        check(count($official) > count($actual), 'Fixture must cover documentation/development-only native extras');
        $matches = fn (array $live, array $release = [], ?string $digest = null) => BundledPluginInstaller::matchesApprovedRuntimeSource($code, $version, $live, $release ?: $official, $digest ?? $sha);
        check($matches($actual), 'Complete trusted runtime hotfix was treated as an old native release');
        check(!$matches($actual, [], str_repeat('d', 64)), 'Unreviewed replacement archive was silently masked');
        $extra = $actual + ['unknown.php' => hash('sha256', 'unknown')];
        check(!$matches($extra), 'Unknown live extras were ignored');
        $missing = $actual; unset($missing['Plugin.php']);
        check(!$matches($missing), 'Incomplete runtime graph was accepted');
        $modified = $actual; $modified['Plugin.php'] = str_repeat('d', 64);
        check(!$matches($modified), 'Unreviewed runtime modification was accepted');
        $changed = $official; $changed['Plugin.php'] = str_repeat('d', 64);
        check(!$matches($actual, $changed), 'Official runtime member mutation was hidden by subset compatibility');
        check(BundledPluginInstaller::matchesApprovedRuntimeSource($code, $version, $official, $official), 'Exact full native graph was not current');
    });
}
test('full native approved overlays are complete and version-scoped', function (): void {
    $official = ['config.json' => str_repeat('a', 64), 'Plugin.php' => '2d73596c562a7c4bcb5bbae0229aff75918b3626d950e5089296389b4b65e8ad',
        'Services/AdminBundlePatcher.php' => '42f4b1e41f936bde1e340e629c4ca5e6e43d9cf457db1792077362a34f82a4c9'];
    $actual = $official; $actual['Plugin.php'] = 'ab99c90d1b259bd9bd0a8fd10105362cf7f49ca1b134fcc13c96064a480cb545';
    check(!BundledPluginInstaller::matchesApprovedRuntimeSource('qrcodeextend', '1.2.0', $actual, $official), 'Partial hotfix was accepted');
    $actual['Services/AdminBundlePatcher.php'] = '9ae7ed11f431992fb11e83671ab8da731438e1f75985a7b6ae07f77f3afe235e';
    check(BundledPluginInstaller::matchesApprovedRuntimeSource('qrcodeextend', '1.2.0', $actual, $official), 'Full approved native overlay was rejected');
    check(!BundledPluginInstaller::matchesApprovedRuntimeSource('qrcodeextend', '1.3.1', $actual, $official), 'Historical hotfix policy escaped its version boundary');
});
echo "PASS {$passed} native ownership/source checks\n";
