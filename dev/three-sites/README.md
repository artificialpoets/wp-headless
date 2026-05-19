# Three Local WordPress Sites

This stack starts three fully local WordPress sites.

Preferred mode:

- Docker Compose with one MariaDB container and three WordPress containers

Fallback mode when Docker is unavailable:

- one local MySQL server
- three local PHP built-in servers
- WP-CLI bootstrap on disk

Each site:

- runs on its own localhost port
- uses the same shared `wp-headless` plugin and `wp-foundation` / `a13s` themes
- activates `wp-headless`, `a13s-visual-pack`, and `a13s-site-pack`
- uses the `a13s` child theme
- has the same admin login

## One-command bootstrap

From `wp-headless`:

```bash
./dev/three-sites/bin/up.sh
```

The wrapper prefers Docker automatically. If the Docker daemon is unavailable, it falls back to the local WP-CLI stack and keeps the same URLs and credentials.

Sites:

- `http://localhost:11001`
- `http://localhost:11002`
- `http://localhost:11003`

Login:

- email: `admin@admin.com`
- password: `12341234`

## Shared code model

The bootstrap creates symlinks inside each site container:

- `wp-content/plugins/wp-headless`
- `wp-content/plugins/a13s-visual-pack`
- `wp-content/plugins/a13s-site-pack`
- `wp-content/themes/wp-foundation`
- `wp-content/themes/a13s`

Those symlinks point to the shared workspace mounted at:

```text
/workspace/wordpress-projects
```

So changes to the plugin or themes apply to all three sites immediately.

## Management

Stop the stack:

```bash
./dev/three-sites/bin/down.sh
```

Reset everything, including databases, site volumes, and local runtime files:

```bash
./dev/three-sites/bin/reset.sh
```
