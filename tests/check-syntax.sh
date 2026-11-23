#!/usr/bin/env bash
set -euo pipefail

image="leuffen-pagebuilder-jekyll-runner:local"
docker run --rm --entrypoint sh "$image" -c 'find /opt/src /opt/bin /opt/docs /opt/tests -name "*.php" -print0 | xargs -0 -n1 php -l'
docker run --rm --entrypoint apache2ctl "$image" configtest
docker compose -f tests/compose.yaml config --quiet
