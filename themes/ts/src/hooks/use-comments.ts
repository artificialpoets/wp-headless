import { useState, useEffect } from 'react'
import { fetchComments } from '../lib/api'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import type { WPComment } from '../types/api'

interface UseCommentsOptions {
  page?: number
  per_page?: number
  order?: 'asc' | 'desc'
}

interface UseCommentsState {
  comments: WPComment[]
  loading: boolean
  error: string | null
  total: number
  totalPages: number
}

export function useComments(
  runtime: WPHeadlessRuntime | null,
  postId: number | null | undefined,
  options: UseCommentsOptions = {}
): UseCommentsState {
  const [comments, setComments] = useState<WPComment[]>([])
  const [total, setTotal] = useState(0)
  const [totalPages, setTotalPages] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const page = Math.max(1, options.page ?? 1)
  const perPage = options.per_page ?? runtime?.discussion?.comments_per_page ?? 50
  const order = options.order ?? (runtime?.discussion?.comment_order as 'asc' | 'desc' | undefined) ?? 'asc'

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
      .catch((err: Error) => {
        if (cancelled) return
        setError(err.message)
        setLoading(false)
      })

    return () => { cancelled = true }
  }, [postId, page, perPage, order])

  return { comments, loading, error, total, totalPages }
}
