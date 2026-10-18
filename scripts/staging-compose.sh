#!/bin/sh
set -eu
project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
exec docker compose --env-file /etc/equo/staging.env \
    -f "$project_dir/compose.staging.yaml" "$@"
