# WP Headless — Hook API Reference

Every hook uses the `wp_headless_` prefix. **Hook names, signatures, and the
documented array shapes on this page are public API.** During 0.x, a breaking
change to any of them bumps the minor version and is called out in the
CHANGELOG under "Breaking"; from 1.0.0 they only change in major versions.

Two shapes are explicitly frozen:

- `wp_headless_seo_meta` — `array( 'description', 'canonical', 'og', 'twitter', 'jsonld' )`.
  The `jsonld` value carries a schema.org document whose *internal* structure
  may evolve (since 0.2.0 it is a `@graph`); the array key itself is stable.
- `wp_headless_modules` — keyed `array<string, Module>`; keys are stable
  module identities (see [Modules](#modules--boot)).

## Modules & boot

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_modules` | filter | `array<string, Module> $modules, Config $config, ThemeManager $tm → array` | Once, on `after_setup_theme@100`, before any module boots (theme functions.php is already included — themes can hook it too). Add your own `Contracts\Module` implementations, or replace/remove built-ins by key. |
| `wp_headless_booted` | action | `Plugin $plugin` | Once, after every enabled module registered. |

Built-in module keys: `rewrite_rules`, `nav_menus`, `block_annotator`,
`asset_proxy`, `frontend_bridge`, `cache`, `prerender`, `data_export`,
`static_publish`, `seo_head`, `content_fields`, `comments`, `menu_endpoint`,
`runtime_endpoint`, `resolve_endpoint`, `cors`, `head_cleanup`, `llms_txt`,
`robots_txt`, `settings_page`.

Any module can be disabled per site via config — no code required:

```php
// wp-content/headless/wp-headless.config.php
return array(
	'modules' => array(
		'llms_txt' => array( 'enabled' => false ),
	),
);
```

Add-on plugins register modules through the filter (all plugins load before
`plugins_loaded`, so ordering never matters):

```php
add_filter( 'wp_headless_modules', function ( array $modules, $config ) {
	$modules['my_feature'] = new My_Feature_Module( $config );
	return $modules;
}, 10, 2 );
```

Themes work too: theme `functions.php` is included *before*
`after_setup_theme@100` fires, so a theme can register the same filter at
include time (this is also why theme `wp_headless_config` filters apply).
Code that runs even later can still join via `add_module()`, which
registers immediately once the plugin has booted:

```php
add_action( 'after_setup_theme', function () {
	if ( function_exists( 'wp_headless_plugin' ) ) {
		wp_headless_plugin()->add_module( 'my_theme_feature', new My_Theme_Module() );
	}
}, 20 );
```

## Config & serving

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_config` | filter | `array $config → array` | After defaults + env constants + project file merge. |
| `wp_headless_should_serve_frontend` | filter | `bool $serve, Config $config → bool` | Every public request on `template_redirect@0`, after built-in exemptions. |
| `wp_headless_runtime_data` | filter | `array $data, Config $config, ?string $url → array` | After the `window.WP_HEADLESS` payload is assembled. |
| `wp_headless_theme_data` | filter | `array $theme_data → array` | While building the runtime's `theme` key. |
| `wp_headless_resolve_url` | filter | `array $response, string $url → array` | Every URL resolve (served shell + `/resolve` endpoint). |
| `wp_headless_document_html` | filter | `string $html, array $runtime, Config $config → string` | After all HTML rewrites, before output. |

## SEO & schema

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_output_seo_meta` | filter | `bool → bool` | `wp_head@4`; master on/off for the SEO block. |
| `wp_headless_seo_meta` | filter | `array $meta → array` | After `build_meta()`, before render. Shape frozen (see top). |
| `wp_headless_seo_plugin_active` | filter | `bool → bool` | SEO-plugin standdown detection (Yoast, Rank Math, AIOSEO, SEOPress, TSF). |
| `wp_headless_schema_pieces` | filter | `array<string, ?array> $pieces, array $context → array` | Per request, before per-piece filtering. Add (`$pieces['faq'] = …`) or remove graph nodes. |
| `wp_headless_schema_piece` | filter | `?array $node, string $key, array $context → ?array` | Per node (built-ins AND filter-added). Return `null` to drop. |
| `wp_headless_schema_graph` | filter | `array $graph, array $context → array` | The final `{'@context','@graph'}` document. |

The `$context` array carries `kind` (`front_page`, `home`, `singular`,
`term_archive`, `author_archive`, `post_type_archive`, `date_archive`),
`canonical`, `post`/`term`/`user` objects where applicable, `site_url`, and
`ids` — the precomputed stable `@id` anchors (`organization`, `website`,
`webpage`, `breadcrumb`, `primary_image`, `author`) so contributed nodes can
cross-reference built-ins without recomputing them.

```php
// Theme example: contribute an FAQPage node built from block attributes.
add_filter( 'wp_headless_schema_pieces', function ( array $pieces, array $context ) {
	if ( 'singular' !== $context['kind'] ) {
		return $pieces;
	}
	$pieces['faq'] = my_theme_build_faq_node( $context );
	return $pieces;
}, 10, 2 );
```

## llms.txt

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_llms_txt_data` | filter | `array $data, Config $config → array` | After the structured document is assembled (`title`, `summary`, `description`, `sections`, `optional`). |
| `wp_headless_llms_txt_output` | filter | `string $output, array $data → string` | Final plain-text body of `/llms.txt` (and `/llms-full.txt`). |

## robots.txt & head hygiene

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_ai_crawlers` | filter | `array<int, string> $agents → array` | Default AI-crawler agent list used when `seo.robots_txt.ai_policy` is `block`. |
| `wp_headless_head_cleanup` | filter | `array<string, bool> $removals, Config $config → array` | Removal map before head-cleanup executes (keys: `rsd`, `wlw`, `generator`, `shortlink`, `adjacent_posts`, `emoji`, `oembed_host_js`, `oembed_discovery`, `feed_links_extra`, `rest_link`). |

## Blocks, REST & comments

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_annotate_blocks` | filter | `bool $annotate, array $block → bool` | `render_block@100`, per block. |
| `wp_headless_rest_post_types` | filter | `array $types, Config $config → array` | `rest_api_init`; which post types get REST enrichment. |
| `wp_headless_rest_fields` | filter | `array $fields, array $types, Config $config → array` | Before `register_rest_field` runs. |
| `wp_headless_menu_item` | filter | `array $item, int $id → array` | Per node while building menu trees (classic menus and `wp_navigation`). |
| `wp_headless_allow_anonymous_comments` | filter | `bool $allow → bool` | On `rest_allow_anonymous_comments`. |

## Pre-render

The Prerender module stores per-post first-paint HTML and injects it as
`<div id="wp-headless-prerender">…</div>` immediately before `#root`
(`wp_headless_document_html@10`). Contract: theme-side fallback shells
hook the same filter at priority 20 and skip when that container is
already present; the frontend removes the container when it commits its
own chrome. Themes generate the markup (typically their own SSR bundle
via `wp headless prerender`) — the plugin stores (`Prerender::store()`),
serves, and invalidates (`Prerender::invalidate()`, `Prerender::flush()`).
Saving or deleting a post invalidates its own pre-render; saving or
deleting a reusable block (`wp_block`) also invalidates every published
post embedding it through `wp:block {"ref":N}` markers, nested patterns
included — each embedder fires `wp_headless_prerender_invalidated`
individually, so per-path CDN purges keep working.

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_prerender_invalidated` | action | `int\|null $post_id` | After a stored pre-render is invalidated (`null` = full flush). Hosts queue regeneration or CDN purges here. |

Config: `modules.prerender.enabled`, `modules.prerender.post_types`
(default `['page']`), `modules.prerender.command` (renderer shell
template; tokens `{renderer}`, `{theme}`, `{base}`, `{routes}`, `{out}`),
`modules.prerender.auto_regenerate` (default `true`: invalidations queue
a debounced `wp_headless_prerender_regenerate` cron event that reruns
the renderer — disable when a host worker consumes the invalidated
action instead), `modules.prerender.node_bin` (absolute Node binary for
web-context cron runs; auto-detected from common install paths
otherwise).

## Caching

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_cache_headers` | filter | `array\|null $headers, array $context, Config $config → array\|null` | While serving the document shell (`template_redirect@0`), after the body is rendered. `null` means undecided — the bridge falls back to `nocache_headers()` (the pre-0.3 behavior, and the behavior when the `cache` module is disabled); an empty `array()` emits nothing (defer to headers already sent during render); a map is emitted verbatim. `$context` carries `is_404`. Hosts adjust or replace the policy here. |
| `wp_headless_runtime_cache_invalidated` | action | `string $reason` | After the cached runtime payload is invalidated (menu/Customizer/site-identity/plugin changes). The payload is embedded in every served document — hosts hook this to purge their CDN/edge caches of the shell. |

Config: `modules.cache.enabled` (default `true`), `modules.cache.max_age`
(default `60`, clamped to 3600), `modules.cache.s_maxage` (default `300`,
clamped to 21600 — half the 12-hour REST-nonce tick: a public copy must never
outlive its embedded nonce's validity), `modules.cache.stale_while_revalidate`
(default `3600`), `modules.cache.not_found_s_maxage` (default `60`),
`modules.cache.payload` (default `true` — cache the static payload subset),
`modules.cache.payload_ttl` (default `900`), `modules.cache.payload_keys`
(default `[]` = full payload; a non-empty list prunes `menus`, `urls`,
`postTypes`, `discussion`, `customCss`, `theme` down to those listed —
`site`, `rest`, `frontend`, `user`, `request` always survive. The bundled
starter themes read the full payload; prune only when your theme knows its
reads). The policy automatically stands down to `private, no-store` for
logged-in users, WP identity cookies, and sites where a plugin makes
anonymous nonces per-visitor by filtering `nonce_user_logged_out`
(e.g. WooCommerce).

REST reads (0.3.1): `modules.cache.rest` (default `true`) applies the same
policy to anonymous GETs on allowlisted REST routes via `rest_post_dispatch` —
default allowlist is the plugin's own `/runtime`, `/resolve`, `/menus`;
extend with `modules.cache.rest_routes` (exact-match route paths; per-user,
by-id, and templated routes are structurally refused). Tunables:
`modules.cache.rest_max_age` (`0`), `modules.cache.rest_s_maxage` (`300`),
`modules.cache.rest_stale_while_revalidate` (`600`).

## Data export

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_data_exported` | action | `string $path, string $json, array $context` | After a route's data artifact is built (publish/update, or `wp headless export`). Hosts persist `$json` wherever their edge serves it (S3, filesystem, KV) under the `$path` key (`/` for the front page, `/about-us` style otherwise). `$context` carries `post_id`, `post_type`. |
| `wp_headless_data_deleted` | action | `string $path, array $context` | When a route's artifact must be removed (unpublish, delete). |

Config: `modules.data_export.enabled` (default `true`),
`modules.data_export.post_types` (default `[]` = every REST-exposed public
type). The artifact envelope is `{version, generated, path, request, content}`
— `request` mirrors the runtime's resolved request shape and `content` is the
INTERNAL `wp/v2` collection dispatch for the route's `?slug=` query, so it is
shape-identical to the live API (registered rest fields and filters included):
a frontend consuming `/wp/v2/{base}?slug=x` today can consume `content`
without a parser change. Exports run at editor time (publish transitions),
never on the visitor path. Backfill: `wp headless export [--post=<id>]`.

## Static publishing

The `static_publish` module writes the public site to a storage target, so a
host can serve it from object storage or a plain directory while WordPress
sleeps. It is **opt-in per site and off by default**: it runs only when the
`wp_headless_static_publish` option is `on` (or the
`WP_HEADLESS_STATIC_PUBLISH` constant is `true`) **and** a target is
configured. A configured target alone never turns it on. The constant, when
defined, wins over the option; `false` keeps a site off.

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_static_storage` | filter | `?Storage $storage, Config $config → ?Storage` | Whenever the publisher resolves its target (cron run, CLI). Return any `WPHeadless\StaticPublish\Storage` implementation to write elsewhere (R2, GCS, KV, your own S3 client); `null` keeps the publisher off. |
| `wp_headless_static_s3_client` | filter | `?object $client, array $settings → ?object` | While building the `s3` target. Return an object with the AWS SDK's `putObject(array)` and `deleteObject(array)`. Without one, a client is built only when the AWS SDK for PHP is already loaded (default credential chain); otherwise the target stays off. |
| `wp_headless_static_paths` | filter | `array<int,string> $paths, array $routes → array` | Each run, before the non-singular paths are read (archives with pagination, `robots.txt`, `llms.txt`, `llms-full.txt`, `wp-sitemap.xml` and the sitemaps it lists, the sitemap stylesheets, `/feed/`). Add routes only a plugin knows about. |
| `wp_headless_static_redirects` | filter | `array<string,string> $map → array` | Each run, while building `__data/__redirects.json` (path with leading and trailing slash → absolute URL). Built in: the static front page's own path and renamed posts' old slugs. Redirect plugins add their rules here. |
| `wp_headless_static_published` | action | `array $stats, Storage $storage` | After a successful run. Hosts purge their CDN here — one wildcard invalidation is usually cheapest. |
| `wp_headless_static_publish_failed` | action | `\Throwable $error` | After a run that threw is recorded. The site stays dirty; the next change or a CLI run retries. Hosts alert here. |

Config, under `modules.static_publish`:

| Key | Default | Meaning |
|-----|---------|---------|
| `target` | `''` | `local`, `s3`, or empty (no target unless the storage filter supplies one). |
| `path` | `''` | Local target: absolute directory. |
| `s3` | `[]` | `bucket`, `prefix`, `region` (`us-east-1`), `endpoint` (S3-compatible stores), `path_style`. No credentials. |
| `origin` | `''` | Base URL to read pages from, e.g. `http://127.0.0.1:8080`, sent with the public `Host` (and `X-Forwarded-Proto: https`). Empty reads the public URL — set it when the public host is a CDN. |
| `timeout` | `30` | Seconds per read before it counts as failed. |
| `delay` | `60` | Seconds between a change and its cron publish; later changes join it. |
| `document_cache` | `public, max-age=60, s-maxage=300, stale-while-revalidate=3600` | Cache-Control for a stored copy whose origin answer was not public. |
| `assets` | `true` | Mirror the theme build to the asset mount. Skipped when `frontend.asset_base_url` is set. |

Storage layout (keys are relative to the target):

| Key | Content |
|-----|---------|
| `index.html`, `<path>/index.html` | Every `publish` singular without a password, and the archives with their pagination. A path with a file extension is its own key (`robots.txt`). |
| `__data/routes/<path>/index.json` | The route's data-export envelope (`/` → `__data/routes/index.json`). |
| `<asset mount>/...` | The theme build (`{theme}/dist/`), proxy content types and cache policy. Never swept. |
| `__data/__status.json` | `{v, running, started, published, nextScheduled}`; after a failure `failedAt` and `error` (the exception's short class name). Public: never messages, paths or counts. `nextScheduled` is when WordPress must be awake to publish a scheduled post. |
| `__data/__redirects.json` | The redirect map. |
| `__data/__shell/404.html` | The site's 404 page. |

A stored copy follows these rules:

- Each page is read as an anonymous browser: no cookies, no redirects
  followed, Chrome's navigation `Accept` header. An `Accept` naming JSON
  makes `wp_is_json_request()` true, and wp-headless would stand down.
- A 200 is stored. A body, content type and cache policy whose hash matches
  the last write to this target is not rewritten (`--force` rewrites all).
  The publisher's reads get an empty REST nonce in the runtime payload, so a
  copy never carries an expiring nonce and stays byte-stable across runs.
- A timeout, 408, 429 or 5xx keeps the last good copy. A 404, 410, redirect
  or other status removes it, and so does a post that is no longer a
  published, password-less post. A run that reads no published post sweeps
  nothing.
- One run at a time: a second run exits (the CLI non-zero) and leaves the
  change queued for the running one, which goes round again. A run that
  throws records the error class, keeps the site dirty and rethrows.

An edge in front of the storage answers a request by trying the document
key for its path. On a miss it forwards to WordPress while WordPress is
awake. While it sleeps, it answers a path in `__data/__redirects.json` with a
301 and anything else with `__data/__shell/404.html` and a 404 status.

CLI: `wp headless static publish [--force]` and `wp headless static status`.

