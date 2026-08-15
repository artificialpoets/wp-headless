# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — 0.2.0

SEO/AEO engine + open module system. For a headless site the served body is
an empty SPA shell, so head meta, JSON-LD, sitemaps, and llms.txt are the
machine-readable content channels — this release makes them first-class.

### Added
- **Module registry**: `Plugin` keeps a keyed module map; the new
  `wp_headless_modules` filter lets add-on plugins register, replace, or
  remove modules; `Plugin::add_module()` covers late registrants (themes);
  `modules.{key}.enabled => false` config disables any module per site.
  New `wp_headless_booted` action (the plugin's first action).
- **Prerender module** (`Runtime\Prerender`): first-paint pre-rendering as
  a platform capability. Stores per-post pre-rendered HTML
  (script-stripped, size-capped) in a dedicated `headless_prerenders`
  table — postmeta storage polluted every post's meta cache with 40kB
  rows; legacy meta migrates automatically on upgrade and serves it as a
  `#wp-headless-prerender` sibling injected before `#root` at
  `wp_headless_document_html@10` — theme fallback shells conventionally
  hook at 20 and skip when the container is present; the frontend removes
  the container when it commits its own chrome. Invalidates on post
  save/delete and theme switch — reusable-block (`wp_block`) saves and
  deletes cascade to every published post embedding them through
  `wp:block {"ref":N}` markers, nested patterns included, since those
  posts' stored markup renders the block and hydration otherwise masks
  the staleness from everyone but no-JS visitors and crawlers;
  themes/hosts call
  `Prerender::invalidate()/flush()` for their own triggers and react via
  the new `wp_headless_prerender_invalidated` action (regeneration
  queues, CDN purges). `wp headless prerender [--post=<id>] [--flush]`
  shells a configurable renderer command
  (`modules.prerender.command`, tokens `{renderer}/{theme}/{base}/{routes}/{out}`;
  default runs the active theme's `tools/render-pages.mjs` — plain-Node
  SSR, no browser). Self-healing by default: every invalidation queues a
  debounced cron regeneration (`modules.prerender.auto_regenerate`,
  default on where `shell_exec` is available; `modules.prerender.node_bin`
  pins the Node binary for thin-PATH web contexts). Config:
  `modules.prerender.post_types` (default `['page']`).
- **Schema.org graph** (`Seo\SchemaGraph`): the SEO head's JSON-LD is now a
  connected `@graph` with stable `@id` anchors — Organization (+logo),
  WebSite (+SearchAction), WebPage/CollectionPage/ProfilePage,
  BreadcrumbList, ImageObject, Article, Person — covering singulars,
  archives, authors, and dates. New filters: `wp_headless_schema_pieces`,
  `wp_headless_schema_piece`, `wp_headless_schema_graph`.
- **llms.txt** (`Seo\LlmsTxt`): `/llms.txt` (llmstxt.org site map for AI
  agents) and opt-in `/llms-full.txt` (full text content). Filters:
  `wp_headless_llms_txt_data`, `wp_headless_llms_txt_output`.
- **AI-crawler policy** (`Seo\RobotsTxt`): robots.txt decoration —
  `allow` (default) or `block` stanzas for AI training/discovery bots
  (`wp_headless_ai_crawlers` filter), plus an llms.txt pointer.
- **Head cleanup** (`Seo\HeadCleanup`): removes headless-irrelevant head
  output (RSD/xmlrpc, generator, shortlink, emoji bootstrap, …) when
  serving; per-tag config + `wp_headless_head_cleanup` filter. RSS feed
  links, canonical, robots, and REST discovery stay by default.
- og:image fallback chain: featured image → custom logo → site icon →
  `seo.default_image` config — social cards are no longer imageless.
- `docs/HOOKS.md`: complete hook API reference with semver policy.
- Boot now happens on `after_setup_theme@100` (was include time) so every
  plugin AND the active theme can hook `wp_headless_modules`/`wp_headless_config` regardless of
  load order.

### Changed
- **`jsonld` in `wp_headless_seo_meta` now carries a `@graph` document**
  (was a single flat node). The array key and filter contract are
  unchanged; only consumers introspecting the node's internal shape are
  affected.
- Front page / posts page is detected before singulars in SEO meta —
  fixes a static front page emitting its page permalink as canonical and
  its post title as og:title.
- `/favicon.ico` is exempted from frontend interception (core favicon
  behavior instead of an SPA-shell 404).

### Fixed
- **Soft 404s: WordPress's `is_404()` now decides the response status for
  every route WordPress can query.** The URL resolver matches route
  *shape* — a valid permalink structure plus an object that exists — and
  never asks whether the query behind it returns anything, so
  `/blog/page/2/` on a one-page blog, and archives whose posts are all
  drafts, resolved cleanly and served **200** carrying the app's
  not-found view. A 200 that renders not-found is worse than a real 404:
  crawlers bank it as thin content instead of dropping the URL.
  `FrontendBridge` already deferred to WordPress in the opposite
  direction (resolver says unresolved, WP resolved it fine); this adds
  the missing mirror. Auth routes (`/login/`, `/profile/`, …) are exempt
  — WordPress 404s them by definition, so the resolver stays
  authoritative there. New `FrontendBridge::resolve_is_404()` holds the
  decision and is unit-tested.

  Note for site owners: this can surface pre-existing routing bugs that
  the soft 200 was hiding. A taxonomy archive that 404s after upgrading
  was already broken — check for rewrite-rule collisions between a
  custom post type's slug and a taxonomy nested under it.

### Removed
- `SeoHead::article_jsonld()` (protected) — replaced by `Seo\SchemaGraph`.
  Subclasses overriding it must migrate to the schema piece filters.

## [0.1.0] — 2026-05-18

First pre-release. Plugin is feature-complete for native WordPress content and ships with two production-ready starter themes. Versioned `0.x` while battle-testing on real sites; `1.0.0` will be the first wp.org release.

### Distribution

- Self-hosted update channel via GitHub Releases on `artificialpoets/wp-headless`.
- Bundles [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) so updates appear in WordPress's Dashboard → Updates like a wp.org plugin.
- Auto-update opt-in by default (filterable per-site).
- GitHub Actions `release.yml` workflow: every push to `main` auto-bumps the version, rebuilds both starter themes, tags, and publishes a clean zip as a release asset.

### Plugin

- **Active-theme detection.** Headless mode follows Appearance → Themes — no custom activation UI. Any theme that ships `dist/index.html` becomes a headless theme.
- **Asset proxy.** Built JS/CSS served from the active theme's `dist/assets/` via `/_wp-headless/assets/...` with immutable cache headers for hash-named files.
- **Runtime payload.** Single `window.WP_HEADLESS` exposes site, REST roots + nonce, current request context, menu locations, registered post types, discussion settings, Customizer Additional CSS, auth URLs, and the logged-in user (with capabilities) when present.
- **URL resolver.** `/wp-headless/v1/resolve?url=...` recognises every WordPress URL kind: front page, posts archive, posts page, post / page / CPT singles, attachment, term archives (category, tag, custom taxonomies), author archives, date archives (year/month/day), CPT archives, paged archives (`/page/N/` and `?paged=N`), search, post preview (anonymous + signed-link), and the conventional auth paths (`/login/`, `/register/`, `/lost-password/`).
- **Menu API.** `/wp-headless/v1/menus` returns a recursive tree, not a flat list.
- **REST enrichments** on posts/pages: `featured_image` (srcset, sizes, width, height, mime), `author_info` (link, description, url), `comment_count`, `adjacent` (previous / next post), `permalink`.
- **CORS.** Configurable allowed origins with proper preflight handling.
- **Nav menu locations.** Registers `primary` and `footer` so headless themes have menu surfaces out of the box.
- **HTTP status.** Resolver's `is_404` is the source of truth — auth pages and CPT archives that WP itself wouldn't recognise return 200; real 404s return 404.
- **Settings → WP Headless** dashboard shows status (engaged / paused), active theme, dist path, asset mount, and REST endpoints. Informational only — activation lives in Appearance → Themes.

### Starter themes (`themes/js`, `themes/ts`)

Both real WordPress themes (style.css, index.php, functions.php, theme.json) packaged with React + Vite.

- Full template hierarchy: `front-page`, `index`, `single`, `page`, `archive`, `author`, `date`, `attachment`, `cpt-archive`, `search`, `404`.
- Auth: `login`, `register`, `lost-password` posting to `wp-login.php`.
- Threaded + paginated comments respecting Discussion settings.
- Breadcrumbs, sticky posts, post formats (aside, quote, image, video, link), password-protected posts.
- `AdminBar` for logged-in users with edit-this-post / new-post / profile / logout — replaces the WP-injected admin bar.
- `useAuth` hook for capability-aware UI.
- `useHead` hook managing `<title>`, canonical, Open Graph, Twitter Card, RSS feed link.
- Customizer Additional CSS injected at app mount.
- WordPress core block library CSS injected from `runtime.site.url` so blocks render with default styles.
- Featured-image `srcset` + `sizes` + width/height for responsive images and CLS prevention.
- Full RTL via CSS logical properties (margin-inline/padding-inline/border-inline + text-align: start/end). Zero physical layout properties remain.
- TypeScript starter: `tsc --noEmit` strict mode, runs in CI alongside the build.

### Tests

- 96 PHPUnit tests covering Config, HtmlDocument, AssetProxy, MenuEndpoint, RequestDataBuilder, Cors. Brain Monkey for WordPress function stubs. Runs in under a second.

### Documentation

- README, CHANGELOG, CONTRIBUTING, theme READMEs, WordPress.org-format `readme.txt`, GPL-2.0 LICENSE, translation `.pot` template.
