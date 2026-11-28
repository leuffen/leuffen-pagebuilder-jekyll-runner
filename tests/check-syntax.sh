#!/usr/bin/env bash
set -euo pipefail

image="leuffen-pagebuilder-preview:local"
docker run --rm --entrypoint sh -v "$PWD:/test-suite:ro" -w /test-suite "$image" -ec '
    find /opt/src /opt/bin /test-suite/src /test-suite/tests -path "*/vendor" -prune -o -name "*.php" -print0 | xargs -0 -n1 php -l
    /kickstart/bin/_kick_php -f /opt/.kicker/conf/etc/nginx/nginx.conf > /etc/nginx/nginx.conf
    grep -Fq "limit_conn preview_subscribers 400;" /etc/nginx/nginx.conf
    nginx -t
    PREVIEW_SUBSCRIBER_LIMIT=7 /kickstart/bin/_kick_php -f /opt/.kicker/conf/etc/nginx/nginx.conf > /etc/nginx/nginx.conf
    grep -Fq "limit_conn preview_subscribers 7;" /etc/nginx/nginx.conf
    nginx -t
    PREVIEW_SUBSCRIBER_LIMIT=65535 /kickstart/bin/_kick_php -f /opt/.kicker/conf/etc/nginx/nginx.conf > /etc/nginx/nginx.conf
    grep -Fq "limit_conn preview_subscribers 65535;" /etc/nginx/nginx.conf
    nginx -t
    for invalid_limit in 0 65536 -1 1.5 abc; do
        if PREVIEW_SUBSCRIBER_LIMIT="$invalid_limit" /kickstart/bin/_kick_php -f /opt/.kicker/conf/etc/nginx/nginx.conf > /dev/null 2>&1; then
            echo "Invalid subscriber limit was accepted: $invalid_limit" >&2
            exit 1
        fi
    done
    php tests/publisher.php
'
composer validate --no-check-publish
composer validate --working-dir=container --no-check-publish
python3 -c 'import ast, pathlib; ast.parse(pathlib.Path("tests/http-preview.py").read_text())'
bash -n tests/integration.sh container/.kicker/conf/kickstart/run/start.d/10-start-nginx.sh container/.kicker/conf/kickstart/run/stop.d/10-stop-nginx.sh
docker compose -f tests/compose.yaml config --quiet
