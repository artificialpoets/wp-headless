import { useState, useEffect } from 'react'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import type { WPPost } from '../types/api'
import { fetchPages } from '../lib/api'

interface UsePagesResult {
  pages: WPPost[]
  loading: boolean
}

/**
 * Fetch published pages ordered by menu_order.
 */
export function usePages(runtime: WPHeadlessRuntime | null): UsePagesResult {
  const [pages, setPages] = useState<WPPost[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let cancelled = false
    fetchPages(runtime)
      .then((data) => { if (!cancelled) { setPages(data); setLoading(false) } })
      .catch(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [])

  return { pages, loading }
}
