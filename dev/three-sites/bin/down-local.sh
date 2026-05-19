#!/usr/bin/env bash

set -euo pipefail

BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STACK_DIR="$(cd "${BIN_DIR}/.." && pwd)"
RUNTIME_DIR="${STACK_DIR}/.runtime"

stop_pid() {
	local pid_file="$1"
	if [ -f "${pid_file}" ] && kill -0 "$(cat "${pid_file}")" >/dev/null 2>&1; then
		kill "$(cat "${pid_file}")" >/dev/null 2>&1 || true
	fi
	rm -f "${pid_file}"
}

stop_pid "${RUNTIME_DIR}/pids/site1.pid"
stop_pid "${RUNTIME_DIR}/pids/site2.pid"
stop_pid "${RUNTIME_DIR}/pids/site3.pid"
stop_pid "${RUNTIME_DIR}/mysql/mysql.pid"
