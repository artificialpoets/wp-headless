import { useSearchParams } from 'react-router-dom'
import { usePosts } from '../hooks/use-posts'
import { useHead } from '../hooks/use-head'
import { PostCard } from '../components/post-card'
import { Pagination } from '../components/pagination'
import { Breadcrumbs } from '../components/breadcrumbs'
import styles from './cpt-archive.module.css'

/**
 * Custom post type archive — the URL like `/{cpt_slug}/` resolved by
 * the plugin's resolve_post_type_archive(). The queried_object is a
 * post_type summary with rest_base, label, archive_link.
 *
 * @param {{ runtime: object, request: object }} props
 */
export function CPTArchive({ runtime, request }) {
  const [params, setParams] = useSearchParams()
  const initialPage = request?.page ?? 1
  const page = Math.max(1, parseInt(params.get('page') || String(initialPage), 10))

  const pt = request?.queried_object
  const ptName = pt?.name
  const restBase = pt?.rest_base || ptName
  const label = pt?.label || ptName || 'Archive'
  const siteName = runtime?.site?.name

  useHead({
    title: siteName ? `${label} — ${siteName}` : label,
    description: pt?.description || `${label} archive`,
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
  })

  // Use the CPT's rest_base for the REST endpoint, not the hardcoded 'posts'.
  const fetchParams = restBase ? { per_page: 10, page, _rest_base: restBase } : null
  const { posts, totalPages, loading } = usePosts(runtime, fetchParams)

  const setPage = (n) => {
    const next = new URLSearchParams(params)
    if (n <= 1) next.delete('page')
    else next.set('page', String(n))
    setParams(next, { replace: false })
    window.scrollTo({ top: 0, behavior: 'instant' })
  }

  return (
    <div className={styles.wrap}>
      <Breadcrumbs items={[
        { label: 'Home', to: '/' },
        { label },
      ]} />

      <header className={styles.header}>
        <p className={styles.label}>Archive</p>
        <h1 className={styles.title}>{label}</h1>
        {pt?.description && <p className={styles.description}>{pt.description}</p>}
      </header>

      {loading && <p className={styles.loading}>Loading…</p>}
      {!loading && posts.length === 0 && (
        <p className={styles.empty}>No items in this archive.</p>
      )}

      <div className={styles.grid}>
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
