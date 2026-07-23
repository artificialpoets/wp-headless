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
`asset_proxy`, `frontend_bridge`, `seo_head`, `content_fields`, `comments`,
`menu_endpoint`, `runtime_endpoint`, `resolve_endpoint`, `cors`,
`head_cleanup`, `llms_txt`, `robots_txt`, `settings_page`.

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

| Hook | Type | Signature | Fires |
|------|------|-----------|-------|
| `wp_headless_prerender_invalidated` | action | `int\|null $post_id` | After a stored pre-render is invalidated (`null` = full flush). Hosts queue regeneration or CDN purges here. |

Config: `modules.prerender.enabled`, `modules.prerender.post_types`
(default `['page']`), `modules.prerender.command` (renderer shell
template; tokens `{renderer}`, `{theme}`, `{base}`, `{routes}`, `{out}`).
