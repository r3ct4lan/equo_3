#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
services=$(docker compose --project-directory "$project_dir" config --services)

for service in nginx frontend backend postgres redis rabbitmq worker; do
    if ! printf '%s\n' "$services" | grep -Fxq "$service"; then
        echo "Required deployment service is missing from compose.yaml: $service" >&2
        exit 1
    fi
done

echo 'Required deployment services are defined.'
