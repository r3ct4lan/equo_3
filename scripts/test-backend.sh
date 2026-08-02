#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
compose="docker compose --project-directory $project_dir"
database_url='postgresql://equo_test:equo_test_only@postgres-test:5432/equo?serverVersion=17&charset=utf8'

cleanup() {
    $compose --profile quality rm -sf postgres-test >/dev/null 2>&1 || true
}

trap cleanup EXIT HUP INT TERM
cleanup

$compose --profile quality up -d --wait postgres-test
$compose run --rm -T --no-deps \
    -e APP_ENV=test \
    -e APP_SECRET=equo_test_only_not_a_secret \
    -e DATABASE_URL="$database_url" \
    backend \
    sh -lc 'composer check:migrations && composer test'
