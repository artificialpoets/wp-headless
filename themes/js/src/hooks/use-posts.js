import { useState, useEffect } from 'react'
import { fetchPosts } from '../lib/api'

/**
 * Fetch a list of posts.
 *
 * NOTE: params is stringified for the effect dependency to avoid infinite
 * re-render loops when the params object is created inline. For production
 * use, prefer stabilising params with useMemo() instead.
 *
 * @param {object} runtime
 * @param {Record<string, string|number>|null} [params] - Pass null to skip the fetch.
 * @returns {{ posts: object[], total: number, totalPages: number, loading: boolean, error: string|null }}
 */
export function usePosts(runtime, params = {}) {
  const [state, setState] = useState({
    posts: [],
    total: 0,
    totalPages: 0,
    loading: true,
    error: null,
  })

  // Stringify for stable dependency comparison.
  const paramsKey = params === null ? null : JSON.stringify(params)

  useEffect(() => {
    if (paramsKey === null) {
      setState({ posts: [], total: 0, totalPages: 0, loading: false, error: null })
      return
    }

    let cancelled = false
    setState((s) => ({ ...s, loading: true, error: null }))

    fetchPosts(runtime, JSON.parse(paramsKey))
      .then(({ posts, total, totalPages }) => {
        if (!cancelled) setState({ posts, total, totalPages, loading: false, error: null })
      })
      .catch((err) => {
        if (!cancelled)
          setState({ posts: [], total: 0, totalPages: 0, loading: false, error: err.message })
      })

    return () => { cancelled = true }
  }, [paramsKey])

  return state
}
