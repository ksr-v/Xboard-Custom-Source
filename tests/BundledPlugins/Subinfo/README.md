# Bundled SubInfo HTTP regression

These tests use a disposable Xboard application and database. Never run the
fixture seeder or lifecycle checks on a production database. Install the tested
release through its native installer first: all five bundled feature plugins
must already be installed and enabled. The HTTP suite exercises that bundled
installation path; it does not upload or install the SubInfo ZIP for the first time.

Place this directory outside the site's public document root. Run as the
application user, using the same PHP version and extensions as PHP-FPM:

```bash
php fixtures.php /path/to/disposable-app seed /private/test-fixtures.json
python3 http-bundled.py \
  --base http://127.0.0.1:18089 \
  --app /path/to/disposable-app \
  --php /path/to/site-php-cli \
  --fixtures /private/test-fixtures.json \
  --output /private/http-bundled.json
php backend_checks.php /path/to/disposable-app
```

The fixture file contains generated test credentials and must remain private
(the seeder creates it with mode `0600`). Python requires PyYAML for independent
Clash parsing. The default request interval preserves the plugin API's real rate
limit. The CLI wrapper may enable the installer-required PHP functions for that
process; do not change the global FPM security configuration for these tests.

The HTTP suite checks 0–4 rows, all nine variables and date formats, persistence,
validation, non-admin access, subscription formats, real-node preservation,
disable/enable/upgrade, repeated bundle synchronization with a disabled fifth
plugin, and preservation of the other four plugins, tables, Admin assets, users,
nodes and machine identities. The simulated database-version downgrade invokes
the native upgrade lifecycle; verified historical v1.0.0 source adoption is
tested separately by `../integration.php` and `../subinfo-upgrade.php`.

The suite leaves SubInfo enabled with default templates for further checks.
It does not start a proxy kernel, NIC collector or browser, and does not establish
Docker, external WSS, OPcache-enabled FPM or client-import end-to-end coverage.
