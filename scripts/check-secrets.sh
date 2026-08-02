#!/bin/sh

set -eu

cd "$(dirname "$0")/.."

pattern='-----BEGIN (RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|ASIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{30,}|github_pat_[A-Za-z0-9_]{20,}|xox[baprs]-[A-Za-z0-9-]{20,}|sk_live_[A-Za-z0-9]{20,}'

if git grep -nEI -e "$pattern" -- . ':!*.lock'; then
    echo 'Potential secret material found in a tracked file.' >&2
    exit 1
fi

echo 'Tracked-file secret pattern check passed.'
