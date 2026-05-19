import { useSearchParams } from 'react-router-dom'
import { usePosts } from '../hooks/use-posts'
import { useAuthor } from '../hooks/use-author'
import { useHead } from '../hooks/use-head'
import { PostCard } from '../components/post-card'
import { Pagination } from '../components/pagination'
import { Breadcrumbs } from '../components/breadcrumbs'
import { personSchema, breadcrumbsSchema } from '../lib/json-ld'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './author.module.css'

interface AuthorProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function Author({ runtime, request }: AuthorProps) {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, parseInt(params.get('page') || '1', 10))

  const queried = request?.queried_object
  const authorId = queried?.id
  const { user } = useAuthor(runtime, authorId ?? null)

  const name = user?.name ?? queried?.display_name ?? 'Author'
  const description = user?.description ?? queried?.description ?? ''
  const avatar = user?.avatar_urls?.['96'] ?? queried?.avatar ?? undefined
  const siteName = runtime?.site?.name

  useHead({
    title: siteName ? `${name} — ${siteName}` : name,
    description: description || `Posts by ${name}`,
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'profile',
    ogImage: avatar || undefined,
    robots: request?.robots,
    jsonLd: [
      personSchema(
        { display_name: name, description, avatar, link: request?.url, author_link: request?.url },
        runtime?.site ?? null
      ),
      breadcrumbsSchema([
        { label: 'Home', url: runtime?.site?.url },
        { label: name, url: request?.url },
      ]),
    ].filter(Boolean) as object[],
  })

  const restParams: Record<string, string | number> | null = authorId
    ? { per_page: 10, page, author: authorId }
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
      <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: name }]} />

      <header className={styles.header}>
        {avatar && (
          <img src={avatar} alt="" className={styles.avatar} width={96} height={96} />
        )}
        <div>
          <p className={styles.label}>Author</p>
          <h1 className={styles.title}>{name}</h1>
          {description && <p className={styles.bio}>{description}</p>}
        </div>
      </header>

      {loading && <p className={styles.loading}>Loading…</p>}
      {!loading && posts.length === 0 && (
        <p className={styles.empty}>No posts by this author yet.</p>
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
