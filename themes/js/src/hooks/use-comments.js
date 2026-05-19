import { useState, useEffect } from 'react'
import { fetchComments } from '../lib/api'

/**
 * Fetch comments for a post with paging.
 *
 * When `page_comments` is true in the discussion settings the React app
 * should page top-level comments and keep their children attached. This hook
 * fetches one page at a time and reads X-WP-Total / X-WP-TotalPages from the
 * REST response.
 *
 * @param {object} runtime
 * @param {number|null|undefined} postId
 * @param {{ page?: number, per_page?: number, order?: 'asc'|'desc' }} [options]
 * @returns {{ comments: object[], loading: boolean, error: string|null, total: number, totalPages: number }}
 */
export function useComments(runtime, postId, options = {}) {
  const [comments, setComments] = useState([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  const page = Math.max(1, options.page ?? 1)
  const perPage = options.per_page ?? runtime?.discussion?.comments_per_page ?? 50
  const order = options.order ?? runtime?.discussion?.comment_order ?? 'asc'

  useEffect(() => {
    if (!postId) {
      setLoading(false)
      return
    }

    let cancelled = false
    setLoading(true)

    fetchComments(runtime, postId, { page, per_page: perPage, order })
      .then((res) => {
        if (cancelled) return
        setComments(res.comments)
        setTotal(res.total)
        setTotalPages(res.totalPages)
        setLoading(false)
      })
      .catch((err) => {
        if (cancelled) return
        setError(err.message)
        setLoading(false)
      })

    return () => { cancelled = true }
  }, [postId, page, perPage, order])

  return { comments, loading, error, total, totalPages }
}
