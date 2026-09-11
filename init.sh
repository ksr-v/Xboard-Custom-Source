#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${APP_DIR}"

PHP_BIN="${XBOARD_PHP_BIN:-}"
if [[ -z "${PHP_BIN}" ]]; then
  for candidate in /www/server/php/83/bin/php /www/server/php/82/bin/php php8.2 php; do
    if command -v "${candidate}" >/dev/null 2>&1 || [[ -x "${candidate}" ]]; then
      PHP_BIN="${candidate}"
      break
    fi
  done
fi

if [[ -z "${PHP_BIN}" ]]; then
  echo "PHP 8.2+ was not found. Set XBOARD_PHP_BIN and retry." >&2
  exit 1
fi

if ! "${PHP_BIN}" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
  echo "Xboard requires PHP 8.2 or newer." >&2
  "${PHP_BIN}" -v >&2 || true
  exit 1
fi

COMPOSER_BIN="${XBOARD_COMPOSER_BIN:-}"
if [[ -z "${COMPOSER_BIN}" ]] && command -v composer >/dev/null 2>&1; then
  COMPOSER_BIN="$(command -v composer)"
fi
if [[ -z "${COMPOSER_BIN}" ]]; then
  COMPOSER_BIN="${APP_DIR}/composer.phar"
  if [[ ! -f "${COMPOSER_BIN}" ]]; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "${PHP_BIN}" /tmp/composer-setup.php --install-dir="${APP_DIR}" --filename=composer.phar
    rm -f /tmp/composer-setup.php
  fi
fi

# Update the selected Composer installation before resolving dependencies.
"${PHP_BIN}" "${COMPOSER_BIN}" self-update --stable

"${PHP_BIN}" "${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader

if [[ -d .git && ! -f public/assets/admin/index.html ]]; then
  git submodule update --init --recursive --force
elif [[ ! -f public/assets/admin/index.html ]]; then
  echo "public/assets/admin/index.html is missing; cannot continue." >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

"${PHP_BIN}" artisan key:generate --force

if [[ -z "${XBOARD_DB_NAME:-}" ]]; then
  read -r -p "Database name [xboard]: " XBOARD_DB_NAME
  XBOARD_DB_NAME="${XBOARD_DB_NAME:-xboard}"
fi

if [[ -z "${XBOARD_DB_USER:-}" ]]; then
  read -r -p "Database user [${XBOARD_DB_NAME}]: " XBOARD_DB_USER
  XBOARD_DB_USER="${XBOARD_DB_USER:-${XBOARD_DB_NAME}}"
fi

if [[ -z "${XBOARD_DB_PASSWORD:-}" ]]; then
  read -r -s -p "Database password for ${XBOARD_DB_USER:-xboard}: " XBOARD_DB_PASSWORD
  printf '\n'
  if [[ -z "${XBOARD_DB_PASSWORD}" ]]; then
    echo "Database password cannot be empty." >&2
    exit 1
  fi
fi

"${PHP_BIN}" artisan optimize:clear >/dev/null

INSTALL_ARGS=(
  --database="${XBOARD_DB_DRIVER:-mysql}"
  --db-host="${XBOARD_DB_HOST:-127.0.0.1}"
  --db-port="${XBOARD_DB_PORT:-3306}"
  --db-name="${XBOARD_DB_NAME:-xboard}"
  --db-user="${XBOARD_DB_USER:-xboard}"
  --redis-host="${XBOARD_REDIS_HOST:-127.0.0.1}"
  --redis-port="${XBOARD_REDIS_PORT:-6379}"
  --redis-password="${XBOARD_REDIS_PASSWORD:-}"
)

if [[ -n "${XBOARD_DB_PASSWORD:-}" ]]; then
  INSTALL_ARGS+=(--db-password="${XBOARD_DB_PASSWORD}")
fi
if [[ "${XBOARD_NON_INTERACTIVE:-0}" == "1" ]]; then
  INSTALL_ARGS+=(--no-interaction)
fi

"${PHP_BIN}" artisan xboard:install "${INSTALL_ARGS[@]}"
"${PHP_BIN}" artisan storage:link || true
"${PHP_BIN}" artisan optimize

if [[ -f /etc/init.d/bt || -f /.dockerenv ]]; then
  chown -R www:www "${APP_DIR}"
fi

if [[ -d .docker/.data ]]; then
  chmod -R 777 .docker/.data
fi

cat <<EOF

Installation completed.
PHP: ${PHP_BIN}
Application directory: ${APP_DIR}
Save the admin path and initial password, then change the password after first login.
EOF
