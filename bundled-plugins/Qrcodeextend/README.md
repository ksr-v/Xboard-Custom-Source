# QRCode Extend

Adds a locally generated subscription QR-code action to Xboard's Admin user menu. Version 1.3.2 avoids waiting on the shared patch lock when the complete installed bridge is already valid, and skips copying identical published assets. Missing or changed assets are repaired. Version 1.3.1 introduced the visible, touch-friendly PNG save button; those asset bytes and their existing cache version remain unchanged.

## Lifecycle

- `install()` and `boot()` call `ensureIntegration()`; repeated calls are idempotent.
- `cleanup()` calls `removeOwnedChanges()` and deletes QRCode Extend's published/private files. Xboard invokes cleanup on disable and before uninstall, so no custom `uninstall()` lifecycle is required.
- `update()` re-establishes the current owned bridge.
- Re-enabling the plugin recreates the bridge.

The bridge lazily loads `/plugins/qrcodeextend/qrcodeextend.js` and `.css` when the QR action is selected. Consequently, QRCode Extend does not modify `resources/views/admin.blade.php`, `AbstractPlugin`, `PluginManager`, or the Console Kernel.

## Core-integration audit

Earlier releases changed four upstream areas:

- `AbstractPlugin.php` and `PluginManager.php` added a custom `uninstall()` hook so the full Admin backup could be restored before deletion.
- `PluginManager.php` also removed published assets and registered commands belonging to disabled plugins.
- `Console/Kernel.php` invoked that extra command registration path.
- `admin.blade.php` scanned every enabled plugin for Admin JS/CSS assets.

Those changes served safe restore, operator commands, asset cleanup, and Admin asset loading, but none is QR business logic. Version 1.1.0 removes all four core changes and the complete `CoreOverlayDeployer`/`resources/overlay` mechanism. Native `disable() → cleanup()` provides the required reversible lifecycle; the remaining bundle bridge owns its loader.

## Ownership and coexistence

The injected block is bounded by exact `qrcodeextend:start:v5` and `qrcodeextend:end:v5` markers. Exact owned v3 and v4 blocks are upgraded without touching foreign bytes. A fully validated installed v5 bridge returns without acquiring the shared lock or writing files. Every other patch state, including a failed optimistic read, is reread under the shared `storage/framework/xboard-admin-patch.lock` exclusive lock before deciding whether to modify or reject it. Installation, upgrades, status, and removal retain their locked validation and mutation behavior.

Rules:

- zero markers means not installed;
- one complete, byte-exact block means installed;
- partial, duplicate, moved, or edited markers fail closed;
- exactly one known Admin action anchor is required;
- removal deletes only the exact owned block and never restores the entire bundle from backup;
- content before and after the owned block—including SmartExpiry markers and transforms—is preserved byte-for-byte.

The supported build is detected through `public/assets/admin/manifest.json`, which must select `assets/index-CEIYH7i8.js`. The unique anchor is then checked in the current bundle. A clean full-file SHA is deliberately not required because other plugins may have already made legitimate changes.

## Commands

While the plugin is enabled:

```text
php artisan qrcodeextend:status
php artisan qrcodeextend:patch
php artisan qrcodeextend:restore
```

`restore` is retained as an operator-friendly name but removes only QRCode Extend's owned fragment. Backups under `storage/qrcodeextend/backups/`, if present from older releases, are available for diagnostics and manual disaster recovery until the next disable/uninstall cleanup removes QRCode Extend's private storage.

## Compatibility and limitations

- SmartExpiry-first and QRCodeExtend-first transformations are covered by unit fixtures; QRCode Extend cleanup preserves SmartExpiry content.
- The Admin React source is not published with Xboard, so one minimal bundle bridge remains necessary.
- A new Admin build with a different manifest entry or action anchor is rejected until reviewed.
- Legacy unmarked QRCode Extend injections fail closed and require a deliberate migration/removal; they are never guessed at or fuzzily deleted.
- QRCode Extend removes `public/plugins/qrcodeextend/` and `storage/qrcodeextend/` itself during cleanup, then republishes assets from its package on enable. The shared `storage/framework/xboard-admin-patch.lock` is deliberately retained because it belongs to every Admin patching plugin and contains no plugin data.
- Shared locking, build detection, bridge diagnostics, Admin extension points, asset loading, and optional command registration are general runtime capabilities that should eventually live in an `xb-extension-runtime`, not in QR business logic.

## Validation

The repository's focused PHPUnit suite uses `tests/bootstrap.php`. The standalone native lifecycle check requires a local Xboard-Custom-Source checkout through `QRCODE_TEST_PANEL` and its Composer autoloader through `XBOARD_TEST_VENDOR`; run `tests/NativeLifecycleTest.php` in separate PHP processes with `smart-first` and `qr-first`. It uses copied assets and a new SQLite database, without contacting a live site. `node tests/QrcodeImageOutputTest.js` checks the existing PNG/mobile-save implementation.

Build the runtime archive with `python scripts/package.py --timestamp 2026-11-07T00:00:00Z`. It emits the ZIP, a file hash manifest, and `dist/SHA256SUMS`, checks the explicit plugin root and Unix permissions, and excludes repository tests and tooling.

## Data handling

The action uses the selected row's existing `subscribe_url`, applies the frontend-compatible `types` parameter, and renders the QR code locally with the vendored library. It does not persist subscription data or send it to a third party.
