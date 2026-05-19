import { useState, useEffect } from 'react'
import { fetchPost, fetchPage, wpV2Root } from '../lib/api'

/**
 * Fetch a single post, page, or any registered CPT by ID.
 *
 * Pass the post_type to pick the REST collection: 'page' goes to /pages,
 * 'post' (the default) and any CPT name go to /{rest_base}. CPT rest_base
 * is looked up from runtime.postTypes.
 *
 * @param {object} runtime
 * @param {number|null|undefined} id
 * @param {string} [postType]
 * @returns {{ post: object|null, loading: boolean, error: string|null }}
 */
export function usePost(runtime, id, postType = 'post') {
  const [state, setState] = useState({ post: null, loading: true, error: null })

  useEffect(() => {
    if (!id) {
      setState({ post: null, loading: false, error: null })
      return
    }

    let cancelled = false
    setState((s) => ({ ...s, loading: true, error: null }))

    let fetcher
    if (postType === 'page') {
      fetcher = fetchPage(runtime, id)
    } else if (postType === 'post' || !postType) {
      fetcher = fetchPost(runtime, id)
    } else {
      const pt = (runtime?.postTypes ?? []).find((p) => p.name === postType)
      const restBase = pt?.rest_base ?? postType
      fetcher = fetch(`${wpV2Root(runtime)}/${restBase}/${id}?_embed`).then((res) => {
        if (!res.ok) throw new Error(`fetchCpt failed: ${res.status}`)
        return res.json()
      })
    }

    fetcher
      .then((post) => { if (!cancelled) setState({ post, loading: false, error: null }) })
      .catch((err) => { if (!cancelled) setState({ post: null, loading: false, error: err.message }) })

    return () => { cancelled = true }
  }, [id, postType])

  return state
}
