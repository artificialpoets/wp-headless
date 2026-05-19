import { useState } from 'react'
import { Link } from 'react-router-dom'
import { usePost } from '../hooks/use-post'
import { useHead } from '../hooks/use-head'
import { FeaturedImage } from '../components/featured-image'
import { Comments } from '../components/comments'
import { Breadcrumbs } from '../components/breadcrumbs'
import { BlockContent } from '../components/block-content'
import { formatDate } from '../lib/format'
import { articleSchema, breadcrumbsSchema } from '../lib/json-ld'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import type { BreadcrumbItem } from '../components/breadcrumbs'
import styles from './single.module.css'

interface SingleProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function Single({ runtime, request }: SingleProps) {
  const id = request?.queried_object?.id
  const { post, loading, error } = usePost(runtime, id ?? null, 'post')
  const language = runtime?.site?.language
  const timezone = runtime?.site?.timezone
  const siteName = runtime?.site?.name

  const plainTitle = post?.title?.rendered?.replace(/<[^>]+>/g, '') ?? ''
  const plainExcerpt = post?.excerpt?.rendered?.replace(/<[^>]+>/g, '').trim().slice(0, 200) ?? ''

  useHead({
    title: plainTitle && siteName ? `${plainTitle} — ${siteName}` : plainTitle || siteName,
    description: plainExcerpt,
    canonical: post?.permalink || request?.url,
    lang: language,
    siteName,
    ogType: 'article',
    ogImage: (post?.featured_image?.url as string) || undefined,
    robots: request?.robots,
    jsonLd: post
      ? [
          articleSchema(post, runtime?.site ?? null),
          breadcrumbsSchema([
            { label: 'Home', url: runtime?.site?.url },
            { label: plainTitle, url: post.permalink },
          ]),
        ].filter(Boolean) as object[]
      : undefined,
  })

  if (loading) return <div className={styles.wrap}><p className={styles.loading}>Loading…</p></div>
  if (error || !post) return <div className={styles.wrap}><p className={styles.error}>Could not load post.</p></div>

  const allTerms = post._embedded?.['wp:term']?.flat() ?? []
  const categories = allTerms.filter((t) => t.taxonomy === 'category')
  const tags = allTerms.filter((t) => t.taxonomy === 'post_tag')

  const isProtected = !!(post.content?.protected)
  const isSticky = !!post.sticky
  const format = post.format || 'standard'
  const adjacent = post.adjacent ?? { previous: null, next: null }

  const primaryCategory = categories[0]
  const crumbs: BreadcrumbItem[] = [
    { label: 'Home', to: '/' },
    ...(primaryCategory ? [{ label: primaryCategory.name, to: new URL(primaryCategory.link).pathname }] : []),
    { label: plainTitle },
  ]

  const formatClass = styles[`format-${format}` as keyof typeof styles] ?? ''

  return (
    <article className={`${styles.wrap} ${formatClass} ${isSticky ? styles.sticky : ''}`}>
      <Breadcrumbs items={crumbs} />

      <FeaturedImage image={post.featured_image} />

      {categories.length > 0 && (
        <div className={styles.categories}>
          {categories.map((cat) => (
            <Link key={cat.id} to={new URL(cat.link).pathname} className={styles.category}>
              {cat.name}
            </Link>
          ))}
        </div>
      )}

      <header className={styles.header}>
        <h1 className={styles.title} dangerouslySetInnerHTML={{ __html: post.title.rendered }} />
        <div className={styles.meta}>
          {post.author_info?.name && (
            <span className={styles.author}>
              {post.author_info.avatar && (
                <img
                  src={post.author_info.avatar}
                  alt=""
                  className={styles.avatar}
                  width={24}
                  height={24}
                  loading="lazy"
                />
              )}
              {post.author_info.link ? (
                <Link to={new URL(post.author_info.link).pathname}>{post.author_info.name}</Link>
              ) : (
                post.author_info.name
              )}
            </span>
          )}
          {post.date && (
            <time dateTime={post.date}>
              {formatDate(post.date, language, timezone)}
            </time>
          )}
          {post.modified && post.modified !== post.date && (
            <span className={styles.updated}>
              Updated {formatDate(post.modified, language, timezone)}
            </span>
          )}
          {typeof post.comment_count === 'number' && post.comment_count > 0 && (
            <span className={styles.commentMeta}>
              {post.comment_count} {post.comment_count === 1 ? 'comment' : 'comments'}
            </span>
          )}
        </div>
      </header>

      {isProtected ? (
        <PasswordGate runtime={runtime} postId={post.id} />
      ) : (
        <BlockContent
          html={post.content.rendered}
          runtime={runtime}
          className={styles.content}
        />
      )}

      {tags.length > 0 && (
        <div className={styles.tags}>
          {tags.map((tag) => (
            <Link key={tag.id} to={new URL(tag.link).pathname} className={styles.tag}>
              #{tag.name}
            </Link>
          ))}
        </div>
      )}

      {(adjacent.previous || adjacent.next) && (
        <nav className={styles.adjacent} aria-label="Post navigation">
          {adjacent.previous ? (
            <Link to={new URL(adjacent.previous.link).pathname} className={styles.prev}>
              <span className={styles.adjacentLabel}>← Previous</span>
              <span className={styles.adjacentTitle} dangerouslySetInnerHTML={{ __html: adjacent.previous.title }} />
            </Link>
          ) : <span />}
          {adjacent.next ? (
            <Link to={new URL(adjacent.next.link).pathname} className={styles.next}>
              <span className={styles.adjacentLabel}>Next →</span>
              <span className={styles.adjacentTitle} dangerouslySetInnerHTML={{ __html: adjacent.next.title }} />
            </Link>
          ) : <span />}
        </nav>
      )}

      <Comments runtime={runtime} postId={post.id} commentStatus={post.comment_status} />
    </article>
  )
}

interface PasswordGateProps {
  runtime: WPHeadlessRuntime | null
  postId: number
}

function PasswordGate({ runtime, postId }: PasswordGateProps) {
  const [submitting, setSubmitting] = useState(false)
  const siteUrl = runtime?.site?.url
  const action = siteUrl ? new URL('/wp-login.php?action=postpass', siteUrl).toString() : ''

  return (
    <form
      className={styles.passwordForm}
      method="post"
      action={action}
      onSubmit={() => setSubmitting(true)}
    >
      <p className={styles.passwordIntro}>
        This content is password protected. Enter the password below to view it.
      </p>
      <input type="hidden" name="post_id" value={postId} />
      <input
        type="password"
        name="post_password"
        placeholder="Password"
        className={styles.passwordInput}
        required
        autoComplete="current-password"
      />
      <button type="submit" className={styles.passwordButton} disabled={submitting}>
        {submitting ? 'Unlocking…' : 'Unlock'}
      </button>
    </form>
  )
}
