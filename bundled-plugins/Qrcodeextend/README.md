# QRCode Extend

Adds a locally generated subscription QR-code action to Xboard's Admin user menu. Version 1.2.0 follows Xboard's native lifecycle and renders the final QR as a PNG image so mobile users can long-press it to save.

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

The injected block is bounded by exact `qrcodeextend:start:v3` and `qrcodeextend:end:v3` markers. Apply and removal run under the shared `storage/framework/xboard-admin-patch.lock` exclusive lock for their complete read → validate → transform → atomic-write → verify transaction.

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

## Data handling

The action uses the selected row's existing `subscribe_url`, applies the frontend-compatible `types` parameter, and renders the QR code locally with the vendored library. It does not persist subscription data or send it to a third party.
