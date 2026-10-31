# Plugin online update UI regression

`ui.cjs` runs the distributed Admin JavaScript and CSS in Chrome with a synthetic loopback API. It does not access production accounts, download releases, or update installed plugins.

Provide `playwright-core` 1.58.2 and Chrome, then run:

```sh
node tests/PluginUpdates/ui.cjs
```

If the dependency is installed elsewhere, set `PLAYWRIGHT_MODULE` to its absolute module directory. The test writes its screenshot and result under `work/`.

Backend tests use the existing Composer fixture environment:

```sh
php tests/BundledPlugins/online_update.php
php tests/PluginUpdates/official-release-source.php
php tests/BundledPlugins/same-version-snapshots.php
php tests/BundledPlugins/integration.php
```

Set `XBOARD_TEST_VENDOR` when using a shared Composer autoloader. Historical integration fixtures additionally accept `NODE_ENDPOINT_REFERENCE` and `SUBINFO_REFERENCE` pointing to the corresponding local Git repositories.

`official-release-source.php` is standalone and needs PHP ZIP support but no Laravel boot, database, network, or published assets. Its synthetic ZIP/API fixtures cover the five fixed native repositories, stable plugin release selection, real tag-commit provenance, SHA/size verification, unsafe archive contents, fixed package staging, and failure cleanup. The seven core sources remain with the checksummed panel archive.

`online_update.php` uses fake HTTP and fresh real PHP child processes. It covers independent QR 1.3.1 discovery over an older panel snapshot, per-source failure isolation, short check caching, fresh update metadata, one core snapshot per CLI operation, native ownership, enabled/config preservation, and rollback. Dedicated lifecycle/private-data tests and `tests/BundledPlugins/native-ownership.php` cover the QR private-data guard and closed approved runtime overlay graphs.
