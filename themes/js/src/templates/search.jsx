import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { usePosts } from '../hooks/use-posts'
import { useHead } from '../hooks/use-head'
import { PostCard } from '../components/post-card'
import styles from './search.module.css'

/**
 * Search results template — equivalent to WordPress search.php.
 *
 * Reads the initial query from request.search_query (resolved by the plugin),
 * then navigates to /?s=<query> when the user submits the form so React Router
 * picks up the new URL and useResolve fetches the updated context.
 *
 * @param {{ runtime: object, request: object }} props
 */
export function Search({ runtime, request }) {
  const navigate = useNavigate()
  const siteName = runtime?.site?.name
  const initialQuery = request?.search_query ?? ''

  const [query, setQuery] = useState(initialQuery)
  const [activeQuery, setActiveQuery] = useState(initialQuery)

  // Sync when navigating between search pages (e.g., /?s=foo → /?s=bar).
  useEffect(() => {
    if (request?.search_query != null) {
      setQuery(request.search_query)
      setActiveQuery(request.search_query)
    }
  }, [request?.search_query])

  useHead({
    title: activeQuery
      ? `Search results for "${activeQuery}" — ${siteName ?? ''}`
      : siteName ? `Search — ${siteName}` : 'Search',
    description: activeQuery ? `Search results for "${activeQuery}"` : 'Search',
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
  })

  // Only fetch when there's an actual query to avoid returning all posts.
  const params = activeQuery ? { search: activeQuery, per_page: 10 } : null
  const { posts, loading } = usePosts(runtime, params)

  function handleSubmit(e) {
    e.preventDefault()
    const q = query.trim()
    if (q) navigate(`/?s=${encodeURIComponent(q)}`)
  }

  return (
    <div className={styles.wrap}>
      <h1 className={styles.heading}>
        {activeQuery
          ? `Search results for: "${activeQuery}"`
          : 'Search'}
      </h1>

      <form role="search" onSubmit={handleSubmit} className={styles.form}>
        <label htmlFor="search-input" className={styles.srOnly}>
          Search
        </label>
        <input
          id="search-input"
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search posts…"
          className={styles.input}
          autoFocus
        />
        <button type="submit" className={styles.button}>Search</button>
      </form>

      {loading && <p className={styles.status}>Searching…</p>}

      {!loading && activeQuery && posts.length === 0 && (
        <p className={styles.status}>
          No results found for &ldquo;{activeQuery}&rdquo;.
        </p>
      )}

      <div className={styles.list}>
        {posts.map((post) => (
          <PostCard key={post.id} post={post} runtime={runtime} />
        ))}
      </div>
    </div>
  )
}
