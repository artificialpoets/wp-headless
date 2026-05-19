import { useState, useEffect } from 'react'
import type { WPMenu } from '../types/api'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import { fetchMenu } from '../lib/api'

interface MenuState {
  menu: WPMenu | null
  loading: boolean
  error: string | null
}

/**
 * Fetch a nav menu from the wp-headless /menus endpoint.
 *
 * Pass null as params to skip the fetch entirely.
 */
export function useMenu(
  runtime: WPHeadlessRuntime | null,
  params: { id?: number; slug?: string; location?: string } | null = null
): MenuState {
  const [state, setState] = useState<MenuState>({ menu: null, loading: true, error: null })

  const paramsKey = params === null ? null : JSON.stringify(params)

  useEffect(() => {
    if (paramsKey === null) {
      setState({ menu: null, loading: false, error: null })
      return
    }

    let cancelled = false
    setState((s) => ({ ...s, loading: true, error: null }))

    fetchMenu(runtime, JSON.parse(paramsKey) as { id?: number; slug?: string; location?: string })
      .then((menu) => { if (!cancelled) setState({ menu, loading: false, error: null }) })
      .catch((err: unknown) => {
        const message = err instanceof Error ? err.message : String(err)
        if (!cancelled) setState({ menu: null, loading: false, error: message })
      })

    return () => { cancelled = true }
  }, [paramsKey])

  return state
}
