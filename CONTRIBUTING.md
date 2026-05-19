# Contributing to WP Headless

Contributions are welcome — bug reports, feature requests, and pull requests against the plugin or the starter themes.

## Reporting bugs

When opening an issue:

1. State the version of WP Headless, WordPress, and PHP you're on.
2. Describe what you expected to happen and what actually happened.
3. Include the URL pattern that triggered the bug if it's a resolver / routing issue.
4. If the React app is misbehaving, include the response body of `/wp-json/wp-headless/v1/runtime` (with the nonce redacted).

## Development setup

```bash
git clone https://github.com/artificialpoets/wp-headless wp-headless
cd wp-headless

# PHP side
composer install
./vendor/bin/phpunit          # must be green before any PR

# Theme side (substitute themes/ts for the typed flavor)
cd themes/js
npm install
npm run build                 # writes dist/
npm run dev                   # Vite dev server with mock runtime
```

To exercise the plugin against a real WordPress install, symlink the repo into a local site:

```bash
ln -s "$(pwd)" /path/to/wp/wp-content/plugins/wp-headless
ln -s "$(pwd)/themes/js" /path/to/wp/wp-content/themes/wp-headless-starter-js
wp plugin activate wp-headless
wp theme activate wp-headless-starter-js
```

## Pull requests

- Branch from `main`.
- Keep PRs focused — one feature, fix, or refactor per PR.
- Update tests for any logic change. The PHPUnit suite is the contract — green is required.
- Update the TypeScript types in `themes/ts/src/types/` whenever you change the runtime payload shape, even if the change is in the JS theme.
- Update the JavaScript theme (`themes/js`) and the TypeScript theme (`themes/ts`) together when changing a component, hook, or template. They must stay in lock-step on behavior.
- Update both `README.md` and `CHANGELOG.md` for user-visible changes.
- Update `languages/wp-headless.pot` when adding new translatable strings (see below).
- Don't include `dist/` changes in commits unless the PR is specifically about a starter theme release — leave the build to the release process.

## Tests

```bash
./vendor/bin/phpunit
```

96 tests as of 1.0.0. Unit tests live under `tests/Unit/` and use Brain Monkey to stub WordPress functions. New plugin logic should come with tests that exercise the pure behavior — anything that can be tested without a full WP bootstrap, should be.

When updating tests for a refactor, prefer to update the test to match the new contract rather than weakening assertions. If a class signature changes, every consumer's test should follow.

## Coding standards

PHP follows the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/). The codebase uses tabs (WP convention), short array syntax, and namespaces under `WPHeadless\`.

JavaScript / TypeScript follows the default ESLint config that ships with Vite. CSS Modules are scoped per-component — global styles live only in `src/main.css`.

## Translations

When adding new translatable strings to plugin PHP (`__()`, `esc_html__()`, `_e()`, etc.) with the `wp-headless` text domain, regenerate the POT file:

```bash
wp i18n make-pot . languages/wp-headless.pot \
  --domain=wp-headless \
  --slug=wp-headless \
  --skip-audit \
  --exclude=themes,vendor,tests,node_modules,dev
```

Translators contribute `.po` files under `languages/`; the build picks up `.mo` files at runtime.

## Releases

Pre-wp.org (current): releases are fully automated through GitHub Actions. Pushing to `main` is the release command.

### The flow

1. Work on `dev` (or any feature branch) — these branches don't trigger any release.
2. When ready to ship, merge / PR / push to `main`.
3. The `.github/workflows/release.yml` job:
   - Reads the current version from `wp-headless.php`.
   - Bumps it (PATCH by default).
   - Rewrites the version in `wp-headless.php`, `composer.json`, and `readme.txt`.
   - Re-installs composer dependencies with `--no-dev` (production-clean vendor).
   - Rebuilds both starter themes (`themes/js` + `themes/ts`).
   - Commits the bump + fresh `dist/` + `vendor/` back to `main` with `[skip release]` so it doesn't loop.
   - Tags `vX.Y.Z` and creates a GitHub Release with `wp-headless.zip` attached.
4. Within ~12 hours, every site running the plugin auto-installs the new release (because the `auto_update_plugin` filter is enabled for `wp-headless`).

### Controlling the bump level

The commit subject controls how the version moves:

| Tag in commit subject  | Effect                                  |
|------------------------|-----------------------------------------|
| _(none)_               | Patch bump: `0.1.0 → 0.1.1`             |
| `[bump:minor]`         | Minor bump: `0.1.0 → 0.2.0`             |
| `[bump:major]`         | Major bump: `0.1.0 → 1.0.0`             |
| `[skip release]`       | Skip CI entirely — no bump, no release  |

Example:
```bash
git commit -m "feat: add post revisions endpoint [bump:minor]"
git push origin main
```

### Versioning rules

Semantic Versioning ([semver.org](https://semver.org/)) — bump MAJOR for breaking changes to the runtime payload or REST endpoints, MINOR for additive changes, PATCH for fixes.

### Going to wp.org (v1.0.0)

When the plugin moves to wp.org's SVN repository:

1. Remove the `Plugin Update Checker` block at the bottom of `wp-headless.php` (don't fight wp.org's updater).
2. Remove the GitHub Actions release workflow, or restrict it to only build dev zips.
3. Stop committing `vendor/` (wp.org strips it anyway from the zip they serve).

## License

By contributing, you agree your changes are licensed under GPL-2.0-or-later, the same license as the project.
