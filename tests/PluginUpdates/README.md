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
php tests/BundledPlugins/same-version-snapshots.php
php tests/BundledPlugins/integration.php
```

Set `XBOARD_TEST_VENDOR` when using a shared Composer autoloader. Historical integration fixtures additionally accept `NODE_ENDPOINT_REFERENCE` and `SUBINFO_REFERENCE` pointing to the corresponding local Git repositories.
