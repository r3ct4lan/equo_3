#!/bin/sh

set -eu

status=$(git status --porcelain=v1 --untracked-files=all)

if [ -n "$status" ]; then
    echo 'Checks created or modified tracked or untracked files:' >&2
    printf '%s\n' "$status" >&2
    exit 1
fi
