import { Link } from 'react-router-dom'
import { useHead } from '../hooks/use-head'
import { Breadcrumbs } from '../components/breadcrumbs'
import type { BreadcrumbItem } from '../components/breadcrumbs'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './attachment.module.css'

interface AttachmentProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function Attachment({ runtime, request }: AttachmentProps) {
  const att = request?.queried_object
  const siteName = runtime?.site?.name
  const title = att?.title || 'Attachment'

  useHead({
    title: siteName ? `${title} — ${siteName}` : title,
    description: att?.caption || att?.alt || `Media: ${title}`,
    canonical: att?.link || request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'article',
    ogImage: att?.mime?.startsWith('image/') ? att?.url : undefined,
  })

  if (!att) {
    return <div className={styles.wrap}><p>Attachment not found.</p></div>
  }

  const isImage = att.mime?.startsWith('image/')
  const isVideo = att.mime?.startsWith('video/')
  const isAudio = att.mime?.startsWith('audio/')

  const crumbs: BreadcrumbItem[] = [
    { label: 'Home', to: '/' },
    ...(att.parent_link ? [{ label: 'Parent', to: new URL(att.parent_link).pathname }] : []),
    { label: title },
  ]

  return (
    <article className={styles.wrap}>
      <Breadcrumbs items={crumbs} />

      <h1 className={styles.title}>{title}</h1>

      <div className={styles.media}>
        {isImage && att.url && (
          <img
            src={att.url}
            alt={att.alt || ''}
            srcSet={att.srcset || undefined}
            sizes={att.sizes || undefined}
            width={att.width || undefined}
            height={att.height || undefined}
            className={styles.image}
            decoding="async"
          />
        )}
        {isVideo && att.url && (
          <video controls className={styles.video} src={att.url} />
        )}
        {isAudio && att.url && (
          <audio controls className={styles.audio} src={att.url} />
        )}
        {!isImage && !isVideo && !isAudio && att.url && (
          <a href={att.url} className={styles.downloadLink} download>
            Download {title} ({att.mime})
          </a>
        )}
      </div>

      {att.caption && (
        <p className={styles.caption} dangerouslySetInnerHTML={{ __html: att.caption }} />
      )}

      {att.description && (
        <div className={styles.description} dangerouslySetInnerHTML={{ __html: att.description }} />
      )}

      <dl className={styles.meta}>
        <dt>Type</dt><dd>{att.mime}</dd>
        {att.width && att.height && <><dt>Dimensions</dt><dd>{att.width} × {att.height}</dd></>}
        {att.parent_link && (
          <>
            <dt>Attached to</dt>
            <dd><Link to={new URL(att.parent_link).pathname}>Parent post</Link></dd>
          </>
        )}
      </dl>
    </article>
  )
}
