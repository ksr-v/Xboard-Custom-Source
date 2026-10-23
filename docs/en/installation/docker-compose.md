# Deploy the independent Xboard with Docker Compose

For aaPanel and the private node HTTPS entry, follow the [current aaPanel + Docker guide](./aapanel-docker.md) ([Chinese](../../installation/aapanel-docker.zh-CN.md)). It provides the `docker-install.sh` fresh installer, a single reviewed confirmation, and the Root `--docker` workflow for existing standard panels.

The independent image includes NodeEndpoint 1.0.7, SmartExpiry 1.2.0, audited QRCode Extend 1.2.0 and FlowScope 2.4.0. It is built from this repository; original-maintainer images or submodules are unnecessary.

## Choose the deployment path

| Situation | Path |
| --- | --- |
| New empty aaPanel website, SQLite or a prepared MySQL database | Configure the loopback Nginx proxy, clone fixed `v2026.10.23.1`, run `bash docker-install.sh` |
| Existing standard bridged `xboard` Compose service | Preserve data, upgrade/enable NodeEndpoint, run the host Root `--docker` command |
| Existing original panel without fresh-installer ownership | Keep its current installation; do not run the fresh wrapper |
| Native PHP-FPM | Use the [native guide](./aapanel.md) |
| Host networking, split roles or remote Docker | Advanced manual deployment; excluded from automatic Root support |

The repository retains four Compose samples for advanced deployment. The fresh automatic installer instead generates its own owned `compose.node-endpoint.yaml`. Do not replace its recorded project with a sample, another Compose file or a guessed project name.

## Data and updates

Preserve the database, original `.env` / `APP_KEY`, `storage/app`, `.docker/.data`, plugins and every runtime volume. `storage/app` includes the public CA and plugin ownership records. Do not mount an empty directory over older container-layer data: stop/retain the old container, export and verify its storage first, and only then recreate with the populated mount.

Build the intended independent source and replace the service using the original Compose project/configuration and retained data. A local image is not updated by `docker compose pull` alone. The container entrypoint runs application updates as `www` and stops on failure. The fresh wrapper permits its own same-source retry; it does not replace an existing panel or upgrade arbitrary source revisions.

After a container ID changes, rerun Root `--docker` and approve the new reviewed context. Existing credentials must match the original CA key before rebinding; a new container name alone is insufficient. Rollback requires a verified matching image/source and database/configuration/runtime backup, not merely an older image tag.

Use the [complete guide](./aapanel-docker.md) for commands, reverse-proxy configuration, administrator log location, MySQL input, firewall scope, scheduling and support limits. Actual automatic-flow verification is recorded with the corresponding Release.
