# aaPanel + Docker: bundled Xboard and the private node entry

Existing panels: use the [v2026.10.25.1 update guide](../../installation/upgrade-2026.10.25.zh-CN.md) (Chinese) for preserved volumes and the matching Node upgrade. Do not rerun the fresh installer for a cross-version update.

This guide targets `v2026.10.25.1`, including NodeEndpoint 1.0.8, SmartExpiry 1.2.0, audited QRCode Extend 1.2.0 and FlowScope 2.4.0. See the [Chinese guide](../../installation/aapanel-docker.zh-CN.md). Run the commands in the **host Root SSH session**.

## 1. Prepare the website

Prepare aaPanel Nginx, Docker Engine with the Compose plugin, Git, Python 3.10+, curl, OpenSSL, `ss` and `runuser`. Host PHP and Redis are unnecessary. The installer does not install system software. Check Docker against the [official installation instructions](https://docs.docker.com/engine/install/).

Create a new pure-static aaPanel website and configure its ordinary domain HTTPS certificate. Before running the installer, configure its existing root location as a reverse proxy:

```nginx
location / {
    proxy_pass http://127.0.0.1:7001;
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 60s;
    proxy_buffering off;
}
```

Replace the existing root location; do not add a duplicate. This is a reverse proxy, not Laravel URL rewrite. Check/reload Nginx. A 502 before the container starts is temporary. The fresh installer requires exactly one site matching this backend and never edits that original site.

## 2. Install into an empty directory

Replace the directory below with the actual new website directory. Preserve existing sites and data.

```bash
cd /www/wwwroot/panel.example.com
git clone --branch v2026.10.25.1 --single-branch \
  https://github.com/ksr-v/Xboard-Custom-Source.git .
bash docker-install.sh
```

Enter the actual node communication address and administrator email, review the combined plan, then enter `YES` once. The IPv4 suggestion can be accepted or overridden; a failed lookup requires manual input. For NAT or multiple addresses, use the address reachable by the nodes.

The default is an existing aaPanel MySQL/MariaDB database with embedded Redis. Prepare an empty database and a dedicated account in aaPanel; the installer asks for its reachable host, port, database, user and hidden password. It prepares permissions, builds the independent source, installs Xboard and four enabled plugins as container `www`, then configures host HTTPS on port 8443, encrypted storage of a random CA passphrase, public CA import and the local firewall rule. The HTTP backend binds only `127.0.0.1:7001`.

The tool prints a Root-private log directory. Read its `application-install.out` locally as Root to obtain the initial administrator password and dashboard path. Preserve these securely; do not publish the logs. Log in through the website domain and verify `/node-endpoint`.

To use SQLite instead:

```bash
bash docker-install.sh --database sqlite
```

For the default MySQL mode, use a dedicated account and an address reachable from the container; container `127.0.0.1` is not the host. The tool does not install MySQL, create accounts or wipe an existing database. Existing installations keep their database.

The fresh installer only accepts a new directory or its own same-source retry. It creates an owned `compose.node-endpoint.yaml`; retain its project name and configuration. **Do not use this fresh installer for an existing original Docker panel.**

## 3. Add the private entry to an existing standard Docker panel

Back up the database, original `.env` / `APP_KEY`, `storage/app`, `.docker/.data`, plugins and all runtime volumes. Install/upgrade and enable the current NodeEndpoint plugin first.

Supported automatic layouts use a local bridged Compose `xboard` service, image application `/www`, a non-root application user and persistent `.env`, `storage/app`, `.docker/.data` and `plugins`. A full application bind is unnecessary. Publish the backend only on loopback and configure the host Nginx proxy with WebSocket headers.

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.9/install.sh | bash -s -- --docker
```

Review the detected container/project and confirm. Add `--app-dir /actual/compose/project` to select among multiple projects. Without a native FPM site the ordinary Root wizard also checks standard Docker, while `--docker` selects it explicitly.

The tool configures HTTPS and saves the matching panel settings through a non-root PHP pipe. Web processes receive neither Docker socket access nor sudo; the Root CA private key remains outside the container. For an address change, save the plugin settings and copy its new Root command, or pass the saved address explicitly with `--host`. Changing website settings alone does not renew the server certificate or host listener.

## 4. Version updates and container replacement

Retain the database, original `APP_KEY`, runtime volumes, known-good image and host Root state. Never reinstall, delete `.env` or remove data volumes during an upgrade.

**Older installation without a `storage/app` mount:** stop and retain the old container, copy its `/www/storage/app/.` into a new protected host directory, verify the public CA and plugin records, and back up that directory. Only then add the populated mount and recreate. A new empty mount hides existing container-layer files. Do not remove the old container before exporting its data. This is distinct from a fresh-install recreation test.

After replacing a container, rerun the `--docker` command and explicitly confirm the reviewed plan. The tool verifies the existing credential against the original CA private key before updating the pinned container ID, preserving CA trust and passphrase. If the CA key was moved offline, provide the original encrypted file using `--ca-key /root-private/ca.key.pem`; never upload it or mount it into the panel.

Obtain the intended source revision and rebuild the local image using the original Compose configuration/project and existing volumes. `docker compose pull` alone does not rebuild local source. `docker-install.sh` is for fresh installation and same-source retry, not arbitrary version upgrades. Its owned file is `compose.node-endpoint.yaml`; using a directory's default project name can create a second project. Restore a matching verified image/source and database/configuration/runtime backup when rolling back; changing an image tag does not undo database migrations.

## Scheduled tasks and support scope

Octane provides periodic scheduling. Check that the dashboard's scheduled-task status updates automatically; do not add a duplicate cron. Only configure one external non-root `schedule:run` after diagnosing that automatic scheduling is not running.

The host firewall step does not configure cloud security groups or provider firewalls. New nodes use the native machine management's IP + private certificate command and its public CA; existing business nodes require the configuration-preserving migration workflow.

The automatic first version excludes host networking, split services, remote Docker, arbitrary service names and publicly exposed HTTP backends. Native installations use the [PHP-FPM guide](./aapanel.md). The [earlier Docker/manual-entry report](../../installation/aapanel-docker-validation.zh-CN.md) remains historical evidence, not proof of the new automatic flow. Consult the new Release validation record for its actual tested scope.
