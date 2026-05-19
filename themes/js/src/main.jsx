import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App'
import { themeTokensToCss } from './lib/theme-tokens'
import './main.css'

/**
 * Entry point.
 *
 * In production (served by wp-headless plugin):
 *   window.WP_HEADLESS is injected into <head> before </head> by the plugin.
 *   Because this script is a module (deferred by default), WP_HEADLESS is
 *   guaranteed to be available by the time this runs — no DOMContentLoaded needed.
 *
 * In local Vite dev mode:
 *   The dev-runtime mock is loaded instead so the app renders without WordPress.
 */
/**
 * Inject WordPress's core block library stylesheet so Gutenberg blocks
 * (columns, quote, code, separator, etc.) render with their default styles.
 * The path resolves against the WP site URL coming from runtime.site.url
 * so the same build works regardless of where WP is installed.
 */
function injectBlockStyles(runtime) {
  if (!runtime?.site?.url || typeof document === 'undefined') return
  const candidates = [
    '/wp-includes/css/dist/block-library/style.min.css',
    '/wp-includes/css/dist/block-library/theme.min.css',
  ]
  for (const path of candidates) {
    const href = new URL(path, runtime.site.url).toString()
    if (document.querySelector(`link[href="${href}"]`)) continue
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = href
    document.head.appendChild(link)
  }
}

/**
 * Inject WordPress's `wp-embed.min.js` so embedded oEmbed iframes auto-size
 * via the postMessage handshake WP defines. Needed whenever the page can
 * contain `<iframe>` elements served from this WP install (auto-embeds of
 * other posts, paste-an-URL block, etc.).
 */
function injectWpEmbedScript(runtime) {
  if (!runtime?.site?.url || typeof document === 'undefined') return
  const href = new URL('/wp-includes/js/wp-embed.min.js', runtime.site.url).toString()
  if (document.querySelector(`script[src="${href}"]`)) return
  const script = document.createElement('script')
  script.src = href
  script.defer = true
  document.head.appendChild(script)
}

/**
 * Inject theme.json presets as :root CSS custom properties, so any block
 * rendered from content.rendered that references --wp--preset--color-{slug}
 * etc. picks up the theme's palette automatically.
 *
 * Runs as early as possible so the very first paint already has the values.
 */
function injectThemeTokens(runtime) {
  if (typeof document === 'undefined') return
  const css = themeTokensToCss(runtime?.theme?.styles)
  if (!css) return
  if (document.querySelector('style[data-managed="wp-headless-tokens"]')) return
  const style = document.createElement('style')
  style.setAttribute('data-managed', 'wp-headless-tokens')
  style.textContent = css
  document.head.appendChild(style)
}

async function mount() {
  let runtime

  if (import.meta.env.DEV) {
    const { default: devRuntime } = await import('./lib/dev-runtime.js')
    runtime = devRuntime
  } else {
    runtime = window.WP_HEADLESS ?? null
  }

  injectThemeTokens(runtime)
  injectBlockStyles(runtime)
  injectWpEmbedScript(runtime)

  const root = document.getElementById('root')
  if (!root) throw new Error('Root element #root not found.')

  createRoot(root).render(
    <StrictMode>
      <BrowserRouter>
        <App runtime={runtime} />
      </BrowserRouter>
    </StrictMode>
  )
}

mount()
