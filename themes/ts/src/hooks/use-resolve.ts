import { useState, useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
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
 */
export function useResolve(runtime: WPHeadlessRuntime | null): WPHeadlessRequest | null {
  const location = useLocation()
  const isFirstRun = useRef(true)
  const [request, setRequest] = useState<WPHeadlessRequest | null>(runtime?.request ?? null)

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
          setRequest({
            is_404: true,
            url,
            path: location.pathname,
            is_front_page: false,
            is_home: false,
            is_singular: false,
            is_archive: false,
            is_search: false,
            queried_object: null,
            post: null,
            queried_object_id: 0,
          })
        }
      })

    return () => { cancelled = true }
  }, [location.pathname, location.search])

  return request
}
