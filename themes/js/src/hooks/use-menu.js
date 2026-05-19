import { useState, useEffect } from 'react'
import { fetchMenu } from '../lib/api'

/**
 * Fetch a nav menu from the wp-headless /menus endpoint.
 *
 * @param {object} runtime
 * @param {{ id?: number, slug?: string, location?: string }|null} [params]
 * @returns {{ menu: object|null, loading: boolean, error: string|null }}
 */
export function useMenu(runtime, params = null) {
  const [state, setState] = useState({ menu: null, loading: true, error: null })

  const paramsKey = params === null ? null : JSON.stringify(params)

  useEffect(() => {
    if (paramsKey === null) {
      setState({ menu: null, loading: false, error: null })
      return
    }

    let cancelled = false
    setState((s) => ({ ...s, loading: true, error: null }))

    fetchMenu(runtime, JSON.parse(paramsKey))
      .then((menu) => { if (!cancelled) setState({ menu, loading: false, error: null }) })
      .catch((err) => { if (!cancelled) setState({ menu: null, loading: false, error: err.message }) })

    return () => { cancelled = true }
  }, [paramsKey])

  return state
}
