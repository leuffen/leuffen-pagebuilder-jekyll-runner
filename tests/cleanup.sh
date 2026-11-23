#!/usr/bin/env bash
set -euo pipefail
docker compose -f tests/compose.yaml down --volumes --timeout 5
