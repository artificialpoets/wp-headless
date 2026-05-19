import type { WPHeadlessRuntime } from '../types/wp-headless'

/**
 * Returns the injected window.WP_HEADLESS runtime object.
 *
 * On a live WordPress site this is always populated before the script
 * module runs (it is injected into <head> before </head>).
 * In local Vite dev mode, main.tsx passes the dev-runtime mock instead.
 */
export function useRuntime(runtime: WPHeadlessRuntime | null): WPHeadlessRuntime | null {
  return runtime ?? null
}
