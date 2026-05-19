# WP Headless

A WordPress plugin that turns any properly-built theme into a headless React (or Vue, or vanilla JS) frontend. Activate a theme that ships a built React app in `dist/`, and `wp-headless` takes over the public frontend — serving the SPA, proxying its assets, injecting a complete runtime payload, and providing REST primitives for everything WordPress can route to.

WordPress stays itself: posts, pages, menus, the block editor, comments, users, multisite, plugins. The frontend is just yours.

## Requirements

- PHP 7.4 or later
- WordPress 6.0 or later

## Updates

Pre-wp.org (current channel): updates ship via GitHub Releases on [artificialpoets/wp-headless](https://github.com/artificialpoets/wp-headless/releases). The plugin bundles [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker), which hooks into WordPress's standard update API. New releases appear in **Dashboard → Updates** within ~12 hours of being published, exactly like wp.org plugins.

Auto-updates are **opt-in by default**: the plugin enables the `auto_update_plugin` filter for its own slug, so each site silently updates on the next WP cron run. To opt a site out:

```php
add_filter( 'auto_update_plugin', function ( $update, $item ) {
    if ( isset( $item->slug ) && $item->slug === 'wp-headless' ) {
        return false; // keep manual control
    }
    return $update;
}, 20, 2 );
```

When the plugin is published to wp.org (v1.0.0 onward), the update channel will move there and this section will be removed.

## Quick start

1. **Install the plugin.** Two options:
   - **Production (recommended):** download the latest `wp-headless.zip` from the [Releases page](https://github.com/artificialpoets/wp-headless/releases) and upload it through Plugins → Add New → Upload Plugin, or
   - **Development:** clone the repo into `wp-content/plugins/wp-headless/` and activate via Plugins → Installed Plugins.
2. **Install a headless theme.** Either:
   - Use one of the bundled starters in `themes/js` or `themes/ts` (real WordPress themes you can copy into `wp-content/themes/`), or
   - Build your own — any theme with a readable `dist/index.html` becomes a headless theme.
3. **Activate the theme.** Appearance → Themes → activate. The plugin detects `{theme}/dist/index.html` and switches into headless mode.
4. **Visit the site.** The plugin intercepts every public request, serves `dist/index.html` with `window.WP_HEADLESS` injected before `</head>`, and proxies built assets through `/_wp-headless/...`.

To switch headless mode off, activate any non-headless theme. The plugin stands down automatically.

## What's in the box

- **Active-theme detection.** Headless mode follows Appearance → Themes — no separate activation UI.
- **Asset proxy.** Built JS/CSS are served from the active theme's `dist/assets/` via `/_wp-headless/assets/...` with proper cache headers (immutable for hashed filenames).
- **Runtime payload.** A single `window.WP_HEADLESS` object exposes site, REST roots, nonce, current request context, menus, registered post types, discussion settings, custom CSS, auth URLs, and the logged-in user when present.
- **URL resolution.** Resolves any URL on the site to a request shape the React app can dispatch on: posts, pages, CPT singles and archives, attachments, term archives (category, tag, custom taxonomies), author archives, date archives (year/month/day), paged archives (`/page/N/` and `?paged=N`), search, previews, and auth pages.
- **Menu API.** `/wp-headless/v1/menus?location=primary` returns a tree with `children`, not the flat list of `/wp/v2/menus`.
- **REST enrichments.** Custom fields added to posts/pages: `featured_image` (with srcset/sizes/width/height), `author_info` (with link/description), `comment_count`, `adjacent` (previous/next post), and `permalink`.
- **CORS.** Configurable allowed origins with proper preflight handling.
- **Nav menu locations.** Registers `primary` and `footer` so themes that delegate to the plugin get menu locations out of the box.

## REST endpoints

The plugin registers three endpoints under `/wp-json/wp-headless/v1/`:

### `GET /runtime`

Full runtime payload — the same object injected into the page on every request.

```json
{
  "site": { "name": "...", "description": "...", "url": "...", "language": "en_US", "textDirection": "ltr", "timezone": "+00:00", "favicon": null, "logo": null, "admin_email": null },
  "rest": { "root": "...", "wpV2": "...", "headless": "...", "namespace": "wp-headless/v1", "nonce": "..." },
  "frontend": { "assetBaseUrl": "...", "assetMount": "/_wp-headless", "hasFrontendBuild": true },
  "menus": { "locations": { "primary": { "id": 12, "name": "Main", "slug": "main" } } },
  "urls": { "login": "...", "logout": "...", "register": "...", "lost_password": "...", "admin": "...", "profile": "...", "registration_enabled": false },
  "user": null,
  "postTypes": [ { "name": "post", "rest_base": "posts", "has_archive": true, "archive_link": "...", ... } ],
  "discussion": { "thread_comments": true, "comments_per_page": 50, "page_comments": false, ... },
  "customCss": "...",
  "request": { "kind": "front_page", "is_front_page": true, "queried_object": null, ... }
}
```

When the visitor is authenticated, `user` is populated:

```json
"user": {
  "id": 1,
  "display_name": "admin",
  "username": "admin",
  "roles": ["administrator"],
  "avatar": "...",
  "capabilities": { "edit_posts": true, "manage_options": true, ... }
}
```

### `GET /resolve?url={URL}`

Resolve any URL to a `request` shape — used by the React app on client-side navigation.

```json
{
  "url": "http://example.com/category/news/page/2/",
  "path": "/category/news/",
  "kind": "term_archive",
  "is_archive": true,
  "queried_object": { "kind": "term", "taxonomy": "category", "id": 5, "name": "News", "link": "...", "count": 12 },
  "page": 2
}
```

Recognised kinds: `front_page`, `post`, `posts_archive`, `posts_page`, `term_archive`, `author_archive`, `date_archive`, `post_type_archive`, `attachment`, `search`, `post_preview`, `login`, `register`, `lost_password`, `unresolved`.

### `GET /menus?location={slug}` (or `?id=N` or `?slug=...`)

Returns a menu with a recursive `items` tree, each item carrying `children`.

```json
{
  "id": 12,
  "name": "Main",
  "slug": "main",
  "items": [
    { "id": 34, "title": "Home", "url": "...", "target": "", "classes": [], "children": [] },
    { "id": 35, "title": "About", "url": "...", "target": "", "classes": [], "children": [
      { "id": 36, "title": "Team", "url": "...", ... }
    ]}
  ]
}
```

## Extensibility hooks

All hooks use the `wp_headless_*` prefix.

| Hook | Type | Parameters | Return | When |
|------|------|------------|--------|------|
| `wp_headless_config` | filter | `array $config` | `array` | After config sources are merged |
| `wp_headless_should_serve_frontend` | filter | `bool, Config` | `bool` | Every public request, before interception |
| `wp_headless_runtime_data` | filter | `array, Config, ?string $url` | `array` | After the runtime payload is built |
| `wp_headless_document_html` | filter | `string, array $runtime, Config` | `string` | After all HTML rewrites/injections |
| `wp_headless_rest_post_types` | filter | `array, Config` | `array` | When REST fields are registered |
| `wp_headless_rest_fields` | filter | `array, array $types, Config` | `array` | Before each REST field is registered |
| `wp_headless_menu_item` | filter | `array $item, int $id` | `array` | For each item while building the menu tree |

### Usage examples

```php
// Add a custom key to the runtime payload
add_filter( 'wp_headless_runtime_data', function ( array $data ): array {
    $data['theme'] = array(
        'primaryColor' => get_option( 'my_primary_color', '#000' ),
    );
    return $data;
} );

// Exclude a path from interception (e.g. a separate React route)
add_filter( 'wp_headless_should_serve_frontend', function ( bool $should ): bool {
    if ( false !== strpos( $_SERVER['REQUEST_URI'] ?? '', '/app-preview/' ) ) {
        return false;
    }
    return $should;
} );

// Enrich a CPT with the standard REST fields
add_filter( 'wp_headless_rest_post_types', function ( array $types ): array {
    $types[] = 'case-study';
    return $types;
} );
```

## Starter themes

Two starter themes ship in the `themes/` directory of this repo. They are real WordPress themes — copy them into `wp-content/themes/` and activate like any other theme.

| Starter | Stack |
|---------|-------|
| `themes/js` | React + Vite + CSS Modules (JavaScript) |
| `themes/ts` | React + Vite + CSS Modules + TypeScript (strict) |

Both implement the full WP template hierarchy:

- `front-page`, `index`, `single`, `page`, `archive` (category/tag/custom-taxonomy), `author`, `date`, `attachment`, `cpt-archive`, `search`, `404`
- `login`, `register`, `lost-password` (post to `wp-login.php`)
- A `Comments` component with threading + pagination
- A `Breadcrumbs` component, sticky-post highlighting, post-format styling, password-protected post gating
- An `AdminBar` for logged-in users with edit-this-post / new-post / profile / logout links
- A `useAuth` hook reading `runtime.user` for capability-aware UI
- A `useHead` hook managing `<title>`, canonical, Open Graph, Twitter Card, and RSS feed `<link>` tags
- Customizer Custom CSS injection at app mount
- Full RTL via CSS logical properties

Each starter has its own README in its directory.

## How activation works

The plugin enters headless mode whenever the **active WordPress theme has a readable `dist/index.html`**.

- When you activate one of the headless starters, `dist/` already contains a build → headless mode engages immediately.
- When you switch to `twentytwentyfive` or any other classic/block theme, `dist/index.html` doesn't exist → the plugin stands down. WordPress serves its own theme.
- When you build your own theme, just make sure your build output ends up at `{theme}/dist/index.html`.

A child theme without its own `dist/` falls back to the parent theme's build.

Settings → WP Headless shows the current status (engaged or paused, which theme, dist path, REST endpoints) but does not control activation — that lives in Appearance → Themes where you'd expect it.

## Configuration

The plugin works with zero configuration. For projects that need to override defaults, define `WP_HEADLESS_PROJECT_DIR` or pass a `wp-headless-config.php` file (legacy mechanism for sites that built before themes-as-themes).

CORS origins, asset mount path, and inject toggles are all filterable via `wp_headless_config`.

## Development

```bash
git clone https://github.com/artificialpoets/wp-headless wp-headless
cd wp-headless

# PHP side
composer install
./vendor/bin/phpunit       # 96 tests, runs in <1s with Brain Monkey

# A starter (substitute themes/ts for the typed flavor)
cd themes/js
npm install
npm run dev                # local Vite server with mock runtime
npm run build              # writes dist/
```

To exercise the plugin end-to-end, symlink it into a local WordPress install:

```bash
ln -s "$(pwd)" /path/to/wp/wp-content/plugins/wp-headless
ln -s "$(pwd)/themes/js" /path/to/wp/wp-content/themes/wp-headless-starter-js
wp plugin activate wp-headless
wp theme activate wp-headless-starter-js
```

## Compatibility

| | Supported |
|---|---|
| WordPress core templates | post, page, attachment, all CPTs |
| Taxonomies | category, tag, all public custom taxonomies |
| Archives | term, author, date (Y/M/D), CPT, paged |
| Comments | threaded, paginated |
| Sticky posts | yes — surfaced via `runtime.request` and the API filter |
| Post formats | aside, image, video, link, quote, status, gallery, chat, audio |
| Password-protected posts | yes — gated via the standard `wp-login.php?action=postpass` flow |
| Drafts / preview | yes — `?preview=true&p=N` and signed `preview_id` + `preview_nonce` |
| RTL | yes — themes use CSS logical properties |
| i18n | text domains `wp-headless`, `wp-headless-starter-js`, `wp-headless-starter-ts` |
| Multisite | works per-site |
| Block library CSS | injected into the React app at mount |
| Customizer Additional CSS | injected at app mount, overrides theme styles |

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Copyright (C) 2024–2025 Artificial Poets.
