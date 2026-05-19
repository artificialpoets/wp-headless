#!/usr/bin/env bash

set -euo pipefail

BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STACK_DIR="$(cd "${BIN_DIR}/.." && pwd)"
WP_HEADLESS_DIR="$(cd "${STACK_DIR}/../.." && pwd)"
PROJECTS_DIR="$(cd "${WP_HEADLESS_DIR}/.." && pwd)"

MYSQL_ROOT="${MYSQL_ROOT:-/Users/matysanchez/Library/Application Support/Local/lightning-services/mysql-8.0.35+4/bin/darwin-arm64}"
PHP_BIN="${PHP_BIN:-/Users/matysanchez/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php}"
WPCLI_PHAR="${WPCLI_PHAR:-/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar}"
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-512M}"

MYSQL_BIN="${MYSQL_ROOT}/bin/mysql"
MYSQLD_BIN="${MYSQL_ROOT}/bin/mysqld"
MYSQLADMIN_BIN="${MYSQL_ROOT}/bin/mysqladmin"

RUNTIME_DIR="${STACK_DIR}/.runtime"
MYSQL_DIR="${RUNTIME_DIR}/mysql"
MYSQL_DATA_DIR="${MYSQL_DIR}/data"
MYSQL_LOG="${MYSQL_DIR}/mysql.log"
MYSQL_PID="${MYSQL_DIR}/mysql.pid"
MYSQL_SOCKET="${MYSQL_SOCKET:-/tmp/wp-three-sites-mysql.sock}"
MYSQL_PORT="${MYSQL_PORT:-33070}"

ROUTER="${BIN_DIR}/router.php"

ensure_requirements() {
	for binary in "${MYSQL_BIN}" "${MYSQLD_BIN}" "${MYSQLADMIN_BIN}" "${PHP_BIN}" "${WPCLI_PHAR}"; do
		if [ ! -e "${binary}" ]; then
			echo "Missing required dependency: ${binary}" >&2
			exit 1
		fi
	done
}

site_dir() {
	echo "${RUNTIME_DIR}/sites/$1"
}

site_url() {
	case "$1" in
		site1) echo "http://localhost:11001" ;;
		site2) echo "http://localhost:11002" ;;
		site3) echo "http://localhost:11003" ;;
		*) return 1 ;;
	esac
}

site_port() {
	case "$1" in
		site1) echo "11001" ;;
		site2) echo "11002" ;;
		site3) echo "11003" ;;
		*) return 1 ;;
	esac
}

site_title() {
	case "$1" in
		site1) echo "Artificial Poets Local One" ;;
		site2) echo "Artificial Poets Local Two" ;;
		site3) echo "Artificial Poets Local Three" ;;
		*) return 1 ;;
	esac
}

site_db() {
	case "$1" in
		site1) echo "wp_site1" ;;
		site2) echo "wp_site2" ;;
		site3) echo "wp_site3" ;;
		*) return 1 ;;
	esac
}

site_pid() {
	echo "${RUNTIME_DIR}/pids/$1.pid"
}

site_log() {
	echo "${RUNTIME_DIR}/logs/$1.log"
}

run_wp() {
	local site="$1"
	shift
	"${PHP_BIN}" -d "memory_limit=${PHP_MEMORY_LIMIT}" "${WPCLI_PHAR}" --path="$(site_dir "${site}")" "$@"
}

mysql_ready() {
	"${MYSQLADMIN_BIN}" --host=127.0.0.1 --port="${MYSQL_PORT}" --user=root ping --silent >/dev/null 2>&1
}

initialize_mysql() {
	if [ -d "${MYSQL_DATA_DIR}/mysql" ]; then
		return
	fi

	mkdir -p "${MYSQL_DIR}"
	rm -rf "${MYSQL_DATA_DIR}"
	mkdir -p "${MYSQL_DATA_DIR}"

	"${MYSQLD_BIN}" \
		--initialize-insecure \
		--basedir="${MYSQL_ROOT}" \
		--datadir="${MYSQL_DATA_DIR}" \
		--log-error="${MYSQL_LOG}"
}

start_mysql() {
	if [ -f "${MYSQL_PID}" ] && kill -0 "$(cat "${MYSQL_PID}")" >/dev/null 2>&1; then
		return
	fi

	mkdir -p "${MYSQL_DIR}"
	rm -f "${MYSQL_SOCKET}"

	"${MYSQLD_BIN}" \
		--daemonize \
		--basedir="${MYSQL_ROOT}" \
		--datadir="${MYSQL_DATA_DIR}" \
		--socket="${MYSQL_SOCKET}" \
		--port="${MYSQL_PORT}" \
		--bind-address=127.0.0.1 \
		--pid-file="${MYSQL_PID}" \
		--log-error="${MYSQL_LOG}" \
		--mysqlx=0

	printf 'Waiting for local MySQL'
	for _ in $(seq 1 60); do
		if mysql_ready; then
			printf ' OK\n'
			return
		fi
		printf '.'
		sleep 2
	done
	printf '\n'
	echo "Local MySQL did not become ready in time." >&2
	exit 1
}

configure_mysql() {
	"${MYSQL_BIN}" --host=127.0.0.1 --port="${MYSQL_PORT}" --user=root <<SQL
CREATE USER IF NOT EXISTS 'wordpress'@'localhost' IDENTIFIED BY 'wordpress';
CREATE USER IF NOT EXISTS 'wordpress'@'127.0.0.1' IDENTIFIED BY 'wordpress';
CREATE DATABASE IF NOT EXISTS wp_site1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_site2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_site3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON wp_site1.* TO 'wordpress'@'localhost';
GRANT ALL PRIVILEGES ON wp_site1.* TO 'wordpress'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wp_site2.* TO 'wordpress'@'localhost';
GRANT ALL PRIVILEGES ON wp_site2.* TO 'wordpress'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wp_site3.* TO 'wordpress'@'localhost';
GRANT ALL PRIVILEGES ON wp_site3.* TO 'wordpress'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
}

link_shared_code() {
	local site="$1"
	local dir
	dir="$(site_dir "${site}")"

	mkdir -p "${dir}/wp-content/plugins" "${dir}/wp-content/themes"
	rm -rf "${dir}/wp-content/plugins/wp-headless"
	rm -rf "${dir}/wp-content/plugins/a13s-visual-pack"
	rm -rf "${dir}/wp-content/plugins/a13s-site-pack"
	rm -rf "${dir}/wp-content/themes/wp-foundation"
	rm -rf "${dir}/wp-content/themes/a13s"

	ln -s "${PROJECTS_DIR}/wp-headless" "${dir}/wp-content/plugins/wp-headless"
	ln -s "${PROJECTS_DIR}/a13s-visual-pack" "${dir}/wp-content/plugins/a13s-visual-pack"
	ln -s "${PROJECTS_DIR}/a13s-site-pack" "${dir}/wp-content/plugins/a13s-site-pack"
	ln -s "${PROJECTS_DIR}/wp-foundation" "${dir}/wp-content/themes/wp-foundation"
	ln -s "${PROJECTS_DIR}/a13s" "${dir}/wp-content/themes/a13s"
}

ensure_wordpress_files() {
	local site="$1"
	local dir
	dir="$(site_dir "${site}")"

	mkdir -p "${dir}"

	if [ ! -f "${dir}/wp-load.php" ]; then
		run_wp "${site}" core download --force
	fi

	link_shared_code "${site}"

	if [ ! -f "${dir}/wp-config.php" ]; then
		run_wp "${site}" config create \
			--dbname="$(site_db "${site}")" \
			--dbuser="wordpress" \
			--dbpass="wordpress" \
			--dbhost="127.0.0.1:${MYSQL_PORT}" \
			--skip-check \
			--extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'FS_METHOD', 'direct' );
PHP
	fi
}

bootstrap_site() {
	local site="$1"
	local url
	local title

	url="$(site_url "${site}")"
	title="$(site_title "${site}")"

	ensure_wordpress_files "${site}"

	if ! run_wp "${site}" core is-installed >/dev/null 2>&1; then
		run_wp "${site}" core install \
			--url="${url}" \
			--title="${title}" \
			--admin_user="admin" \
			--admin_password="12341234" \
			--admin_email="admin@admin.com" \
			--skip-email
	fi

	run_wp "${site}" option update home "${url}" >/dev/null
	run_wp "${site}" option update siteurl "${url}" >/dev/null
	run_wp "${site}" option update blogname "${title}" >/dev/null
	run_wp "${site}" option update blogdescription "AI agents and infrastructure for media and publishers" >/dev/null
	run_wp "${site}" user update admin --user_email="admin@admin.com" --user_pass="12341234" --display_name="Admin" >/dev/null
	run_wp "${site}" theme activate a13s >/dev/null
	run_wp "${site}" plugin activate wp-headless a13s-visual-pack a13s-site-pack >/dev/null
	run_wp "${site}" rewrite structure '/%postname%/' --hard >/dev/null
	run_wp "${site}" option update permalink_structure '/%postname%/' >/dev/null
	run_wp "${site}" cache flush >/dev/null || true
}

start_php_server() {
	local site="$1"
	local port
	local pid_file
	local log_file
	local dir

	port="$(site_port "${site}")"
	pid_file="$(site_pid "${site}")"
	log_file="$(site_log "${site}")"
	dir="$(site_dir "${site}")"

	mkdir -p "${RUNTIME_DIR}/pids" "${RUNTIME_DIR}/logs"

	if [ -f "${pid_file}" ] && kill -0 "$(cat "${pid_file}")" >/dev/null 2>&1; then
		return
	fi

	PHP_SERVER_BIN="${PHP_BIN}" \
	PHP_SERVER_PORT="${port}" \
	PHP_SERVER_DIR="${dir}" \
	PHP_SERVER_ROUTER="${ROUTER}" \
	PHP_SERVER_LOG="${log_file}" \
	PHP_SERVER_PID="${pid_file}" \
	/usr/local/bin/python3 - <<'PY'
import os
import subprocess

cmd = [
    os.environ["PHP_SERVER_BIN"],
    "-S",
    "127.0.0.1:" + os.environ["PHP_SERVER_PORT"],
    "-t",
    os.environ["PHP_SERVER_DIR"],
    os.environ["PHP_SERVER_ROUTER"],
]

with open(os.environ["PHP_SERVER_LOG"], "ab", buffering=0) as log:
    process = subprocess.Popen(
        cmd,
        cwd=os.environ["PHP_SERVER_DIR"],
        stdin=subprocess.DEVNULL,
        stdout=log,
        stderr=subprocess.STDOUT,
        start_new_session=True,
    )

with open(os.environ["PHP_SERVER_PID"], "w", encoding="utf-8") as pid_handle:
    pid_handle.write(str(process.pid))
PY

	printf 'Waiting for %s web server' "${site}"
	for _ in $(seq 1 40); do
		if curl -fsS "http://127.0.0.1:${port}/wp-login.php" >/dev/null 2>&1; then
			printf ' OK\n'
			return
		fi
		printf '.'
		sleep 1
	done
	printf '\n'
	echo "PHP server for ${site} did not become ready in time." >&2
	exit 1
}

main() {
	ensure_requirements
	initialize_mysql
	start_mysql
	configure_mysql

	for site in site1 site2 site3; do
		bootstrap_site "${site}"
		start_php_server "${site}"
		printf '%s ready at %s\n' "${site}" "$(site_url "${site}")"
	done

	cat <<EOF

All three sites are up.

Site 1: http://localhost:11001
Site 2: http://localhost:11002
Site 3: http://localhost:11003

Login:
  Email: admin@admin.com
  Password: 12341234

Shared host symlinks inside each site:
  wp-content/plugins/wp-headless -> ${PROJECTS_DIR}/wp-headless
  wp-content/plugins/a13s-visual-pack -> ${PROJECTS_DIR}/a13s-visual-pack
  wp-content/plugins/a13s-site-pack -> ${PROJECTS_DIR}/a13s-site-pack
  wp-content/themes/wp-foundation -> ${PROJECTS_DIR}/wp-foundation
  wp-content/themes/a13s -> ${PROJECTS_DIR}/a13s
EOF
}

main "$@"
