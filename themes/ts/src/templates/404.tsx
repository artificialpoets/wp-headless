import { Link } from 'react-router-dom'
import { useHead } from '../hooks/use-head'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import styles from './404.module.css'

interface NotFoundProps {
  runtime: WPHeadlessRuntime | null
}

export function NotFound({ runtime }: NotFoundProps) {
  const siteName = runtime?.site?.name

  useHead({
    title: siteName ? `Page Not Found — ${siteName}` : 'Page Not Found',
    description: 'The page you are looking for does not exist.',
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
  })

  return (
    <div className={styles.wrap}>
      <p className={styles.code}>404</p>
      <h1 className={styles.title}>Page not found</h1>
      <p className={styles.description}>
        The page you are looking for doesn't exist{siteName ? ` on ${siteName}` : ''} or has been moved.
      </p>
      <Link to="/" className={styles.home}>← Back to home</Link>
    </div>
  )
}
