<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Services\Plugin\BundledPluginInstaller;

function ownedFixture(string $name): array
{
    $root = fixtureRoot('owned-hotfix-' . $name);
    $installer = new BundledPluginInstaller($root);
    $installer->stage();
    return [$root, $installer, json_decode(file_get_contents($root . '/bundled-plugins/manifest.json'), true, 64, JSON_THROW_ON_ERROR)];
}
function fixtureOwnership(string $root, ?array $value = null): array
{
    $path = $root . '/storage/app/private/plugin-bundles/ownership.json';
    if ($value !== null) { file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR)); }
    return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
}
function originalBundleFile(string $relative): string
{
    global $sourceRoot;
    return runFixtureProcess(['git', '-c', 'safe.directory=' . str_replace('\\', '/', $sourceRoot),
        '-C', $sourceRoot, 'show', '01be17d6c1ffbefd820e631660cc4b0d1a121d9c:bundled-plugins/' . $relative]);
}
function preHotfixFiles(array $item): array
{
    if (isset($item['source_provenance']['base_files'])) { return $item['source_provenance']['base_files']; }
    // QR now ships the genuine 1.3.1 native release; its reviewed 1.2.0
    // baseline remains historical evidence rather than current provenance.
    foreach ($item['accepted_previous'] as $previous) {
        if ($previous['version'] === '1.2.0' && !isset($previous['source_provenance'])) { return $previous['files']; }
    }
    throw new RuntimeException('No pre-hotfix fixture graph found.');
}
function deployPartialQrHotfix(string $root, array $item): void
{
    global $sourceRoot;
    foreach (preHotfixFiles($item) as $relative => $hash) {
        $bytes = originalBundleFile('Qrcodeextend/' . $relative);
        check(hash('sha256', $bytes) === $hash, 'Historical QR source changed');
        file_put_contents($root . '/plugins/Qrcodeextend/' . $relative, $bytes);
    }
    $patch = dirname($sourceRoot) . '/panel-changes/runtime-patch-lock-fix/payload/plugins/Qrcodeextend/Plugin.php';
    check(hash_file('sha256', $patch) === 'ab99c90d1b259bd9bd0a8fd10105362cf7f49ca1b134fcc13c96064a480cb545', 'Published QR hotfix fixture changed');
    copy($patch, $root . '/plugins/Qrcodeextend/Plugin.php');
}

test('all three complete trusted current graphs reconcile stale pre-hotfix ownership without source rewrites', function (): void {
    [$root, $installer, $manifest] = ownedFixture('all-current');
    $ownership = fixtureOwnership($root);
    $selected = ['smart_expiry', 'qrcodeextend', 'subinfo_customizer'];
    foreach ($manifest['plugins'] as $item) {
        if (!in_array($item['code'], $selected, true)) { continue; }
        $ownership['plugins'][$item['code']]['files'] = preHotfixFiles($item);
        $ownership['plugins'][$item['code']]['bundle_version'] = '2026.10.30.2';
    }
    fixtureOwnership($root, $ownership);
    file_put_contents($root . '/plugins/SmartExpiry/MANIFEST.sha256', 'unowned ZIP metadata retained');
    $report = $installer->stage();
    $after = fixtureOwnership($root);
    foreach ($manifest['plugins'] as $item) {
        if (!in_array($item['code'], $selected, true)) { continue; }
        check($report[$item['code']]['action'] === 'unchanged', 'Known current hotfix source was rewritten');
        check($after['plugins'][$item['code']]['files'] === $item['files'], 'Ownership did not adopt the complete current graph');
        foreach ($item['files'] as $file => $hash) { check(hash_file('sha256', $root . '/plugins/' . $item['directory'] . '/' . $file) === $hash, 'Known source bytes changed'); }
    }
    check(file_get_contents($root . '/plugins/SmartExpiry/MANIFEST.sha256') === 'unowned ZIP metadata retained', 'Unowned verification metadata was removed');
    check($installer->stage()['smart_expiry']['action'] === 'unchanged', 'Reconciled ownership was not idempotent');
});

test('owned current graph can recognize and upgrade a complete trusted previous same-version graph', function (): void {
    [$root, $installer] = ownedFixture('known-previous');
    file_put_contents($root . '/plugins/SmartExpiry/Services/AdminBridgePatcher.php', originalBundleFile('SmartExpiry/Services/AdminBridgePatcher.php'));
    check($installer->stage()['smart_expiry']['action'] === 'upgraded-files', 'Trusted previous graph was rejected only because ownership was stale');
    check(hash_file('sha256', $root . '/plugins/SmartExpiry/Services/AdminBridgePatcher.php') === 'c483f0dd9dd5224dd3a4ff36817d23e77de774209af71ed1f2f11024ffa6e303', 'Previous graph did not reach the verified current source');
});

test('approved published hotfix on historical SubInfo v1.0.0 upgrades from its unchanged ownership graph', function (): void {
    $root = fixtureRoot('owned-hotfix-subinfo-v1');
    $historical = deployHistoricalSubinfo($root);
    copy($root . '/bundled-plugins/SubinfoCustomizer/Services/CoreBridgePatcher.php', $root . '/plugins/SubinfoCustomizer/Services/CoreBridgePatcher.php');
    mkdir($root . '/storage/app/private/plugin-bundles', 0700, true);
    fixtureOwnership($root, ['schema_version' => 1, 'plugins' => ['subinfo_customizer' => [
        'directory' => 'SubinfoCustomizer', 'version' => '1.0.0', 'source_commit' => $historical['commit'],
        'source_repository' => 'https://github.com/ksr-v/xb-SubInfoCustomizer', 'bundle_version' => '2026.10.29.1',
        'files' => $historical['files'],
    ]]]);
    $report = (new BundledPluginInstaller($root))->stage();
    check($report['subinfo_customizer']['action'] === 'upgraded-files', 'Approved historical hotfix graph was not recognized');
    check(json_decode(file_get_contents($root . '/plugins/SubinfoCustomizer/config.json'), true)['version'] === '1.0.1', 'Historical hotfix graph was not upgraded');
});

test('a known hotfix plus an unrelated modified source file is rejected before staging', function (): void {
    [$root, $installer, $manifest] = ownedFixture('unknown-change');
    $item = array_values(array_filter($manifest['plugins'], fn ($item) => $item['code'] === 'smart_expiry'))[0];
    $ownership = fixtureOwnership($root);
    $ownership['plugins']['smart_expiry']['files'] = $item['source_provenance']['base_files'];
    fixtureOwnership($root, $ownership);
    $plugin = $root . '/plugins/SmartExpiry/Plugin.php';
    file_put_contents($plugin, '// unreviewed operator source change', FILE_APPEND);
    $before = hash_file('sha256', $root . '/storage/app/private/plugin-bundles/ownership.json');
    expectFailure(fn () => $installer->stage(), 'modified files');
    check(hash_file('sha256', $root . '/storage/app/private/plugin-bundles/ownership.json') === $before, 'Rejected source changed the ownership record');
    check(str_ends_with(file_get_contents($plugin), '// unreviewed operator source change'), 'Unreviewed source was overwritten');
});

test('partial QR hotfix mixing old and patched target files is not an approved graph', function (): void {
    [$root, $installer, $manifest] = ownedFixture('partial-qr');
    $item = array_values(array_filter($manifest['plugins'], fn ($item) => $item['code'] === 'qrcodeextend'))[0];
    $ownership = fixtureOwnership($root);
    $ownership['plugins']['qrcodeextend']['files'] = preHotfixFiles($item);
    fixtureOwnership($root, $ownership);
    deployPartialQrHotfix($root, $item);
    expectFailure(fn () => $installer->stage(), 'modified files');
});

test('unapproved CRLF rewriting is not silently normalized while reconciling ownership', function (): void {
    [$root, $installer, $manifest] = ownedFixture('crlf');
    $item = array_values(array_filter($manifest['plugins'], fn ($item) => $item['code'] === 'smart_expiry'))[0];
    $ownership = fixtureOwnership($root);
    $ownership['plugins']['smart_expiry']['files'] = $item['source_provenance']['base_files'];
    fixtureOwnership($root, $ownership);
    $path = $root . '/plugins/SmartExpiry/Services/AdminBridgePatcher.php';
    file_put_contents($path, str_replace("\n", "\r\n", file_get_contents($path)));
    expectFailure(fn () => $installer->stage(), 'modified files');
});
echo "PASS {$passed} owned hotfix upgrade checks\n";
