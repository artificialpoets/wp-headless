import { useSearchParams } from 'react-router-dom'
import { usePosts } from '../hooks/use-posts'
import { useHead } from '../hooks/use-head'
import { PostCard } from '../components/post-card'
import { Pagination } from '../components/pagination'
import { Breadcrumbs } from '../components/breadcrumbs'
import type { BreadcrumbItem } from '../components/breadcrumbs'
import { breadcrumbsSchema, collectionSchema } from '../lib/json-ld'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './archive.module.css'

interface ArchiveProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function Archive({ runtime, request }: ArchiveProps) {
  const [params, setParams] = useSearchParams()
  const initialPage = request?.page ?? 1
  const page = Math.max(1, parseInt(params.get('page') || String(initialPage), 10))

  const term = request.queried_object
  const taxonomy = term?.taxonomy ?? ''
  const termId = term?.id
  const ancestors = term?.ancestors ?? []
  const siteName = runtime?.site?.name
  const termName = term?.name ?? 'Archive'

  const crumbs: BreadcrumbItem[] = [
    { label: 'Home', to: '/' },
    { label: taxonomyLabel(taxonomy) },
    ...ancestors.map((a) => ({
      label: a.name,
      to: a.link ? new URL(a.link).pathname : undefined,
    })),
    { label: termName },
  ]

  useHead({
    title: siteName ? `${termName} — ${siteName}` : termName,
    description: term?.description || `${taxonomyLabel(taxonomy)}: ${termName}`,
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
    robots: request?.robots,
    jsonLd: [
      collectionSchema({ url: request?.url, name: termName, description: term?.description }),
      breadcrumbsSchema(
        crumbs.map((c) => ({
          label: c.label,
          url: c.to ? new URL(c.to, runtime?.site?.url || 'http://localhost/').toString() : undefined,
        }))
      ),
    ].filter(Boolean) as object[],
  })

  const restParams: Record<string, string | number> | null = termId
    ? { per_page: 10, page, [taxonomyToParam(taxonomy)]: termId }
    : null

  const { posts, totalPages, loading } = usePosts(runtime, restParams)

  const setPage = (n: number) => {
    const next = new URLSearchParams(params)
    if (n <= 1) next.delete('page')
    else next.set('page', String(n))
    setParams(next, { replace: false })
    window.scrollTo({ top: 0, behavior: 'instant' })
  }

  return (
    <div className={styles.wrap}>
      <Breadcrumbs items={crumbs} />

      <header className={styles.header}>
        <p className={styles.label}>{taxonomyLabel(taxonomy)}</p>
        <h1 className={styles.title}>{termName}</h1>
        {term?.description && (
          <p className={styles.description}>{term.description}</p>
        )}
      </header>

      {loading && <p className={styles.loading}>Loading…</p>}

      {!loading && posts.length === 0 && (
        <p className={styles.empty}>No posts in this archive.</p>
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

function taxonomyToParam(taxonomy: string): string {
  if (taxonomy === 'category') return 'categories'
  if (taxonomy === 'post_tag') return 'tags'
  return taxonomy
}

function taxonomyLabel(taxonomy: string): string {
  if (taxonomy === 'category') return 'Category'
  if (taxonomy === 'post_tag') return 'Tag'
  return taxonomy || 'Archive'
}
