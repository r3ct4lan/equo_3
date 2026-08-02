#!/bin/sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)

docker compose \
    --project-directory "$project_dir" \
    run --rm -T --no-deps \
    --volume "$project_dir:/workspace:ro" \
    backend \
    php -r "require '/var/www/backend/vendor/autoload.php'; Symfony\\Component\\Yaml\\Yaml::parseFile('/workspace/.github/workflows/quality.yml');"

echo 'GitHub Actions workflow YAML is valid.'
