#!/usr/bin/env bash

set -euo pipefail

BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STACK_DIR="$(cd "${BIN_DIR}/.." && pwd)"

COMPOSE=(docker compose -f "${STACK_DIR}/docker-compose.yml")

site_url() {
	case "$1" in
		site1) echo "http://localhost:11001" ;;
		site2) echo "http://localhost:11002" ;;
		site3) echo "http://localhost:11003" ;;
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

cli_service() {
	case "$1" in
		site1) echo "cli-site1" ;;
		site2) echo "cli-site2" ;;
		site3) echo "cli-site3" ;;
		*) return 1 ;;
	esac
}

run_wp() {
	local site="$1"
	shift
	"${COMPOSE[@]}" run --rm "$(cli_service "${site}")" wp --allow-root "$@"
}

wait_for_db() {
	printf 'Waiting for MariaDB'
	for _ in $(seq 1 60); do
		if "${COMPOSE[@]}" exec -T db mariadb-admin ping -h 127.0.0.1 -prootpassword --silent >/dev/null 2>&1; then
			printf ' OK\n'
			return 0
		fi
		printf '.'
		sleep 2
	done
	printf '\n'
	echo "MariaDB did not become ready in time." >&2
	return 1
}

wait_for_wordpress_files() {
	local site="$1"
	printf 'Waiting for %s core files' "${site}"
	for _ in $(seq 1 60); do
		if "${COMPOSE[@]}" exec -T "${site}" bash -lc 'test -f /var/www/html/wp-config.php && test -f /var/www/html/wp-load.php' >/dev/null 2>&1; then
			printf ' OK\n'
			return 0
		fi
		printf '.'
		sleep 2
	done
	printf '\n'
	echo "WordPress files for ${site} did not become ready in time." >&2
	return 1
}

link_shared_code() {
	local site="$1"
	"${COMPOSE[@]}" exec -u 0:0 -T "${site}" bash -lc '
		set -e
		mkdir -p /var/www/html/wp-content/plugins /var/www/html/wp-content/themes
		rm -rf /var/www/html/wp-content/plugins/wp-headless
		rm -rf /var/www/html/wp-content/plugins/a13s-visual-pack
		rm -rf /var/www/html/wp-content/plugins/a13s-site-pack
		rm -rf /var/www/html/wp-content/themes/wp-foundation
		rm -rf /var/www/html/wp-content/themes/a13s
		ln -s /workspace/wordpress-projects/wp-headless /var/www/html/wp-content/plugins/wp-headless
		ln -s /workspace/wordpress-projects/a13s-visual-pack /var/www/html/wp-content/plugins/a13s-visual-pack
		ln -s /workspace/wordpress-projects/a13s-site-pack /var/www/html/wp-content/plugins/a13s-site-pack
		ln -s /workspace/wordpress-projects/wp-foundation /var/www/html/wp-content/themes/wp-foundation
		ln -s /workspace/wordpress-projects/a13s /var/www/html/wp-content/themes/a13s
	'
}

bootstrap_site() {
	local site="$1"
	local url
	local title

	url="$(site_url "${site}")"
	title="$(site_title "${site}")"

	link_shared_code "${site}"

	if ! run_wp "${site}" core is-installed >/dev/null 2>&1; then
		run_wp "${site}" core install \
			--url="${url}" \
			--title="${title}" \
			--admin_user="admin" \
			--admin_password="12341234" \
			--admin_email="admin@admin.com" \
			--skip-email
	fi

	run_wp "${site}" option update blogname "${title}" >/dev/null
	run_wp "${site}" option update blogdescription "AI agents and infrastructure for media and publishers" >/dev/null
	run_wp "${site}" user update admin --user_email="admin@admin.com" --user_pass="12341234" --display_name="Admin" >/dev/null
	run_wp "${site}" theme activate a13s >/dev/null
	run_wp "${site}" plugin activate wp-headless a13s-visual-pack a13s-site-pack >/dev/null
	run_wp "${site}" rewrite structure '/%postname%/' --hard >/dev/null
	run_wp "${site}" option update permalink_structure '/%postname%/' >/dev/null
	run_wp "${site}" cache flush >/dev/null || true

	printf '%s ready at %s\n' "${site}" "${url}"
}

main() {
	"${COMPOSE[@]}" up -d db site1 site2 site3

	wait_for_db

	for site in site1 site2 site3; do
		wait_for_wordpress_files "${site}"
		bootstrap_site "${site}"
	done

	cat <<EOF

All three sites are up.

Site 1: http://localhost:11001
Site 2: http://localhost:11002
Site 3: http://localhost:11003

Login:
  Email: admin@admin.com
  Password: 12341234

Shared symlink targets inside each container:
  wp-content/plugins/wp-headless -> /workspace/wordpress-projects/wp-headless
  wp-content/plugins/a13s-visual-pack -> /workspace/wordpress-projects/a13s-visual-pack
  wp-content/plugins/a13s-site-pack -> /workspace/wordpress-projects/a13s-site-pack
  wp-content/themes/wp-foundation -> /workspace/wordpress-projects/wp-foundation
  wp-content/themes/a13s -> /workspace/wordpress-projects/a13s
EOF
}

main "$@"
