#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
project_name=${E2E_PROJECT_NAME:-equo-3-e2e}
compose="docker compose -p $project_name -f $project_dir/compose.e2e.yaml"
before=$(mktemp)
after=$(mktemp)

cleanup() {
    status=$?
    trap - EXIT HUP INT TERM

    if [ "$status" -ne 0 ]; then
        echo 'Safe API request log (query strings are not logged):' >&2
        $compose logs --no-color nginx 2>/dev/null | grep -E ' (GET|POST|PUT|PATCH|DELETE) /api/' >&2 || true
        $compose logs --no-color --tail=80 backend worker frontend nginx mailpit >&2 || true
    fi

    $compose --profile e2e down --remove-orphans >/dev/null 2>&1 || true
    rm -f "$before" "$after"
    exit "$status"
}

trap cleanup EXIT HUP INT TERM
cd "$project_dir"

git status --porcelain=v1 --untracked-files=all >"$before"

$compose --profile e2e build --quiet backend frontend e2e
$compose up -d --wait rabbitmq
$compose up -d --wait postgres redis mailpit
$compose run --rm -T backend php bin/console doctrine:migrations:migrate --no-interaction
$compose up -d --wait backend frontend nginx
$compose up -d worker
$compose --profile e2e run --rm -T --no-deps e2e

git status --porcelain=v1 --untracked-files=all >"$after"

if ! cmp -s "$before" "$after"; then
    echo 'E2E checks changed tracked or untracked source files:' >&2
    diff -u "$before" "$after" >&2 || true
    exit 1
fi

echo 'First vertical slice E2E passed without source changes.'
