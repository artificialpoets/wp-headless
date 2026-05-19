import { useSearchParams } from 'react-router-dom'
import { usePosts } from '../hooks/use-posts'
import { useHead } from '../hooks/use-head'
import { PostCard } from '../components/post-card'
import { Pagination } from '../components/pagination'
import { Breadcrumbs } from '../components/breadcrumbs'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './cpt-archive.module.css'

interface CPTArchiveProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function CPTArchive({ runtime, request }: CPTArchiveProps) {
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

  const fetchParams: Record<string, string | number> | null = restBase
    ? { per_page: 10, page, _rest_base: restBase }
    : null
  const { posts, totalPages, loading } = usePosts(runtime, fetchParams)

  const setPage = (n: number) => {
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
