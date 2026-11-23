#!/usr/bin/env bash
set -euo pipefail

compose=(docker compose -f tests/compose.yaml)
mkdir -p data/source/demo data/target
sudo chmod 777 data/target
cp tests/fixtures/demo/_config.yml data/source/demo/_config.yml
cp tests/fixtures/demo/index.html data/source/demo/index.html

"${compose[@]}" up --detach --no-build --wait --wait-timeout 90
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
