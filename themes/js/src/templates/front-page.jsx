import { useSearchParams } from 'react-router-dom'
import { usePosts } from '../hooks/use-posts'
import { useHead } from '../hooks/use-head'
import { PostCard } from '../components/post-card'
import { Pagination } from '../components/pagination'
import { websiteSchema, organizationSchema } from '../lib/json-ld'
import styles from './front-page.module.css'

/**
 * FrontPage — equivalent to WordPress front-page.php.
 *
 * Sticky posts are shown above the regular feed on page 1 only, deduplicated
 * from the regular query so they don't render twice.
 *
 * @param {{ runtime: object, request: object }} props
 */
export function FrontPage({ runtime, request }) {
  const [params, setParams] = useSearchParams()
  const initialPage = request?.page ?? 1
  const page = Math.max(1, parseInt(params.get('page') || String(initialPage), 10))

  const site = runtime?.site ?? {}
  const rssUrl = site.url ? new URL('/feed/', site.url).toString() : undefined

  // Sticky posts only on page 1.
  const { posts: stickies } = usePosts(runtime, page === 1
    ? { per_page: 5, sticky: 'true' }
    : null)
  const stickyIds = new Set((stickies ?? []).map((p) => p.id))

  const { posts: rawPosts, totalPages, loading } = usePosts(runtime, { per_page: 6, page })
  // Filter out sticky posts that would appear in the regular feed on page 1
  const posts = (rawPosts ?? []).filter((p) => !stickyIds.has(p.id))

  useHead({
    title: site.name,
    description: site.description,
    canonical: request?.url,
    lang: site.language,
    siteName: site.name,
    ogType: 'website',
    ogImage: site.logo?.url,
    rssUrl,
    robots: request?.robots,
    jsonLd: [websiteSchema(site), organizationSchema(site)].filter(Boolean),
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

      <section className={styles.hero}>
        {site.logo?.url && (
          <img src={site.logo.url} alt={site.name || ''} className={styles.heroLogo} />
        )}
        <h1 className={styles.siteName}>{site.name}</h1>
        {site.description && (
          <p className={styles.tagline}>{site.description}</p>
        )}
      </section>

      {page === 1 && stickies && stickies.length > 0 && (
        <section className={styles.sticky}>
          <header className={styles.postsHeader}>
            <h2 className={styles.postsHeading}>Featured</h2>
          </header>
          <div className={styles.grid}>
            {stickies.map((post) => (
              <PostCard key={post.id} post={post} runtime={runtime} featured />
            ))}
          </div>
        </section>
      )}

      <section className={styles.posts}>
        <header className={styles.postsHeader}>
          <h2 className={styles.postsHeading}>Latest</h2>
        </header>

        {loading && <p className={styles.loading}>Loading…</p>}

        {!loading && posts.length === 0 && (
          <p className={styles.empty}>No posts published yet.</p>
        )}

        <div className={styles.grid}>
          {posts.map((post) => (
            <PostCard key={post.id} post={post} runtime={runtime} />
          ))}
        </div>

        <Pagination currentPage={page} totalPages={totalPages} onPageChange={setPage} />
      </section>

    </div>
  )
}
