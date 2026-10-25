# Xboard Deployment Guide for aaPanel Environment

This guide keeps the aaPanel PHP-FPM deployment flow for the independent **v2026.10.25.2** release. For Chinese instructions, see [宝塔 / aaPanel 安装教程](../../installation/aapanel.zh-CN.md). This release includes NodeEndpoint 1.0.9, SmartExpiry 1.2.0, QRCode Extend **1.2.0 (audited copy)**, and FlowScope 2.4.0. A fresh installation installs and enables all four plugins.

Pair this backend with independent Node `v1.13-orphan.6` for automatic Hysteria2 UDP range forwarding and the `node` management menu. Update the backend first, then each node with `upgrade`; keep existing identities, credentials and CA trust. Plugins do not need to be uninstalled. See the [operation notes](../../operations/node-management-hy2.zh-CN.md) and the [matching update guide](../../installation/upgrade-2026.10.25.2.zh-CN.md) (Chinese).

## Table of Contents

1. [Requirements](#requirements)
2. [Quick Deployment](#quick-deployment)
3. [Detailed Configuration](#detailed-configuration)
4. [Maintenance Guide](#maintenance-guide)
5. [Troubleshooting](#troubleshooting)
6. [Enable WebSocket Real-time Sync (Optional)](#enable-websocket-real-time-sync-optional)

## Requirements

### Hardware Requirements

- CPU: 1 core or above
- Memory: 2GB or above
- Storage: 10GB+ available space, with additional space for backups

### Software Requirements

- A Linux server with aaPanel and Root SSH access
- Nginx / OpenResty and PHP **8.3**, using PHP-FPM for the website
- A running Redis service and a prepared MySQL / MariaDB database and account, or another database supported by Xboard
- Python **3.10+**, Git, OpenSSL, `ss`, and `runuser`
- A trusted **Composer 2.2 or newer (2.x)** PHP script / PHAR; this guide pins **2.10.3**
- The PHP extensions required by the pinned `composer.lock`, enabled for both PHP 8.3 CLI and FPM

The native installer does not install aaPanel, upgrade system software or Composer, or create database accounts. Its validation uses an isolated PHP-FPM / SQLite site; complete MySQL / PostgreSQL and standalone Octane deployments are outside that record. Docker has a separate [installation guide](aapanel-docker.md) and validation record. See the [native validation record](../../installation/bundled-validation.zh-CN.md).

## Quick Deployment

### 1. Install aaPanel

Install aaPanel using the instructions on the [official aaPanel website](https://www.aapanel.com/), then sign in to its dashboard. Skip this step if aaPanel is already installed.

### 2. Basic Environment Setup

#### 2.1 Install LNMP Environment

In the aaPanel dashboard, install and start:

- Nginx / OpenResty
- PHP **8.3**, including its PHP-FPM service
- Redis
- MySQL / MariaDB if using a MySQL database

Confirm that `python3 --version` is 3.10 or newer. The locked dependencies require Composer's runtime API `^2.2`; Composer 2.0 / 2.1 are too old. To avoid selecting an older aaPanel or system Composer, prepare the fixed **Composer 2.10.3** PHAR as Root using the [official Composer download](https://getcomposer.org/download/):

```bash
install -d -o root -g root -m 755 /opt/xboard-tools
curl -fsSL --proto '=https' --tlsv1.2 \
  https://getcomposer.org/download/2.10.3/composer.phar \
  -o /opt/xboard-tools/composer-2.10.3.phar
printf '%s\n' '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6  /opt/xboard-tools/composer-2.10.3.phar' | sha256sum -c -
chown root:root /opt/xboard-tools/composer-2.10.3.phar
chmod 0644 /opt/xboard-tools/composer-2.10.3.phar
```

Proceed only if the SHA-256 check reports `OK`. This downloads a fixed PHAR without executing a remote installer or replacing the system Composer. The commands below explicitly select this file. The installer runs Composer and application commands as the website user. Do not run `composer install` separately as Root, run `composer update`, or bypass checks with `--ignore-platform-reqs`.

#### 2.2 Install PHP Extensions

Enable the extensions required by the pinned dependencies. These include `redis`, `fileinfo`, `mbstring`, `curl`, `dom` / XML, `openssl`, `pcntl`, and `posix`, in addition to PHP's standard extensions and the PDO driver for your database, such as `pdo_mysql`. Ensure Composer can extract distribution archives, using `unzip` or PHP's ZIP extension.

Use the same PHP 8.3 installation for CLI and FPM. Composer checks the complete platform requirements during installation; resolve any missing extension before proceeding. Swoole and Event are not required for this PHP-FPM route.

#### 2.3 Enable Required PHP Functions

Check PHP 8.3's disabled-function settings in aaPanel. Application installation and workers need functions such as `putenv`, `proc_open`, `pcntl_alarm`, `pcntl_signal`, and **`symlink`**. Laravel uses `symlink` for the public storage link; enabling `exec` is not required for that step when `symlink` is available. Apply changes to the relevant CLI configuration and restart PHP-FPM when required. Do not give the website user sudo access.

Before running Composer or initializing the database, the new fresh-install flow checks that the selected PHP CLI provides `symlink`. If this check stops installation, correct the relevant PHP CLI settings and retry; the installer does not edit `php.ini` or enable `exec` for you.

### 3. Site Configuration

#### 3.1 Create Website

1. Open **aaPanel > Website > Add site**.
2. Enter your panel domain, select **PHP 8.3**, and create a database and account if using MySQL.
3. Use a dedicated **new, empty website** directory, for example `/www/wwwroot/your-domain`. Do not reuse an existing business site or database.
4. In aaPanel's File Manager, confirm that this new directory contains only the generated placeholders. Select and remove only those placeholders, including any hidden files, so Git can clone into an empty directory. If aaPanel protects its generated `.user.ini`, release it through that new site's aaPanel settings before removing it. Do not delete an existing site's files or configuration.

#### 3.2 Deploy Xboard

As Root, clone the fixed independent release into the empty site directory:

```bash
cd /www/wwwroot/your-domain
git clone --branch v2026.10.25.2 --single-branch \
  https://github.com/ksr-v/Xboard-Custom-Source.git .
```

This intentionally checks out a fixed release tag. Do not initialize or update submodules from the original author's repository. **Complete section 3.3 before running `init.sh`**: the Root installer must discover this site's actual running Nginx and PHP-FPM configuration.

Alternatively, download the fixed `xboard.tar.gz` release attachment and `SHA256SUMS`, independently verify the digest, and extract it into the empty site directory as described in the [native installation guide](../../installation/bundled-native.zh-CN.md). Continue with section 3.3 in either case.

#### 3.3 Configure Site

In the new site's aaPanel settings:

1. Set the running directory to **`/public`**.
2. Select **PHP 8.3** and retain aaPanel's matching PHP-FPM configuration.
3. Add Laravel rewrite rules. A minimal front-controller rule is:

   ```nginx
   location / {
       try_files $uri $uri/ /index.php$is_args$query_string;
   }
   ```

4. Save the site configuration, check the Nginx configuration, and start / reload Nginx and PHP 8.3 FPM. The application may not respond successfully until dependencies and the database are installed; the Nginx site and FPM backend must already be running.

Now run the installer from the site directory as Root. Replace the directory with your actual path. aaPanel normally uses the `www` website user; verify it before running the command.

```bash
cd /www/wwwroot/your-domain
bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

For the first endpoint configuration, choose how to supply the communication address:

```text
1. Automatically detect this server's public IPv4 (default; press Enter)
2. Manually enter an IP / domain
```

Option `1` detects this panel server's public IPv4 and displays it as a candidate. Press Enter to accept it or enter another actual communication IP / domain. If detection fails, enter the address manually. Option `2` asks for the address without contacting the public-IP detection service. The command deliberately omits `--host`; documentation addresses such as `203.0.113.10` cannot be used for real communication. An existing real Root endpoint or an explicit `--host` skips this menu and is not overwritten by detection. Keep the explicit `--composer` path to the verified PHAR prepared in section 2.1.

The Root entry prepares the application and Admin patch directories, then runs Composer, Xboard installation, database migrations, and all four plugin lifecycles as `www`. Follow the database, Redis, and administrator prompts and save the displayed initial login information. You do not need to upload these plugins separately.

For NodeEndpoint, the same flow configures the private IP HTTPS entry, the local TCP firewall rule, and automatic public CA import. Review the displayed plan, then type the **entire confirmation phrase** shown by the installer. Its format is:

```text
INSTALL <actual-communication-address>:<port>
```

This shows the response format; do not enter the angle brackets or placeholder text literally. Copy the exact phrase requested by your local installer. It includes the space after `INSTALL`, the actual address, and the port; typing only `INSTALL` does not confirm the plan. The new interactive flow explains mismatched input and asks again. Enter `CANCEL`, send EOF, or press Ctrl+C to cancel; an automated confirmation must still match the complete phrase exactly.

For a new CA, the plugin generates a random passphrase and saves it encrypted with the panel's original `APP_KEY` before issuing certificates. No passphrase or repeat prompt is needed. An administrator can explicitly reveal or copy it in the certificate section of the HTTPS `/node-endpoint` page. Normal settings and node installation commands do not include it.

Back up the database together with the original `.env` / `APP_KEY`, and complete the encrypted CA export and offline verification described in the NodeEndpoint tools. Private keys stay in the external Root-only directory or offline storage. Existing CAs retain their certificates and passwords; older unmanaged CAs still require their original passphrase during maintenance.

Root tools and PKI stay outside the application directory. After a successful installation, refresh the plugin page; do not download and rerun a separate Root installer or rerun `init.sh` on this installed site. Normal maintenance does not recreate the CA or certificates.

For a later communication IP change, save the new address on the NodeEndpoint settings page, then click “一键复制 Root 工具命令” (Copy Root tool command) under “SSH 配置入口”. Log in to this panel server through SSH as Root and run the copied command. Check the saved address, port, and WS mode in the wizard before confirming the modification. Return to the plugin page and verify the entry afterward. Saving settings or copying a command does not execute Root operations from the web application.

The local firewall step supports active firewalld and UFW. If UFW is inactive, it saves the TCP rule and reports that status without enabling UFW. The default rule permits all source addresses to reach the selected TCP port. You must separately open the port in your provider's firewall / cloud security group. A saved local rule alone does not prove external connectivity.

This basic flow uses REST synchronization when no WebSocket backend is configured. FlowScope's panel plugin is enabled, but each node's collector still needs its own installation on that node. If using domain-only communication or deliberately deferring the IP entry, add `--skip-endpoint`; the plugins are still installed, but the private IP entry is not ready.

## Detailed Configuration

### 1. Configure Daemon Process

Install Supervisor through aaPanel and add the queue worker after installation:

- Name: `Xboard`
- Run User: **`www`**
- Running Directory: `/www/wwwroot/your-domain`
- Start Command: `/www/server/php/83/bin/php artisan horizon`
- Process Count: 1

Replace the directory with your site path. Enable automatic start and restart for the worker. Do not run application workers as Root.

### 2. Configure Scheduled Tasks

- Type: Shell Script
- Task Name: `Xboard Scheduler`
- Run User: **`www`**
- Frequency: Every minute
- Script Content:

  ```bash
  /www/server/php/83/bin/php /www/wwwroot/your-domain/artisan schedule:run
  ```

Use the same PHP 8.3 CLI path as the installer and the actual site directory.

### 3. Octane Configuration (Optional)

This release's complete installation and Root endpoint integration were validated with PHP-FPM. Octane deployment and its different proxy / Root-entry configuration were not validated. Keep the PHP-FPM configuration above for this guide; do not replace it with an Octane proxy and assume the Root entry will be configured automatically. An Octane deployment requires separate configuration and validation.

## Maintenance Guide

For automatic forwarding, the node management menu and retained rate-limit/routing fixes, follow the [v2026.10.25.2 update guide](../../installation/upgrade-2026.10.25.2.zh-CN.md) (Chinese). Update the backend and independent Node `v1.13-orphan.6` together; keep the existing database, `APP_KEY`, node bindings and CA trust.

### Version Updates

For an existing panel, use the [Root archive upgrade procedure](../../installation/bundled-native.zh-CN.md#已有面板升级). It requires a fixed release archive, an independently checked SHA-256, and an existing protected database backup. It preserves `.env` / APP_KEY, runtime data, plugin settings, CA, and existing disabled-plugin choices. Dependency manifests must match; an original panel with different dependencies needs a separately prepared upgrade.

Do not rerun fresh installation, regenerate APP_KEY, or uninstall plugins first. Modified plugin sources, an unknown old QR core overlay, and ambiguous Root endpoint ownership require separate review. No automatic Git reset, original upstream fetch, or submodule repair is part of this upgrade route. After an application upgrade, restart the site's Supervisor workers as needed.

### Routine Maintenance

- Check application, PHP-FPM, Nginx, and Supervisor logs.
- Monitor resource usage and worker status.
- Back up the database, `.env` / APP_KEY, source, plugins and settings, and Root endpoint state. Keep encrypted CA private-key backups offline.
- Test that backups can be restored; the upgrade tool's backup-file checks do not establish recoverability.

## Troubleshooting

### Common Issues

**Private-certificate nodes connect, but the server page says IPv4 is pending:** The old independent Nginx entry omitted FlowScope's exact `POST /api/v2/flowscope/node-address` route. Machine status uses a different endpoint. Fresh v2026.10.25.2 installs use root-installer-v1.0.9 / tools 1.0.6 to include the audited IPv4 route. Updating panel code or the plugin ZIP alone does not necessarily update an existing Nginx entry. Run once as Root on the panel server:

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.9/install.sh | bash -s -- --refresh-entry
```

The tool reads the managed entry's current address, port, site, PHP and WS mode. Review the plan and enter its full confirmation phrase. Automatic refresh supports native HTTPS FPM / Octane entries; Docker is excluded. It preserves the CA, server certificate, managed passphrase, node Token and database, without changing plugin settings or firewall rules. Ensure FlowScope 2.4.0 is enabled, the node uses independent Node v1.13-orphan.6, and it can reach `https://api-ipv4.ip.sb/ip`. Automatic IPv4 reporting requires neither the Python collector nor periodic traffic. After detection succeeds, allow up to 10 minutes for the previous 404 cache to expire, then refresh the page. Do not reinstall, change Tokens or force a Node restart. `--sync-ca` and `--configure-firewall` do not refresh routes; use the ordinary Root wizard to change an IP.

**An older release's dashboard works, but installation stopped with `Filesystem.php` / undefined `exec`:** Xboard and the database may already be installed. The fresh installer runs `storage:link` before configuring the private IP entry, firewall, and public CA, so those later steps are still pending. The new fresh-install flow checks PHP CLI `symlink` availability before Composer or database initialization. To recover an older installation, enable `symlink` in PHP 8.3's disabled-function settings and ensure the CLI configuration has taken effect. Deploy this release's code, then run the following as Root from the installed site directory, replacing the paths:

```bash
cd /www/wwwroot/your-domain &&
runuser -u www -- /www/server/php/83/bin/php artisan storage:link &&
bash update.sh --deployed-code --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

This resumes the installed application and Root setup. If no endpoint exists, choose automatic public IPv4 detection or manual address entry; an existing real endpoint retains its address. Do not remove `.env`, change APP_KEY, or rerun `init.sh` to recover an installed site.

**v2026.10.18.1 / v2026.10.18.2 stopped after an incomplete `INSTALL` confirmation:** If the application is already installed, keep its database and `.env`. Deploy this release's code, then resume the pending Root setup from that site directory with:

```bash
cd /www/wwwroot/your-domain &&
bash update.sh --deployed-code --app-user www \
  --php /www/server/php/83/bin/php \
  --composer /opt/xboard-tools/composer-2.10.3.phar \
  --port 8443
```

Use the actual site path and review the communication address offered by the installer. At the confirmation prompt, enter the full phrase for the displayed plan as explained in section 3.3. Do not rerun `init.sh`, delete `.env`, or regenerate APP_KEY. An aborted Root confirmation does not roll back application installation.

**The saved communication address is `203.0.113.10`:** This was an old documentation example, not the panel server's public IP. If its Root endpoint was already created, run this on the same independent Xboard panel server as Root:

```bash
curl -fsSL https://raw.githubusercontent.com/ksr-v/Xboard-Independent-NodeEndpoint/root-installer-v1.0.9/install.sh | bash -s -- --repair-address
```

Choose automatic public IPv4 detection or manual address entry. For automatic detection, review the candidate and press Enter to accept it or enter the actual communication IP / domain; manual entry does not contact the detection service. Review and confirm the modification plan. The tool keeps the existing CA and passphrase, issues a server certificate for the new address, saves settings as the application user, and synchronizes the public CA. Changing the plugin page alone does not repair the server certificate. This command requires the independent backend's `xboard:configure-endpoint` command. If no Root endpoint exists yet, use the installed-site continuation above and check the real address. Refresh the plugin page and copy fresh node installation commands afterward; do not reinstall Xboard or recreate its CA.

1. **Empty Admin Dashboard:** Check browser errors, the deployed release's local Admin assets, and the application / Nginx logs. Restore verified assets from the independent release if needed; do not fetch original-author submodules.
2. **Root installer cannot discover the site:** Confirm `/public`, the actual PHP 8.3 FPM backend, Laravel rewrite rules, and running Nginx / FPM services before rerunning the failed step. Resolve ambiguous or multiple-site discovery explicitly; do not guess a socket.
3. **PHP or Composer failure:** Check that both CLI and FPM use PHP 8.3 and the required extensions are available. An error about `composer-runtime-api ^2.2` means the selected Composer is too old; prepare the pinned PHAR in section 2.1 and pass its explicit `--composer` path. Do not change `composer.lock` or bypass platform requirements. A duplicate `mbstring` warning is separate from this fatal dependency error; check duplicate extension entries in the appropriate PHP configuration.
4. **Database or Redis connection failure:** Check service status, credentials, database existence, and permissions. Do not delete `.env` to retry an installed panel.
5. **IP endpoint timeout:** Check the actual listener, local firewall status, and provider / cloud firewall. Keep certificate validation enabled; a connection timeout is not solved by disabling TLS verification.
6. **Only the firewall or CA step failed:** Read the reported state and use the documented targeted recovery for that step. Do not reinstall the panel or regenerate its CA. Keep partial-install evidence for diagnosis.

If **v2026.10.18.1** stopped during Composer dependency installation, before application initialization / APP_KEY creation, its cache warning can be repaired as Root with:

```bash
install -d -o root -g root -m 755 /var/cache/xboard-composer
```

Then rerun that original `init.sh` command with the verified `--composer /opt/xboard-tools/composer-2.10.3.phar` argument. Version v2026.10.18.2 handles the cache-parent permissions itself. Keep `.env`; do not delete it to force a retry. If application initialization has already begun or the panel is installed, preserve its state and diagnose the failed stage before proceeding.

## Enable WebSocket Real-time Sync (Optional)

REST synchronization is sufficient for the installation above. WebSocket setup is a separate optional change, especially if the private IP entry already exists.

### 1. Start WS Server

Add a WebSocket daemon in aaPanel Supervisor:

- Name: `Xboard-WS`
- Run User: **`www`**
- Running Directory: `/www/wwwroot/your-domain`
- Start Command: `/www/server/php/83/bin/php artisan ws-server start`
- Process Count: 1

### 2. Configure Nginx

Configure the domain site's WebSocket proxy to the actual local WS listener. For a server listening at `127.0.0.1:8076`, add this location before the main application location and validate / reload Nginx:

```nginx
location /ws/ {
    proxy_pass http://127.0.0.1:8076;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 60s;
}
```

### 3. Verify Both Communication Entries

Restart the WS Supervisor process and verify the domain WebSocket route. If NodeEndpoint's private IP HTTPS entry was installed earlier in REST mode, changing the domain site does **not** add WebSocket support to that separate Root-owned entry. Use the NodeEndpoint Root tool's reviewed modification procedure to configure its WS backend and the matching plugin WS mode, preserving the existing CA and certificates. Rerunning `init.sh` or ordinary application upgrades will not modify an existing Root entry for you. Keep REST mode until both entries are configured and verified.
