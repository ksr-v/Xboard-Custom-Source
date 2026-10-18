# Xboard Deployment Guide for aaPanel Environment

This guide keeps the aaPanel PHP-FPM deployment flow for the independent **v2026.10.18.1** release. For Chinese instructions, see [宝塔 / aaPanel 安装教程](../../installation/aapanel.zh-CN.md). This release includes NodeEndpoint 1.0.3, SmartExpiry 1.2.0, QRCode Extend **1.2.0 (audited copy)**, and FlowScope 2.4.0. A fresh installation installs and enables all four plugins.

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
- A trusted **Composer 2** PHP script / PHAR, installed before starting this guide
- The PHP extensions required by the pinned `composer.lock`, enabled for both PHP 8.3 CLI and FPM

The installer does not install aaPanel, upgrade system software or Composer, or create database accounts. The complete installation was validated on an isolated PHP-FPM / SQLite test site; complete MySQL / PostgreSQL installation and Docker / Octane installation are outside this release's validated scope. See the [validation record](../../installation/bundled-validation.zh-CN.md).

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

Confirm that `python3 --version` is 3.10 or newer. Install trusted Composer 2 in a standard system path such as `/usr/local/bin/composer`, and check that `command -v composer` finds it. For a custom PHP script / PHAR path, add `--composer /actual/path/to/composer` to the installer command below. The installer runs Composer and application commands as the website user, not as Root. Do not run `composer install` separately as Root.

#### 2.2 Install PHP Extensions

Enable the extensions required by the pinned dependencies. These include `redis`, `fileinfo`, `mbstring`, `curl`, `dom` / XML, `openssl`, `pcntl`, and `posix`, in addition to PHP's standard extensions and the PDO driver for your database, such as `pdo_mysql`. Ensure Composer can extract distribution archives, using `unzip` or PHP's ZIP extension.

Use the same PHP 8.3 installation for CLI and FPM. Composer checks the complete platform requirements during installation; resolve any missing extension before proceeding. Swoole and Event are not required for this PHP-FPM route.

#### 2.3 Enable Required PHP Functions

Check PHP 8.3's disabled-function settings in aaPanel. Application installation and workers need functions such as `putenv`, `proc_open`, `pcntl_alarm`, and `pcntl_signal`. Apply changes to the relevant CLI configuration and restart PHP-FPM when required. Do not give the website user sudo access.

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
git clone --branch v2026.10.18.1 --single-branch \
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

Now run the installer from the site directory as Root. Replace the directory and example IP with your actual values. aaPanel normally uses the `www` website user; verify it before running the command.

```bash
cd /www/wwwroot/your-domain
bash init.sh --app-user www \
  --php /www/server/php/83/bin/php \
  --host '203.0.113.10' --port 8443
```

`203.0.113.10` is a documentation placeholder; replace it with the panel server's public communication IP. If Composer is outside a standard system path, append `--composer /actual/path/to/composer`.

The Root entry prepares the application and Admin patch directories, then runs Composer, Xboard installation, database migrations, and all four plugin lifecycles as `www`. Follow the database, Redis, and administrator prompts and save the displayed initial login information. You do not need to upload these plugins separately.

For NodeEndpoint, the same flow configures the private IP HTTPS entry, the local TCP firewall rule, and automatic public CA import. Confirm the displayed plan by typing the exact `INSTALL IP:8443` phrase. `Encrypted CA passphrase` asks for a hidden password of at least 16 characters to encrypt the CA private key; it is not the panel password. Keep it securely and complete the encrypted export / offline backup described in the NodeEndpoint tools. Do not upload a private key to the panel.

Root tools and PKI stay outside the application directory. After a successful installation, refresh the plugin page; do not download and rerun a separate Root installer or rerun `init.sh` on this installed site. Normal maintenance does not recreate the CA or certificates.

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

1. **Empty Admin Dashboard:** Check browser errors, the deployed release's local Admin assets, and the application / Nginx logs. Restore verified assets from the independent release if needed; do not fetch original-author submodules.
2. **Root installer cannot discover the site:** Confirm `/public`, the actual PHP 8.3 FPM backend, Laravel rewrite rules, and running Nginx / FPM services before rerunning the failed step. Resolve ambiguous or multiple-site discovery explicitly; do not guess a socket.
3. **PHP or Composer failure:** Check that both CLI and FPM use PHP 8.3, all pinned dependency requirements are available, and Composer is a trusted PHP script / PHAR accessible to the website user.
4. **Database or Redis connection failure:** Check service status, credentials, database existence, and permissions. Do not delete `.env` to retry an installed panel.
5. **IP endpoint timeout:** Check the actual listener, local firewall status, and provider / cloud firewall. Keep certificate validation enabled; a connection timeout is not solved by disabling TLS verification.
6. **Only the firewall or CA step failed:** Read the reported state and use the documented targeted recovery for that step. Do not reinstall the panel or regenerate its CA. Keep partial-install evidence for diagnosis.

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
