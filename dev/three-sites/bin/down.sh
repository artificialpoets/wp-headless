#!/usr/bin/env bash

set -euo pipefail

BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STACK_DIR="$(cd "${BIN_DIR}/.." && pwd)"
WP_HEADLESS_DIR="$(cd "${STACK_DIR}/../.." && pwd)"
PROJECTS_DIR="$(cd "${WP_HEADLESS_DIR}/.." && pwd)"

export PROJECTS_DIR

docker compose -f "${STACK_DIR}/docker-compose.yml" down "$@" >/dev/null 2>&1 || true
"${BIN_DIR}/down-local.sh" >/dev/null 2>&1 || true
