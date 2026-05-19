import { useState, useEffect } from 'react'
import type { WPPost } from '../types/api'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import { fetchPosts } from '../lib/api'

interface PostsState {
  posts: WPPost[]
  total: number
  totalPages: number
  loading: boolean
  error: string | null
}

/**
 * Fetch a list of posts.
 *
 * NOTE: params is stringified for the effect dependency to avoid infinite
 * re-render loops when the params object is created inline. For production
 * use, prefer stabilising params with useMemo() instead.
 *
 * Pass null as params to skip the fetch entirely.
 */
export function usePosts(
  runtime: WPHeadlessRuntime | null,
  params: Record<string, string | number> | null = {}
): PostsState {
  const [state, setState] = useState<PostsState>({
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

    fetchPosts(runtime, JSON.parse(paramsKey) as Record<string, string | number>)
      .then(({ posts, total, totalPages }) => {
        if (!cancelled) setState({ posts, total, totalPages, loading: false, error: null })
      })
      .catch((err: unknown) => {
        const message = err instanceof Error ? err.message : String(err)
        if (!cancelled)
          setState({ posts: [], total: 0, totalPages: 0, loading: false, error: message })
      })

    return () => { cancelled = true }
  }, [paramsKey])

  return state
}
