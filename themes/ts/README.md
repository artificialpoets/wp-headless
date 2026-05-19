# WP Headless Starter (TS)

A WordPress theme that hands its frontend over to a strict-mode TypeScript React app. Built with React 18, Vite, React Router, CSS Modules, and `tsc --noEmit` as a compile gate.

The TypeScript flavor of `../js/`. Most of the developer experience is identical — see that README for the architectural overview. This file documents what's specific to the typed setup.

## Quick start

```bash
ln -s "$(pwd)" /path/to/wp/wp-content/themes/wp-headless-starter-ts
cd /path/to/wp/wp-content/themes/wp-headless-starter-ts
npm install
npm run build        # runs `tsc --noEmit && vite build`
wp theme activate wp-headless-starter-ts
```

`npm run build` runs the TypeScript checker before Vite so any type error fails the build. Use `npm run dev` for the Vite dev server (no TS check; rely on your editor).

## What's typed

Every file under `src/` is `.ts` or `.tsx`. Two type packs live under `src/types/`:

- **`wp-headless.d.ts`** — types for the `window.WP_HEADLESS` runtime payload the plugin injects. Mirrors the plugin's runtime shape: `WPHeadlessSite`, `WPHeadlessRuntime`, `WPHeadlessRequest`, `WPHeadlessUser`, `WPHeadlessUrls`, `WPHeadlessPostType`, `WPHeadlessDiscussion`, `WPHeadlessQueriedObject`, etc. A `declare global` extends `Window` so `window.WP_HEADLESS` is typed without a cast.

- **`api.d.ts`** — types for WP REST API responses: `WPPost`, `WPComment`, `WPTerm`, `WPUser`, `WPMenu`, `WPMenuItem`, `WPFeaturedImage`, `WPAuthorInfo`, `WPEmbeddedTerm`, `WPAdjacentSummary`, `WPPostsResponse`.

When the plugin grows new runtime fields, update both — the JS theme stays in sync via the same shapes, just without compile-time enforcement.

## tsconfig highlights

```json
{
  "strict": true,
  "noUnusedLocals": true,
  "noUnusedParameters": true,
  "moduleResolution": "bundler",
  "jsx": "react-jsx"
}
```

If you fork this theme and want looser types, relax `strict` rather than disabling specific rules — that way the runtime types in `src/types/` keep documenting the API surface even if your own code is less rigorous.

## Layout differences from JS

Same file structure as `themes/js`, with `.tsx` extensions and these additions:

- `src/types/` — see above
- `tsconfig.json` — strict mode
- `vite.config.ts` (typed) and `vite-env.d.ts` for the `import.meta.env.DEV` typing

Each hook and template imports the relevant types from `src/types/` so you get autocomplete on `runtime.user.capabilities.edit_posts`, `request.queried_object.taxonomy`, `post.featured_image?.srcset`, etc.

## Customizing safely

Because everything is typed:

- **Adding a new runtime field** — update `WPHeadlessRuntime` in `src/types/wp-headless.d.ts` and `src/lib/dev-runtime.ts` first; the rest of the codebase will tell you everywhere that field needs to be considered.
- **Adding a REST endpoint** — define the response interface in `src/types/api.d.ts`, then the `fetch*()` function in `src/lib/api.ts`, then the hook in `src/hooks/use-*.ts`. The compiler catches signature drift between layers.
- **Adding a template** — typed `WPHeadlessRequest` makes it obvious which request shape your new template handles.

## License

GPL-2.0-or-later. Copyright (C) 2024–2025 Artificial Poets.
