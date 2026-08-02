#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
before=$(mktemp)
after=$(mktemp)

cleanup() {
    rm -f "$before" "$after"
}

trap cleanup EXIT HUP INT TERM
cd "$project_dir"

git status --porcelain=v1 --untracked-files=all >"$before"

make check-repository
make check-backend
make check-frontend
make smoke

git status --porcelain=v1 --untracked-files=all >"$after"

if ! cmp -s "$before" "$after"; then
    echo 'Quality checks changed tracked or untracked source files:' >&2
    diff -u "$before" "$after" >&2 || true
    exit 1
fi

echo 'Full quality gate passed without generated source changes.'
