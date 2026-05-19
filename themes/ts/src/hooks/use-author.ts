import { useState, useEffect } from 'react'
import { fetchUser } from '../lib/api'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import type { WPUser } from '../types/api'

interface AuthorState {
  user: WPUser | null
  loading: boolean
  error: string | null
}

export function useAuthor(runtime: WPHeadlessRuntime | null, id: number | null | undefined): AuthorState {
  const [state, setState] = useState<AuthorState>({ user: null, loading: !!id, error: null })

  useEffect(() => {
    if (!id) {
      setState({ user: null, loading: false, error: null })
      return
    }

    let cancelled = false
    setState((s) => ({ ...s, loading: true, error: null }))

    fetchUser(runtime, id)
      .then((user) => { if (!cancelled) setState({ user, loading: false, error: null }) })
      .catch((err: Error) => { if (!cancelled) setState({ user: null, loading: false, error: err.message }) })

    return () => { cancelled = true }
  }, [id])

  return state
}
