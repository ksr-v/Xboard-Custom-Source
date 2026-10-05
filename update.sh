#!/bin/bash
set -Eeuo pipefail

if [ ! -d ".git" ]; then
  echo "Please deploy using Git."
  exit 1
fi

if ! command -v git &> /dev/null; then
    echo "Git is not installed! Please install git and try again."
    exit 1
fi

repo_root="$(pwd)"

add_safe_directory() {
  local dir="$1"

  git config --global --get-all safe.directory | grep -Fx "$dir" > /dev/null ||     git config --global --add safe.directory "$dir"
}

add_safe_directory "$repo_root"
update_ref="${XBOARD_UPDATE_REF:-master}"

git fetch private "$update_ref"
git merge --ff-only FETCH_HEAD

if [[ -n "${XBOARD_COMPOSER_BIN:-}" ]]; then
  composer_bin=("${XBOARD_COMPOSER_BIN}")
elif command -v composer >/dev/null 2>&1; then
  composer_bin=("$(command -v composer)")
elif [[ -f "${repo_root}/composer.phar" ]]; then
  composer_bin=(php "${repo_root}/composer.phar")
else
  echo "Composer was not found. Set XBOARD_COMPOSER_BIN or provide composer.phar." >&2
  exit 1
fi

"${composer_bin[@]}" install --no-dev --no-interaction --optimize-autoloader
php artisan xboard:update

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www $(pwd);
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data
fi