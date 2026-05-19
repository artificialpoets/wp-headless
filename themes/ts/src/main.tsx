import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import type { WPHeadlessRuntime } from './types/wp-headless'
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
 * Inject WordPress's core block library stylesheet so Gutenberg blocks render
 * with their default styles. Resolves against runtime.site.url so the same
 * build works regardless of WP install path.
 */
function injectBlockStyles(runtime: WPHeadlessRuntime | null): void {
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

function injectWpEmbedScript(runtime: WPHeadlessRuntime | null): void {
  if (!runtime?.site?.url || typeof document === 'undefined') return
  const href = new URL('/wp-includes/js/wp-embed.min.js', runtime.site.url).toString()
  if (document.querySelector(`script[src="${href}"]`)) return
  const script = document.createElement('script')
  script.src = href
  script.defer = true
  document.head.appendChild(script)
}

function injectThemeTokens(runtime: WPHeadlessRuntime | null): void {
  if (typeof document === 'undefined') return
  const css = themeTokensToCss(runtime?.theme?.styles)
  if (!css) return
  if (document.querySelector('style[data-managed="wp-headless-tokens"]')) return
  const style = document.createElement('style')
  style.setAttribute('data-managed', 'wp-headless-tokens')
  style.textContent = css
  document.head.appendChild(style)
}

async function mount(): Promise<void> {
  let runtime: WPHeadlessRuntime | null = null

  if (import.meta.env.DEV) {
    const { default: devRuntime } = await import('./lib/dev-runtime.js')
    runtime = devRuntime
  } else {
    runtime = window.WP_HEADLESS
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
