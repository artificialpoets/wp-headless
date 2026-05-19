#!/usr/bin/env bash

set -euo pipefail

BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if docker version >/dev/null 2>&1; then
	echo "Using Docker stack."
	exec "${BIN_DIR}/up-docker.sh" "$@"
fi

echo "Docker daemon unavailable. Falling back to local WP-CLI stack."
exec "${BIN_DIR}/up-local.sh" "$@"
