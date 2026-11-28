#!/usr/bin/env bash
set -euo pipefail

image="leuffen-pagebuilder-preview:local"
docker run --rm --entrypoint sh -v "$PWD:/test-suite:ro" -w /test-suite "$image" -ec '
    find /opt/src /opt/bin /test-suite/src /test-suite/tests -path "*/vendor" -prune -o -name "*.php" -print0 | xargs -0 -n1 php -l
    php /opt/.kicker/conf/etc/nginx/nginx.conf > /etc/nginx/nginx.conf
    nginx -t
    php tests/publisher.php
'
composer validate --no-check-publish
composer validate --working-dir=container --no-check-publish
python3 -c 'import ast, pathlib; ast.parse(pathlib.Path("tests/http-preview.py").read_text())'
bash -n tests/integration.sh container/.kicker/conf/kickstart/run/start.d/10-start-nginx.sh container/.kicker/conf/kickstart/run/stop.d/10-stop-nginx.sh
docker compose -f tests/compose.yaml config --quiet
