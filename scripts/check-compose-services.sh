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

docker compose --project-directory "$project_dir" config --format json | python3 -c '
import json
import sys

config = json.load(sys.stdin)
services = config["services"]

def require(condition, message):
    if not condition:
        print(message, file=sys.stderr)
        raise SystemExit(1)

def command_text(service):
    command = services[service]["healthcheck"]["test"]
    return " ".join(command) if isinstance(command, list) else str(command)

for name in ("backend", "frontend", "nginx", "postgres", "redis", "rabbitmq"):
    require("healthcheck" in services[name], f"Missing healthcheck: {name}")

require("cgi-fcgi" in command_text("backend") and "equo-fpm-ready" in command_text("backend"),
        "Backend healthcheck must probe the PHP-FPM ping endpoint.")
require("/api/health" in command_text("nginx"), "Nginx healthcheck must request /api/health.")

for dependency in ("postgres", "redis", "rabbitmq"):
    require(services["backend"].get("depends_on", {}).get(dependency, {}).get("condition") == "service_healthy",
            f"Backend must wait for healthy {dependency}.")
for service in ("nginx", "worker"):
    require(services[service].get("depends_on", {}).get("backend", {}).get("condition") == "service_healthy",
            f"{service} must wait for healthy backend.")
require(services["nginx"].get("depends_on", {}).get("frontend", {}).get("condition") == "service_healthy",
        "Nginx must wait for healthy frontend.")

worker = services["worker"]
require(worker.get("command") == ["/usr/local/bin/equo-worker-entrypoint"],
        "Worker must use the signal-forwarding entrypoint.")
require(worker.get("stop_grace_period") == "1m30s", "Worker must have a 90-second graceful stop window.")
print("Compose readiness, startup dependencies and worker shutdown are configured.")
'
