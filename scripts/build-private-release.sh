#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    echo "Usage: $0 [commit [output-directory]]" >&2
    exit 2
}

[[ "$#" -le 2 ]] || usage

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(git -C "$script_dir" rev-parse --show-toplevel)"
commit_ref="${1:-HEAD}"
output_dir="${2:-$repo_root/../../private-assets/xboard/releases}"

commit="$(git -C "$repo_root" rev-parse --verify "${commit_ref}^{commit}")"
head="$(git -C "$repo_root" rev-parse HEAD)"
if [[ "$commit" != "$head" ]]; then
    echo "Refusing to build a non-HEAD commit; check out the intended release commit first." >&2
    exit 1
fi
if [[ -n "$(git -C "$repo_root" status --porcelain=v1 --untracked-files=all)" ]]; then
    echo "Refusing to build from a dirty worktree. Commit or remove all changes first." >&2
    exit 1
fi

command -v tar >/dev/null || { echo "tar is required." >&2; exit 1; }
command -v gzip >/dev/null || { echo "gzip is required." >&2; exit 1; }
command -v sha256sum >/dev/null || { echo "sha256sum is required." >&2; exit 1; }
command -v php >/dev/null || { echo "PHP CLI is required to encode the JSON manifest." >&2; exit 1; }

short_commit="${commit:0:7}"
release_date="$(git -C "$repo_root" show -s --format=%cI "$commit")"
release_day="${release_date:0:4}${release_date:5:2}${release_date:8:2}"
version="${release_day}-${short_commit}"
output_dir="$(mkdir -p "$output_dir" && cd "$output_dir" && pwd)"
release_dir="$output_dir/releases/$version"
if [[ -e "$release_dir" ]]; then
    echo "Release already exists: $release_dir" >&2
    exit 1
fi

work_dir="$(mktemp -d)"
cleanup() { rm -rf "$work_dir"; }
trap cleanup EXIT
tree_dir="$work_dir/tree"
mkdir -p "$tree_dir"
git -C "$repo_root" archive --format=tar "$commit" | tar -xf - -C "$tree_dir"

rm -rf -- \
    "$tree_dir/.env" \
    "$tree_dir/.git" \
    "$tree_dir/storage" \
    "$tree_dir/vendor" \
    "$tree_dir/public/storage" \
    "$tree_dir/bootstrap/cache"

if find "$tree_dir" -type l -print -quit | grep -q .; then
    echo "Refusing to publish a source tree containing symbolic links." >&2
    exit 1
fi
for required in artisan composer.json composer.lock app config routes; do
    [[ -e "$tree_dir/$required" ]] || { echo "Required path is missing: $required" >&2; exit 1; }
done

archive_tmp="$work_dir/xboard.tar.gz"
tar -czf "$archive_tmp" -C "$tree_dir" .
archive_size="$(wc -c < "$archive_tmp" | tr -d '[:space:]')"
if (( archive_size < 1 || archive_size > 262144000 )); then
    echo "Archive size is outside the supported 1..262144000 byte range." >&2
    exit 1
fi
archive_sha256="$(sha256sum "$archive_tmp" | cut -d' ' -f1)"
archive_author="$(git -C "$repo_root" show -s --format=%an "$commit")"
archive_message="$(git -C "$repo_root" show -s --format=%s "$commit")"

export RELEASE_VERSION="$version"
export RELEASE_COMMIT="$commit"
export RELEASE_SHA256="$archive_sha256"
export RELEASE_SIZE="$archive_size"
export RELEASE_PUBLISHED_AT="$release_date"
export RELEASE_AUTHOR="$archive_author"
export RELEASE_MESSAGE="$archive_message"
php -r '
$manifest = [
    "version" => getenv("RELEASE_VERSION"),
    "commit" => getenv("RELEASE_COMMIT"),
    "sha256" => getenv("RELEASE_SHA256"),
    "size" => (int) getenv("RELEASE_SIZE"),
    "published_at" => getenv("RELEASE_PUBLISHED_AT"),
    "author" => getenv("RELEASE_AUTHOR"),
    "message" => getenv("RELEASE_MESSAGE"),
];
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
' > "$work_dir/latest.json"

mkdir -p "$output_dir/releases"
mkdir "$release_dir"
install -m 0644 "$archive_tmp" "$release_dir/xboard.tar.gz"
if [[ -f "$output_dir/latest.json" ]]; then
    cp -p "$output_dir/latest.json" "$output_dir/latest.json.previous"
fi
install -m 0644 "$work_dir/latest.json" "$output_dir/latest.json.tmp"
mv -f "$output_dir/latest.json.tmp" "$output_dir/latest.json"

printf 'Published local release: %s\n' "$version"
printf 'Manifest: %s/latest.json\n' "$output_dir"
printf 'Archive: %s/xboard.tar.gz\n' "$release_dir"
printf 'SHA-256: %s\n' "$archive_sha256"