<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Services\Plugin\BundledPluginInstaller;

// The reviewed original native artifact is independent of the new manifest's
// hashes. Override its location only; its pinned digest is never configurable.
const QR131_ARCHIVE_SHA256 = '7da6c72eb7cfe60ac7b255007735608520acd20d96f946b8bbef44c784281f0b';
$archive = getenv('XBOARD_QR131_ARCHIVE') ?: dirname($sourceRoot)
    . '/release-work/online-updates-20261031/qrcode-131-recovery/qrcodeextend-1.3.1.zip';
check(is_file($archive) && hash_file('sha256', $archive) === QR131_ARCHIVE_SHA256,
    'Set XBOARD_QR131_ARCHIVE to the original verified QRCodeExtend 1.3.1 ZIP.');
$zip = new ZipArchive();
check($zip->open($archive) === true, 'Could not open the reviewed QR archive.');
$originalBytes = $originalFiles = [];
try {
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        check(is_string($name) && str_starts_with($name, 'Qrcodeextend/'), 'Unexpected QR ZIP wrapper.');
        $relative = substr($name, strlen('Qrcodeextend/'));
        check(!str_contains($relative, '\\') && !str_contains($relative, ':') && !str_contains($relative, "\0")
            && !preg_match('~(?:^|/)(?:\.{1,2}|\.git|\.env)(?:/|$)~', $relative), 'Unsafe QR ZIP member.');
        if (str_ends_with($name, '/')) { continue; }
        check($relative !== '' && !str_starts_with($relative, '/') && !isset($originalBytes[$relative]),
            'Empty, absolute, or duplicate QR ZIP member.');
        check($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes), 'Missing QR ZIP member metadata.');
        check(in_array(($attributes >> 16) & 0170000, [0, 0100000], true), 'QR ZIP member is not a regular file.');
        $bytes = $zip->getFromIndex($index);
        check(is_string($bytes), 'Could not read QR ZIP member.');
        $originalBytes[$relative] = $bytes;
        $originalFiles[$relative] = hash('sha256', $bytes);
    }
} finally { $zip->close(); }
ksort($originalFiles);
check(count($originalFiles) === 12, 'Expected the complete reviewed native QR 1.3.1 file graph.');
check(json_decode($originalBytes['config.json'], true, 32, JSON_THROW_ON_ERROR)['version'] === '1.3.1',
    'The original native ZIP has an unexpected plugin version.');

$manifest = json_decode(file_get_contents($sourceRoot . '/bundled-plugins/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
$current = array_values(array_filter($manifest['plugins'], static fn (array $item): bool => $item['code'] === 'qrcodeextend'))[0];
check($current['version'] === '1.3.2', 'Expected the current official QR 1.3.2 release.');
$previous = null;
foreach ($current['accepted_previous'] as $item) {
    $files = $item['files']; ksort($files);
    if ($item['version'] === '1.3.1' && $files === $originalFiles) { $previous = $item; }
}
check(is_array($previous), 'The exact original native 1.3.1 graph is missing from accepted_previous.');
check(($previous['source_provenance']['kind'] ?? null) === 'github-release-asset'
    && ($previous['source_provenance']['archive_sha256'] ?? null) === QR131_ARCHIVE_SHA256,
    'Original accepted QR graph lost its verified native artifact provenance.');

function qr131Fixture(string $name): string
{
    global $originalBytes;
    $root = fixtureRoot('qrcode-lockfix-' . $name);
    foreach ($originalBytes as $relative => $bytes) {
        $target = $root . '/plugins/Qrcodeextend/' . $relative;
        if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
        check(file_put_contents($target, $bytes) === strlen($bytes), 'Could not deploy original QR fixture bytes.');
    }
    return $root;
}
function qr131Tree(string $root): array
{
    $directory = $root . '/plugins/Qrcodeextend';
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
        check($entry->isFile() && !$entry->isLink(), 'Unexpected fixture source member.');
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
        $files[$relative] = hash_file('sha256', $entry->getPathname());
    }
    ksort($files); return $files;
}
function qr131Ownership(string $root, array $owned): string
{
    $directory = $root . '/storage/app/private/plugin-bundles';
    if (!is_dir($directory)) { mkdir($directory, 0700, true); }
    $path = $directory . '/ownership.json';
    file_put_contents($path, json_encode(['schema_version' => 1, 'plugins' => ['qrcodeextend' => $owned]], JSON_THROW_ON_ERROR));
    return $path;
}
function qr131OriginalOwnership(): array
{
    global $current, $previous, $originalFiles;
    return ['directory' => 'Qrcodeextend', 'version' => '1.3.1', 'source_commit' => $previous['source_commit'],
        'source_repository' => $current['source_repository'], 'files' => $originalFiles];
}

foreach (['unowned', 'panel-owned', 'native-owned'] as $mode) {
    test('exact original QR 1.3.1 upgrades to official 1.3.2 with notes preserved: ' . $mode,
        static function () use ($mode, $current, $previous, $originalFiles): void {
            $root = qr131Fixture($mode);
            check(qr131Tree($root) === $originalFiles, 'Fixture must begin with the exact original native graph.');
            if ($mode === 'panel-owned') {
                qr131Ownership($root, qr131OriginalOwnership() + ['bundle_version' => '2026.10.31.1']);
            }
            if ($mode === 'native-owned') {
                $owned = qr131OriginalOwnership();
                $owned['source_provenance'] = $previous['source_provenance'];
                $owned['source_provenance']['checksum_source'] = 'SHA256SUMS';
                qr131Ownership($root, $owned);
            }
            $note = 'operator-notes.txt';
            file_put_contents($root . '/plugins/Qrcodeextend/' . $note, 'preserve-operator-notes');
            $installer = new BundledPluginInstaller($root);
            $report = $installer->stage();
            check($report['qrcodeextend']['action'] === 'upgraded-files', 'Original accepted QR graph did not upgrade.');
            $expected = $current['files'] + [$note => hash('sha256', 'preserve-operator-notes')]; ksort($expected);
            check(qr131Tree($root) === $expected, 'Upgrade changed notes or did not install the complete current graph.');
            $ownership = json_decode(file_get_contents($root . '/storage/app/private/plugin-bundles/ownership.json'), true, 64, JSON_THROW_ON_ERROR);
            check($ownership['plugins']['qrcodeextend']['files'] === $current['files'], 'Ownership does not match the complete delivered QR graph.');
            check($installer->stage()['qrcodeextend']['action'] === 'unchanged'
                && qr131Tree($root) === $expected, 'The completed same-version upgrade was not idempotent.');
        });
}

test('complete reviewed local QR 1.3.1 overlay upgrades to official 1.3.2', static function () use ($current, $originalFiles): void {
    global $sourceRoot;
    $root = qr131Fixture('reviewed-local-overlay');
    $local = $originalFiles;
    $local['Plugin.php'] = 'ab99c90d1b259bd9bd0a8fd10105362cf7f49ca1b134fcc13c96064a480cb545';
    $local['Services/AdminBundlePatcher.php'] = '7b8f6f984f52c49e616ec4644baa948db516b13332eee41568fd2d07f1f38380';
    foreach (['Plugin.php', 'Services/AdminBundlePatcher.php'] as $relative) {
        $source = $sourceRoot . '/bundled-plugins/Qrcodeextend/' . $relative;
        check(hash_file('sha256', $source) === $local[$relative], 'Reviewed local overlay PHP fixture changed.');
        check(copy($source, $root . '/plugins/Qrcodeextend/' . $relative), 'Could not deploy local overlay fixture.');
    }
    $accepted = false;
    foreach ($current['accepted_previous'] as $previous) {
        $files = $previous['files']; ksort($files);
        if ($previous['version'] === '1.3.1' && $files === $local) { $accepted = true; }
    }
    check($accepted && qr131Tree($root) === $local, 'Complete reviewed local 1.3.1 graph is not retained.');
    $installer = new BundledPluginInstaller($root);
    check($installer->stage()['qrcodeextend']['action'] === 'upgraded-files', 'Reviewed local overlay did not upgrade.');
    check(qr131Tree($root) === $current['files'] && $installer->stage()['qrcodeextend']['action'] === 'unchanged',
        'Reviewed local overlay did not reach the exact idempotent current release.');
});

test('same-version native-owned QR 1.3.2 retains its exact release provenance', static function () use ($current): void {
    $root = fixtureRoot('qrcode-lockfix-current-native');
    copyFixture($root . '/bundled-plugins/Qrcodeextend', $root . '/plugins/Qrcodeextend');
    $owned = ['directory' => 'Qrcodeextend', 'version' => $current['version'], 'source_commit' => $current['source_commit'],
        'source_repository' => $current['source_repository'], 'files' => $current['files'],
        'source_provenance' => $current['source_provenance']];
    // Online ownership records use the updater's primary checksum source.
    $owned['source_provenance']['checksum_source'] = 'SHA256SUMS';
    $path = qr131Ownership($root, $owned);
    $installer = new BundledPluginInstaller($root);
    foreach ([1, 2] as $attempt) {
        $report = $installer->stage()['qrcodeextend'];
        check($report['action'] === 'preserved-newer' && ($report['source'] ?? null) === 'github-release-asset',
            'Panel staging replaced the independent same-version native release.');
        check(qr131Tree($root) === $current['files'], 'Native-owned QR source changed.');
        $ownership = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        check($ownership['plugins']['qrcodeextend'] === $owned, 'Verified native ownership/provenance changed.');
    }
});

echo "PASS {$passed} original QR 1.3.1 to official 1.3.2 upgrade checks\n";
