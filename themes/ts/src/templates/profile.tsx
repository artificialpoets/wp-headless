import { Link } from 'react-router-dom'
import { useAuth } from '../hooks/use-auth'
import { useHead } from '../hooks/use-head'
import { Breadcrumbs } from '../components/breadcrumbs'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './auth.module.css'

interface ProfileProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function Profile({ runtime, request }: ProfileProps) {
  const { user, isLoggedIn, urls } = useAuth(runtime)
  const siteName = runtime?.site?.name

  useHead({
    title: siteName ? `Profile — ${siteName}` : 'Profile',
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    robots: 'noindex, nofollow',
  })

  if (!isLoggedIn || !user) {
    return (
      <div className={styles.wrap}>
        <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: 'Profile' }]} />
        <h1 className={styles.title}>Please sign in</h1>
        <p className={styles.intro}>You need to be logged in to view your profile.</p>
        <p className={styles.actions}>
          <Link to="/login/" className={styles.primary}>Sign in</Link>
        </p>
      </div>
    )
  }

  return (
    <div className={styles.wrap}>
      <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: 'Profile' }]} />
      <h1 className={styles.title}>Hi, {user.display_name}</h1>
      <p className={styles.intro}>
        Logged in as <strong>{user.username}</strong>
        {user.email && <> ({user.email})</>}.
      </p>

      <ul className={styles.list}>
        <li>
          {urls.profile && <a href={urls.profile} target="_blank" rel="noreferrer">Edit profile in WP Admin →</a>}
        </li>
        <li>
          <Link to="/profile/passwords/">Application Passwords</Link>
        </li>
        {user.author_link && (
          <li>
            <Link to={new URL(user.author_link).pathname}>Your public author page →</Link>
          </li>
        )}
        <li>
          {urls.logout && <a href={urls.logout}>Log out</a>}
        </li>
      </ul>
    </div>
  )
}
