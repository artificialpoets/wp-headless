import { useState, useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'
import { resolveUrl } from '../lib/api'

/**
 * Resolve the current URL to a WordPress request context.
 *
 * On the initial page load, uses the injected runtime.request directly
 * (no network round-trip needed — the plugin already resolved it).
 *
 * On subsequent client-side navigations (including back to the initial URL),
 * calls GET /resolve?url=<path> to get the context for the new URL.
 *
 * A `cancelled` flag prevents stale responses from landing when the user
 * navigates quickly before a previous request completes.
 *
 * @param {object} runtime
 * @returns {object|null} request context, or null while loading
 */
export function useResolve(runtime) {
  const location = useLocation()
  const isFirstRun = useRef(true)
  const [request, setRequest] = useState(runtime?.request ?? null)

  useEffect(() => {
    if (isFirstRun.current) {
      isFirstRun.current = false
      return
    }

    let cancelled = false
    const url = location.pathname + location.search

    resolveUrl(runtime, url)
      .then((data) => { if (!cancelled) setRequest(data) })
      .catch(() => {
        if (!cancelled) {
          setRequest({ is_404: true, url, path: location.pathname })
        }
      })

    return () => { cancelled = true }
  }, [location.pathname, location.search])

  return request
}
