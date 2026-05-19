import { useSearchParams } from 'react-router-dom'
import { usePosts } from '../hooks/use-posts'
import { useHead } from '../hooks/use-head'
import { PostCard } from '../components/post-card'
import { Pagination } from '../components/pagination'
import { Breadcrumbs } from '../components/breadcrumbs'
import styles from './date.module.css'

const MONTHS = [
  '', 'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
]

/**
 * Date archive — year, month, or day.
 *
 * runtime.request.date_archive: { year, month?, day? }
 *
 * Uses WP REST date filters:
 *   ?after=YYYY-MM-DD&before=YYYY-MM-DD
 *
 * @param {{ runtime: object, request: object }} props
 */
export function DateArchive({ runtime, request }) {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, parseInt(params.get('page') || '1', 10))

  const { year, month, day } = request?.date_archive ?? {}
  const range = computeRange(year, month, day)
  const heading = formatHeading(year, month, day)
  const siteName = runtime?.site?.name

  useHead({
    title: siteName ? `${heading} — ${siteName}` : heading,
    description: `Archive for ${heading}`,
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
  })

  const { posts, totalPages, loading } = usePosts(runtime, range
    ? { per_page: 10, page, after: range.after, before: range.before }
    : null)

  const setPage = (n) => {
    const next = new URLSearchParams(params)
    if (n <= 1) next.delete('page')
    else next.set('page', String(n))
    setParams(next, { replace: false })
    window.scrollTo({ top: 0, behavior: 'instant' })
  }

  return (
    <div className={styles.wrap}>
      <Breadcrumbs items={buildCrumbs(year, month, day)} />

      <header className={styles.header}>
        <p className={styles.label}>Date archive</p>
        <h1 className={styles.title}>{heading}</h1>
      </header>

      {loading && <p className={styles.loading}>Loading…</p>}
      {!loading && posts.length === 0 && (
        <p className={styles.empty}>No posts for this date range.</p>
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

function pad(n) { return String(n).padStart(2, '0') }
function daysInMonth(y, m) { return new Date(Date.UTC(y, m, 0)).getUTCDate() }

function computeRange(year, month, day) {
  if (!year) return null
  if (day) {
    const date = `${year}-${pad(month)}-${pad(day)}`
    return {
      after: `${date}T00:00:00`,
      before: `${date}T23:59:59`,
    }
  }
  if (month) {
    const last = daysInMonth(year, month)
    return {
      after: `${year}-${pad(month)}-01T00:00:00`,
      before: `${year}-${pad(month)}-${pad(last)}T23:59:59`,
    }
  }
  return {
    after: `${year}-01-01T00:00:00`,
    before: `${year}-12-31T23:59:59`,
  }
}

function formatHeading(year, month, day) {
  if (!year) return 'Archive'
  if (day) return `${MONTHS[month]} ${day}, ${year}`
  if (month) return `${MONTHS[month]} ${year}`
  return String(year)
}

function buildCrumbs(year, month, day) {
  const out = [{ label: 'Home', to: '/' }]
  if (year) {
    out.push({ label: String(year), to: month || day ? `/${year}/` : undefined })
  }
  if (month) {
    out.push({ label: MONTHS[month], to: day ? `/${year}/${pad(month)}/` : undefined })
  }
  if (day) {
    out.push({ label: String(day) })
  }
  return out
}
