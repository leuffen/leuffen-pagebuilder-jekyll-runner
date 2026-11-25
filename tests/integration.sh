#!/usr/bin/env bash
set -euo pipefail

compose=(docker compose -f tests/compose.yaml)
mkdir -p data/source/demo data/target
sudo chmod 777 data/target
cp tests/fixtures/demo/_config.yml data/source/demo/_config.yml
cp tests/fixtures/demo/index.html data/source/demo/index.html

printf '%s\n' '</body>' > data/source/demo/plain.txt

PREVIEW_RELOAD_JS= "${compose[@]}" up --detach --no-build --wait --wait-timeout 90
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
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: unknown.example' http://localhost:8080/)" = 403
test "$(curl --silent -o /dev/null -w '%{http_code}' -X POST -H 'Host: demo.localhost' http://localhost:8080/)" = 403
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: demo.localhost' http://localhost:8080/.runner-locks/)" = 403

# The default CDN response is unchanged and the reload endpoint is disabled.
html="$(curl --fail --silent --show-error -H 'Host: demo.localhost' http://localhost:8080/)"
if [[ "$html" == *'/__preview_reload.js'* ]]; then
    echo 'Unexpected reload script in default HTML response' >&2
    exit 1
fi
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: demo.localhost' http://localhost:8080/__preview_reload.js)" = 404

# Enable custom multiline JavaScript without changing the published files.
reload_js=$'window.previewReload = "enabled";\n// custom | script'
PREVIEW_RELOAD_JS="$reload_js" "${compose[@]}" up --detach --no-build --force-recreate --no-deps worker
html="$(curl --fail --silent --show-error --retry 15 --retry-delay 1 --retry-connrefused -H 'Host: demo.localhost' http://localhost:8080/)"
grep -Fq '<script src="/__preview_reload.js"></script></body>' <<< "$html"
test "$(curl --fail --silent --show-error -H 'Host: demo.localhost' http://localhost:8080/__preview_reload.js)" = "$reload_js"
test "$(curl --fail --silent --show-error -H 'Host: demo.localhost' http://localhost:8080/plain.txt)" = '</body>'
sha256sum --check "$checksum_file"

# Disabling reload on the next start must remove the filter and script again.
PREVIEW_RELOAD_JS= "${compose[@]}" up --detach --no-build --force-recreate --no-deps worker
html="$(curl --fail --silent --show-error --retry 15 --retry-delay 1 --retry-connrefused -H 'Host: demo.localhost' http://localhost:8080/)"
if [[ "$html" == *'/__preview_reload.js'* ]]; then
    echo 'Reload script still present after disabling it' >&2
    exit 1
fi
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: demo.localhost' http://localhost:8080/__preview_reload.js)" = 404
sha256sum --check "$checksum_file"
