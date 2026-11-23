#!/usr/bin/env bash
set -euo pipefail

image="leuffen-pagebuilder-preview:local"
docker run --rm --entrypoint sh "$image" -c 'find /opt/src /opt/bin -name "*.php" -print0 | xargs -0 -n1 php -l'
php -l src/PageBuilderJekyllRunnerClient.php
composer validate --no-check-publish
composer validate --working-dir=container --no-check-publish
docker run --rm --entrypoint apache2ctl "$image" configtest
docker compose -f tests/compose.yaml config --quiet
