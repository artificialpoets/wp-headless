import { useState, useEffect } from 'react'
import type { WPPost } from '../types/api'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import { fetchPost, fetchPage, wpV2Root } from '../lib/api'

interface PostState {
  post: WPPost | null
  loading: boolean
  error: string | null
}

/**
 * Fetch a single post, page, or any registered custom post type by ID.
 *
 * The `postType` is used to pick the REST collection: 'page' goes to
 * /pages, 'post' (the default) and any CPT name go to /{rest_base}.
 */
export function usePost(
  runtime: WPHeadlessRuntime | null,
  id: number | null | undefined,
  postType: string = 'post'
): PostState {
  const [state, setState] = useState<PostState>({ post: null, loading: true, error: null })

  useEffect(() => {
    if (!id) {
      setState({ post: null, loading: false, error: null })
      return
    }

    let cancelled = false
    setState((s) => ({ ...s, loading: true, error: null }))

    const fetcher: Promise<WPPost> = (() => {
      if (postType === 'page') return fetchPage(runtime, id)
      if (postType === 'post' || !postType) return fetchPost(runtime, id)
      // CPT — look up rest_base from runtime.postTypes
      const pt = runtime?.postTypes?.find((p) => p.name === postType)
      const restBase = pt?.rest_base ?? postType
      return fetchCpt(runtime, restBase, id)
    })()

    fetcher
      .then((post) => { if (!cancelled) setState({ post, loading: false, error: null }) })
      .catch((err: unknown) => {
        const message = err instanceof Error ? err.message : String(err)
        if (!cancelled) setState({ post: null, loading: false, error: message })
      })

    return () => { cancelled = true }
  }, [id, postType])

  return state
}

async function fetchCpt(runtime: WPHeadlessRuntime | null, restBase: string, id: number): Promise<WPPost> {
  const res = await fetch(`${wpV2Root(runtime)}/${restBase}/${id}?_embed`)
  if (!res.ok) throw new Error(`fetchCpt failed: ${res.status} ${res.statusText}`)
  return res.json() as Promise<WPPost>
}
