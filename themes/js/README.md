# WP Headless Starter (JS)

A WordPress theme that hands its frontend over to a React single-page app. Built with React 18, Vite, React Router, and CSS Modules. JavaScript only — no build step beyond Vite, no TypeScript compile.

For the typed flavor, see `../ts/`.

## What this is

This is a real WordPress theme (`style.css` with header, `index.php` fallback, `functions.php` for theme support) bundled with a Vite-built React app under `dist/`. When the [wp-headless](../../) plugin is active and this theme is selected in Appearance → Themes, the plugin intercepts every public request and serves the React app instead of running the PHP template hierarchy.

When the plugin is inactive or the build is missing, WordPress falls back to `index.php`, which shows a helpful "this theme needs a build" page.

## Quick start

```bash
# 1. Copy or symlink the theme into your WP install
ln -s "$(pwd)" /path/to/wp/wp-content/themes/wp-headless-starter-js

# 2. Install dependencies and build
cd /path/to/wp/wp-content/themes/wp-headless-starter-js
npm install
npm run build

# 3. Activate via wp-admin → Appearance → Themes, or:
wp theme activate wp-headless-starter-js
```

You'll need the `wp-headless` plugin installed and active in the same site for headless mode to engage.

## Local development with Vite

```bash
npm run dev
```

This starts Vite on `http://localhost:5173/` with a **mock runtime** so the app renders without a WordPress backend. The mock lives in `src/lib/dev-runtime.js` — edit it to test specific request kinds (search, archive, single, etc.) against your local components.

To develop against a real WordPress install, build the theme (`npm run build`) and edit-build-refresh against your local WP. Hot reload against a live WP is on the roadmap.

## Project layout

```
themes/js/
├── style.css                   # WP theme header
├── index.php                   # PHP fallback when build is missing
├── functions.php               # Registers post-thumbnails, menus, post-formats, etc.
├── theme.json                  # WP block editor styles
├── screenshot.png              # Appears in Themes picker
├── package.json
├── vite.config.js
├── index.html                  # Vite entry — read once at build time
├── dist/                       # Build output (committed for end-users)
│   ├── index.html
│   └── assets/
└── src/
    ├── main.jsx                # Entry — mounts <App /> + injects WP block CSS
    ├── main.css                # Design tokens + Gutenberg block fallbacks + reset
    ├── App.jsx                 # Routes request to the right template
    ├── App.module.css
    ├── components/             # Reusable UI
    │   ├── admin-bar.jsx       # Top toolbar for logged-in users
    │   ├── breadcrumbs.jsx
    │   ├── comments.jsx        # Threaded + paginated
    │   ├── featured-image.jsx  # srcset/sizes/width/height aware
    │   ├── pagination.jsx
    │   └── post-card.jsx
    ├── hooks/
    │   ├── use-auth.js         # Reads runtime.user + capabilities
    │   ├── use-comments.js
    │   ├── use-head.js         # <title>, canonical, OG, Twitter, RSS
    │   ├── use-menu.js
    │   ├── use-post.js
    │   ├── use-posts.js
    │   ├── use-resolve.js      # Calls /resolve on navigation
    │   ├── use-author.js
    │   ├── use-pages.js
    │   └── use-document-title.js (legacy — prefer use-head)
    ├── lib/
    │   ├── api.js              # REST client — fetchPosts, fetchComments, postComment, ...
    │   ├── dev-runtime.js      # Mock window.WP_HEADLESS for `npm run dev`
    │   └── format.js           # date / timezone helpers
    ├── template-parts/
    │   ├── header/
    │   └── footer/
    └── templates/              # One per WP template kind
        ├── front-page.jsx
        ├── index.jsx
        ├── single.jsx
        ├── page.jsx
        ├── archive.jsx          # category, tag, custom taxonomies
        ├── author.jsx
        ├── date.jsx
        ├── attachment.jsx
        ├── cpt-archive.jsx
        ├── search.jsx
        ├── 404.jsx
        ├── login.jsx
        ├── register.jsx
        ├── lost-password.jsx
        └── *.module.css
```

## Customizing

### Design tokens

All visual styling lives behind CSS custom properties in `src/main.css`:

```css
:root {
  --color-base:       #ffffff;
  --color-contrast:   #111111;
  --color-accent-3:   #503aa8;
  --font-heading:     ui-serif, Georgia, serif;
  --space-6:          1.5rem;
  /* ... */
}
```

Override these in your own CSS or via Customizer → Additional CSS (which the React app injects at mount). No need to fork the components.

### Components

Every template and component uses CSS Modules — `*.module.css` files scoped to the component. Add your own variants by importing the module and composing classes, or fork the component if the change is structural.

### Adding a route

For something WordPress doesn't natively route (e.g. a `/dashboard/` for logged-in users), add a `wp_headless_should_serve_frontend` filter in your plugin that bypasses interception on that path, or — more commonly — handle the path in `App.jsx`'s `TemplateResolver` and ship a new template under `src/templates/`.

### Custom post types

CPTs work out of the box. As long as the type is registered with `'show_in_rest' => true`, `'has_archive' => true`, and a `rest_base`, the React app will:

- Resolve `/{cpt-slug}/` as a `post_type_archive` (rendered by `cpt-archive.jsx`)
- Resolve `/{cpt-slug}/{post-slug}/` as a singular post (rendered by `single.jsx` with the right `rest_base`)
- Pick up the archive in the runtime's `postTypes` array so you can list CPTs in nav

## Building for production

```bash
npm run build
```

Output goes to `dist/`. Vite emits content-hashed filenames (`index-AbCdEf.js`), which the plugin recognises and serves with `Cache-Control: public, max-age=31536000, immutable`. Filenames without a hash get `max-age=3600`.

Commit the `dist/` directory if you want end-users to install the theme without running Node. Otherwise add `dist/` to `.gitignore` and run `npm run build` during your deploy.

## License

GPL-2.0-or-later. Copyright (C) 2024–2025 Artificial Poets.
