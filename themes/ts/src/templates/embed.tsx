import { useEffect } from 'react'
import { usePost } from '../hooks/use-post'
import { formatDate } from '../lib/format'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './embed.module.css'

interface EmbedProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function Embed({ runtime, request }: EmbedProps) {
  const id = request?.queried_object?.id
  const postType = request?.queried_object?.post_type ?? 'post'
  const { post } = usePost(runtime, id ?? null, postType)
  const site = runtime?.site

  useEffect(() => {
    if (typeof window === 'undefined') return
    function postHeight() {
      const height = document.documentElement.scrollHeight
      try {
        window.parent.postMessage({ message: 'height', value: height, secret: '' }, '*')
      } catch {
        // Cross-origin parent — ignore.
      }
    }
    postHeight()
    const observer = new ResizeObserver(() => postHeight())
    observer.observe(document.documentElement)

    function onClick(e: MouseEvent) {
      const anchor = (e.target as HTMLElement)?.closest?.('a')
      if (!anchor) return
      anchor.target = '_top'
    }
    document.addEventListener('click', onClick)

    return () => {
      observer.disconnect()
      document.removeEventListener('click', onClick)
    }
  }, [post?.id])

  if (!post) {
    return <div className={styles.wrap}><p className={styles.loading}>Loading…</p></div>
  }

  const language = site?.language
  const timezone = site?.timezone

  return (
    <article className={styles.wrap}>
      <header className={styles.header}>
        {site?.favicon?.url && (
          <img src={site.favicon.url as string} alt="" className={styles.favicon} width={32} height={32} />
        )}
        <a href={site?.url} className={styles.siteName} target="_top">{site?.name}</a>
      </header>

      <div className={styles.body}>
        {post.featured_image?.url && (
          <a href={post.permalink} target="_top" className={styles.imageLink}>
            <img
              src={post.featured_image.url as string}
              alt={post.featured_image.alt || ''}
              className={styles.image}
              loading="lazy"
            />
          </a>
        )}
        <h1 className={styles.title}>
          <a href={post.permalink} target="_top" dangerouslySetInnerHTML={{ __html: post.title.rendered }} />
        </h1>
        <div className={styles.excerpt} dangerouslySetInnerHTML={{ __html: post.excerpt.rendered }} />
        <div className={styles.meta}>
          {post.author_info?.name && <span>{post.author_info.name}</span>}
          {post.date && <time dateTime={post.date}>{formatDate(post.date, language, timezone)}</time>}
        </div>
      </div>
    </article>
  )
}
