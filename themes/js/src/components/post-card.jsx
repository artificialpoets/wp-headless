import { Link } from 'react-router-dom'
import { formatDateShort } from '../lib/format'
import styles from './post-card.module.css'

/**
 * A single post card for use in post lists.
 *
 * @param {{ post: object, runtime: object, featured?: boolean }} props
 */
export function PostCard({ post, runtime, featured = false }) {
  const url = new URL(post.link)
  const path = url.pathname
  const language = runtime?.site?.language
  const timezone = runtime?.site?.timezone

  const categories = post._embedded?.['wp:term']?.flat().filter((t) => t.taxonomy === 'category') ?? []
  const format = post.format && post.format !== 'standard' ? post.format : null
  const isSticky = !!post.sticky
  const commentCount = typeof post.comment_count === 'number' ? post.comment_count : null

  return (
    <article className={`${styles.card} ${featured ? styles.featured : ''} ${format ? styles[`format-${format}`] : ''}`}>
      {post.featured_image?.url && (
        <Link to={path} className={styles.imageLink} tabIndex={-1} aria-hidden="true">
          <img
            src={post.featured_image.url}
            alt={post.featured_image.alt || ''}
            srcSet={post.featured_image.srcset || undefined}
            sizes={post.featured_image.srcset ? '(max-width: 600px) 100vw, 33vw' : undefined}
            width={post.featured_image.width || undefined}
            height={post.featured_image.height || undefined}
            className={styles.image}
            loading="lazy"
            decoding="async"
          />
        </Link>
      )}
      <div className={styles.body}>
        <div className={styles.topRow}>
          {(isSticky || format) && (
            <span className={styles.badge}>
              {isSticky && <span aria-label="Sticky post">★</span>}
              {format && <span>{format}</span>}
            </span>
          )}
          {categories.length > 0 && (
            <div className={styles.categories}>
              {categories.slice(0, 2).map((cat) => (
                <Link key={cat.id} to={new URL(cat.link).pathname} className={styles.category}>
                  {cat.name}
                </Link>
              ))}
            </div>
          )}
        </div>
        <h2 className={styles.title}>
          <Link to={path} dangerouslySetInnerHTML={{ __html: post.title.rendered }} />
        </h2>
        <div className={styles.excerpt} dangerouslySetInnerHTML={{ __html: post.excerpt.rendered }} />
        <div className={styles.meta}>
          {post.author_info?.name && (
            <span className={styles.author}>{post.author_info.name}</span>
          )}
          {post.date && (
            <time dateTime={post.date} className={styles.date}>
              {formatDateShort(post.date, language, timezone)}
            </time>
          )}
          {commentCount !== null && commentCount > 0 && (
            <span className={styles.commentCount} aria-label={`${commentCount} ${commentCount === 1 ? 'comment' : 'comments'}`}>
              💬 {commentCount}
            </span>
          )}
        </div>
      </div>
    </article>
  )
}
