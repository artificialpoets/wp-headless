/**
 * Returns the injected window.WP_HEADLESS runtime object.
 *
 * On a live WordPress site this is always populated before the script
 * module runs (it is injected into <head> before </head>).
 * In local Vite dev mode, main.jsx passes the dev-runtime mock instead.
 *
 * @param {object} runtime - Runtime passed as prop from App / main.jsx
 * @returns {import('../types/wp-headless').WPHeadlessRuntime|null}
 */
export function useRuntime(runtime) {
  return runtime ?? null
}
