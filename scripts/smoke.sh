#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)

if [ -n "${APP_BASE_URL:-}" ]; then
    base_url=$APP_BASE_URL
else
    published=$(docker compose --project-directory "$project_dir" port nginx 80)
    port=${published##*:}
    base_url="http://localhost:$port"
fi

curl --fail --silent --show-error --max-time 10 "$base_url/" >/dev/null
curl --fail --silent --show-error --max-time 10 "$base_url/api/health" >/dev/null

echo "Frontend and API smoke checks passed at $base_url."
