import { useState, useEffect } from 'react'
import { fetchPages } from '../lib/api'

/**
 * Fetch published pages ordered by menu_order.
 *
 * @param {object} runtime
 * @returns {{ pages: object[], loading: boolean }}
 */
export function usePages(runtime) {
  const [pages, setPages] = useState([])
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
