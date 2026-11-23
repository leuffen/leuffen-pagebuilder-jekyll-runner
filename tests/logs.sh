#!/usr/bin/env bash
set -euo pipefail
docker compose -f tests/compose.yaml logs --no-color
