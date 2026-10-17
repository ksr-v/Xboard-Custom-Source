#!/usr/bin/env bash
set -Eeuo pipefail
[[ "$#" -le 2 ]] || { echo "Usage: $0 [commit [output-directory]]" >&2; exit 2; }
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
python3 -I "$script_dir/build-private-release.py" \
    --commit "${1:-HEAD}" \
    --output "${2:-$script_dir/../../../private-assets/xboard/releases}"
