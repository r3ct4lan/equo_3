#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
project_name=${E2E_PROJECT_NAME:-equo-3-e2e}
compose="docker compose -p $project_name -f $project_dir/compose.e2e.yaml"
before=$(mktemp)
after=$(mktemp)
secrets_dir=$(mktemp -d)

generate_e2e_secrets() {
    jwt_private="$secrets_dir/jwt-private.pem"
    jwt_public="$secrets_dir/jwt-public.pem"
    tls_key="$secrets_dir/nginx.key"
    tls_cert="$secrets_dir/nginx.crt"
    tls_config="$secrets_dir/tls.cnf"

    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$jwt_private" >/dev/null 2>&1
    openssl rsa -in "$jwt_private" -pubout -out "$jwt_public" >/dev/null 2>&1

    cat >"$tls_config" <<'EOF'
[req]
distinguished_name = dn
x509_extensions = v3_req
prompt = no

[dn]
CN = nginx

[v3_req]
subjectAltName = @alt_names

[alt_names]
DNS.1 = nginx
EOF
    openssl req -x509 -newkey rsa:2048 -nodes -days 2 \
        -keyout "$tls_key" \
        -out "$tls_cert" \
        -config "$tls_config" >/dev/null 2>&1

    jwt_private_b64=$(base64 -w 0 <"$jwt_private")
    jwt_public_b64=$(base64 -w 0 <"$jwt_public")
    csrf_key_b64=$(openssl rand -base64 32 | tr -d '\n')

    export E2E_TLS_DIR="$secrets_dir"
    export E2E_JWT_SIGNING_PRIVATE_KEY="$jwt_private_b64"
    export E2E_JWT_PUBLIC_KEY_RING="{\"v1\":\"$jwt_public_b64\"}"
    export E2E_CSRF_SIGNING_KEY_RING="{\"v1\":\"$csrf_key_b64\"}"
}

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
    rm -rf "$secrets_dir"
    exit "$status"
}

trap cleanup EXIT HUP INT TERM
cd "$project_dir"

git status --porcelain=v1 --untracked-files=all >"$before"
generate_e2e_secrets

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

echo 'Full auth/session browser E2E passed without source changes.'
