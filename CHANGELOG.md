# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
