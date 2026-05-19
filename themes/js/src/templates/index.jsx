import { useSearchParams } from 'react-router-dom'
import { usePosts } from '../hooks/use-posts'
import { useHead } from '../hooks/use-head'
import { PostCard } from '../components/post-card'
import { Pagination } from '../components/pagination'
import styles from './index.module.css'

/**
 * Index template — equivalent to WordPress index.php.
 *
 * Fallback template that shows a paginated post list. Page state lives in
 * the ?page=N URL parameter so refresh and share preserve position.
 *
 * @param {{ runtime: object, request: object }} props
 */
export function Index({ runtime, request }) {
  const [params, setParams] = useSearchParams()
  const initialPage = request?.page ?? 1
  const page = Math.max(1, parseInt(params.get('page') || String(initialPage), 10))

  const { posts, totalPages, loading } = usePosts(runtime, { per_page: 10, page })
  const siteName = runtime?.site?.name
  const rssUrl = runtime?.site?.url ? new URL('/feed/', runtime.site.url).toString() : undefined

  useHead({
    title: siteName ? `Posts — ${siteName}` : 'Posts',
    description: runtime?.site?.description,
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
    rssUrl,
  })

  const setPage = (n) => {
    const next = new URLSearchParams(params)
    if (n <= 1) next.delete('page')
    else next.set('page', String(n))
    setParams(next, { replace: false })
    window.scrollTo({ top: 0, behavior: 'instant' })
  }

  return (
    <div className={styles.wrap}>
      <h1 className={styles.heading}>Posts</h1>

      {loading && <p className={styles.loading}>Loading…</p>}

      {!loading && posts.length === 0 && (
        <p className={styles.empty}>No posts found.</p>
      )}

      <div className={styles.list}>
        {posts.map((post) => (
          <PostCard key={post.id} post={post} runtime={runtime} />
        ))}
      </div>

      <Pagination
        currentPage={page}
        totalPages={totalPages}
        onPageChange={setPage}
      />
    </div>
  )
}
