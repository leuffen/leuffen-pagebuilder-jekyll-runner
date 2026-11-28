#!/usr/bin/env bash
set -euo pipefail

compose=(docker compose -f tests/compose.yaml)
mkdir -p data/source/demo data/target
sudo chmod 777 data/target
cp tests/fixtures/demo/_config.yml data/source/demo/_config.yml
cp tests/fixtures/demo/index.html data/source/demo/index.html

printf '%s\n' '</body>' > data/source/demo/plain.txt

PREVIEW_RELOAD_JS_URL= "${compose[@]}" up --detach --no-build --wait --wait-timeout 90
php tests/build-success.php
grep -q 'Runner smoke' data/target/demo/index.html

checksum_file="$(mktemp)"
trap 'rm -f "$checksum_file"' EXIT
sha256sum data/target/demo/index.html > "$checksum_file"

printf '%s\n' '---' '---' '{% definitely_not_a_liquid_tag %}' > data/source/demo/index.html
php tests/build-failures.php
sha256sum --check "$checksum_file"

curl --fail --silent --show-error -H 'Host: demo.localhost' http://localhost:8080/ | grep -q 'Runner smoke'
curl --fail --silent --show-error --head -H 'Host: demo.localhost' http://localhost:8080/ > /dev/null
python3 tests/http-preview.py routing
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: unknown.example' http://localhost:8080/)" = 403
test "$(curl --silent -o /dev/null -w '%{http_code}' -X POST -H 'Host: demo.localhost' http://localhost:8080/)" = 403
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: demo.localhost' http://localhost:8080/.runner-locks/)" = 403

# The default CDN response is unchanged and no reload endpoint exists.
html="$(curl --fail --silent --show-error -H 'Host: demo.localhost' http://localhost:8080/)"
if [[ "$html" == *'<script src='* ]]; then
    echo 'Unexpected reload script in default HTML response' >&2
    exit 1
fi
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: demo.localhost' http://localhost:8080/__preview_reload.js)" = 404

# Inject an external script URL without serving a local file or changing published files.
reload_url='https://preview-tools.example.com/reload.js?v=1&mode=preview&token=$channel'
PREVIEW_RELOAD_JS_URL="$reload_url" "${compose[@]}" up --detach --no-build --force-recreate --no-deps worker
html="$(curl --fail --silent --show-error --retry 15 --retry-delay 1 --retry-connrefused --retry-all-errors -H 'Host: demo.localhost' http://localhost:8080/)"
grep -Fq '<script src="https://preview-tools.example.com/reload.js?v=1&amp;mode=preview&amp;token=&#36;channel"></script></body>' <<< "$html"
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: demo.localhost' http://localhost:8080/__preview_reload.js)" = 404
test "$(curl --fail --silent --show-error -H 'Host: demo.localhost' http://localhost:8080/plain.txt)" = '</body>'
sha256sum --check "$checksum_file"

# Real messages must also remain unchanged while HTML injection is enabled.
python3 tests/http-preview.py messages
"${compose[@]}" exec -T worker php -r '$socket = @fsockopen("worker", 8081, $code, $reason, 1); if ($socket !== false) { throw new RuntimeException("Nchan publisher is reachable on the container network"); }'

# A failed local POST must propagate through RabbitMQ instead of returning OK.
"${compose[@]}" exec -T --user root worker nginx -s stop
python3 tests/http-preview.py publisher-unavailable

# Disabling reload on the next start must remove the injected tag again.
PREVIEW_RELOAD_JS_URL= "${compose[@]}" up --detach --no-build --force-recreate --no-deps worker
html="$(curl --fail --silent --show-error --retry 15 --retry-delay 1 --retry-connrefused --retry-all-errors -H 'Host: demo.localhost' http://localhost:8080/)"
if [[ "$html" == *'<script src='* ]]; then
    echo 'Reload script still present after disabling it' >&2
    exit 1
fi
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: demo.localhost' http://localhost:8080/__preview_reload.js)" = 404
sha256sum --check "$checksum_file"
