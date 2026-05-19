import { useState, useEffect } from 'react'
import { fetchUser } from '../lib/api'

/**
 * Fetch a single user (author) by id.
 *
 * The endpoint /wp/v2/users requires either the user to have public posts or
 * the requester to be authenticated. We return null when the request is
 * unauthorised so consumers can fall back to the request.queried_object info
 * the plugin already serialised.
 *
 * @param {object} runtime
 * @param {number|null} id
 * @returns {{ user: object|null, loading: boolean, error: string|null }}
 */
export function useAuthor(runtime, id) {
  const [state, setState] = useState({ user: null, loading: !!id, error: null })

  useEffect(() => {
    if (!id) {
      setState({ user: null, loading: false, error: null })
      return
    }

    let cancelled = false
    setState((s) => ({ ...s, loading: true, error: null }))

    fetchUser(runtime, id)
      .then((user) => { if (!cancelled) setState({ user, loading: false, error: null }) })
      .catch((err) => { if (!cancelled) setState({ user: null, loading: false, error: err.message }) })

    return () => { cancelled = true }
  }, [id])

  return state
}
