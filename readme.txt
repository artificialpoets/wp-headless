=== WP Headless ===
Contributors: artificialpoets
Tags: headless, react, rest-api, spa, decoupled
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any properly-built theme into a React (or Vue, or vanilla JS) headless frontend without giving up WordPress core.

== Description ==

WP Headless lets a WordPress theme ship a built React app in `dist/` and serve it as the entire public frontend. WordPress stays itself: posts, pages, menus, the block editor, comments, users, multisite, plugins all keep working. The frontend is yours.

When the active theme has a `dist/index.html`, the plugin engages headless mode automatically — intercepting every public request, serving the SPA, proxying its built assets, and injecting a full runtime payload as `window.WP_HEADLESS`. When you switch to a classic or block theme, the plugin stands down and WordPress renders normally.

= What's in the runtime =

A single object on every page exposes everything a React app needs to render the right template:

* Site identity (name, description, URL, language, RTL flag, timezone, favicon, logo)
* REST roots, namespace, and a fresh nonce for authenticated requests
* The current request resolved into `is_front_page`, `is_singular`, `is_archive`, `is_author`, `is_date`, `is_post_type_archive`, `is_attachment`, `is_search`, `is_preview`, `is_auth`, `is_404` — plus the queried object (post, term, user, or post type)
* All registered nav menu locations
* The logged-in user with capabilities, or `null` when anonymous
* Public auth URLs (`login`, `logout`, `register`, `lost_password`, `admin`, `profile`)
* Registered public post types with their REST bases and archive links
* Discussion (comments) settings: threading, depth, paging, per-page count, default page, order
* Customizer Additional CSS, ready to inject

= REST endpoints =

* `GET /wp-json/wp-headless/v1/runtime` — full runtime payload
* `GET /wp-json/wp-headless/v1/resolve?url={URL}` — resolve any URL to a request shape (used for client-side navigation)
* `GET /wp-json/wp-headless/v1/menus?location={slug}` — recursive menu tree

REST enrichments are also added to posts/pages: `featured_image` (with srcset, sizes, width, height, mime), `author_info` (link, description, URL), `comment_count`, `adjacent` (previous/next post), and a resolved `permalink`.

= URL resolution =

The resolver recognises every URL kind WordPress can route to:

* Front page (static or posts)
* Posts page
* Single posts, pages, custom post types
* Attachments (by id or pretty URL)
* Term archives — category, tag, custom taxonomies
* Author archives
* Date archives — year, month, day
* Custom post type archives
* Paged archives — both `/page/N/` and `?paged=N`
* Search (`?s=...`)
* Post preview (anonymous + signed `preview_id` + `preview_nonce`)
* The conventional auth paths: `/login/`, `/register/`, `/lost-password/`

= Activation flow =

Headless mode follows Appearance → Themes — there's no separate activation UI. Any theme that ships a built React app at `dist/index.html` becomes a headless theme; any theme that doesn't, doesn't. A child theme without its own build falls back to the parent's `dist/`.

Settings → WP Headless shows current status (engaged or paused, active theme, dist path, asset mount, REST endpoints) but is informational only.

= Starter themes =

The plugin's GitHub repository ships two starter themes — `themes/js` (React + Vite + CSS Modules) and `themes/ts` (the same, with strict-mode TypeScript). Both are real WordPress themes you can install via Appearance → Themes. They implement the full WP template hierarchy plus auth flows, threaded + paginated comments, breadcrumbs, sticky-post highlighting, post-format styling, password-protected post gating, an admin bar for logged-in users with edit-this-post links, and full RTL via CSS logical properties.

== Installation ==

1. Upload the `wp-headless` folder to `/wp-content/plugins/`, or install from the plugin directory.
2. Activate via Plugins → Installed Plugins.
3. Install a headless theme — either build your own (any theme with `{theme-dir}/dist/index.html` works) or copy one of the bundled starters into `wp-content/themes/`.
4. Activate the theme via Appearance → Themes.

The plugin requires no configuration. CORS, asset mount, and inject behaviour can all be filtered via the `wp_headless_config` hook if you need to override defaults.

== Frequently Asked Questions ==

= Does this require a separate frontend server (Next.js, Nuxt, etc.)? =

No. The React app is served by WordPress itself — the plugin reads `dist/index.html` from the active theme, injects the runtime, rewrites asset URLs to be proxied through `/_wp-headless/...`, and returns it. No Node runtime needed in production.

= How does this differ from existing headless setups? =

Most headless WordPress setups assume a separate frontend host. WP Headless keeps the frontend on the same origin as WordPress, which means:

* No CORS issues for nonce-authenticated requests
* No second hosting tier to manage
* `wp_head()` and `wp_footer()` continue to fire — plugins that print scripts/styles still work
* The plugin can stand down for non-public URLs (admin, feeds, REST, sitemap, robots, embeds) and let WordPress handle them

= Can I use it with Yoast SEO / WP Rocket / WooCommerce / [plugin X]? =

The plugin doesn't touch the admin or the REST API namespaces those plugins extend. SEO meta is injected by the theme itself via the `useHead` hook (or your own). Caching plugins work because the served HTML is a static `index.html` plus a runtime payload — both cacheable.

WooCommerce is not specifically supported in the starter themes — the React app would need additional templates for the shop, cart, and checkout. The plugin's REST and resolver layers don't get in WooCommerce's way.

= Does it work with custom post types? =

Yes — any CPT registered with `'show_in_rest' => true` and `'has_archive' => true` is automatically recognised. The plugin resolves `/{cpt-slug}/` as a `post_type_archive`, lists the CPT in `runtime.postTypes`, and singular CPT URLs work via WP's `url_to_postid()`.

= Logged-in users? =

When the visitor is authenticated, the runtime exposes the user (display name, roles, capabilities) and admin-only fields like the site admin email. The React app can use this to show "Edit this post" links, an admin bar, draft previews, etc. The starter themes include all of this.

= Multisite? =

Works per-site. Network activation works; each site's runtime reflects its own theme + content.

= How do I switch headless mode off? =

Activate any non-headless theme via Appearance → Themes. The plugin will stand down on the next request and WordPress will serve its own theme.

= Is the data in `window.WP_HEADLESS` cached? =

It's rebuilt on every request because it includes a fresh REST nonce, the resolved current request, and the logged-in user. Stripping the dynamic bits and caching the static portion is on the roadmap.

== Screenshots ==

1. Settings → WP Headless dashboard showing engaged status, active theme, and REST endpoints
2. The starter theme rendering a single post with breadcrumbs, featured image, threaded comments, and post navigation
3. The admin bar for logged-in users
4. The login page rendered by the theme

== Changelog ==

= 1.0.0 — 2026-05-18 =

First public release.

* Active-theme detection — headless mode follows Appearance → Themes.
* Asset proxy with immutable cache headers for hashed filenames.
* Full runtime payload — site, REST roots + nonce, request context, menus, post types, discussion settings, custom CSS, auth URLs, current user.
* URL resolver for every WP URL kind: front page, posts archive, posts page, post / page / CPT singles, attachment, term archives, author, date, CPT archives, paged archives, search, preview, auth pages.
* Recursive menu API.
* REST enrichments on posts/pages: featured_image (with srcset/sizes/width/height), author_info, comment_count, adjacent, permalink.
* CORS with configurable origins.
* Two starter themes shipped in the repo: React+JS and React+TypeScript, each implementing the full WP template hierarchy plus auth, threaded comments, admin bar, RTL, and Customizer Additional CSS injection.
* PHPUnit test suite (96 tests).
* Translation template (`languages/wp-headless.pot`).

== Upgrade Notice ==

= 1.0.0 =

First public release. Pre-1.0 installs are cleaned up on activation: the legacy `wp_headless_active_theme` option (used by the removed custom activation UI) is deleted. Switch the active theme through Appearance → Themes instead.
