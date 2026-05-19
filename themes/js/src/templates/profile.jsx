import { Link } from 'react-router-dom'
import { useAuth } from '../hooks/use-auth'
import { useHead } from '../hooks/use-head'
import { Breadcrumbs } from '../components/breadcrumbs'
import styles from './auth.module.css'

/**
 * Profile landing page. Anonymous visitors are redirected to /login/ via
 * a noscript fallback and a `Link` for the JS path.
 *
 * Provides quick links to:
 *   - WP admin profile editor (/wp-admin/profile.php)
 *   - Application Passwords (/profile/passwords/)
 *   - Log out
 *
 * @param {{ runtime: object, request: object }} props
 */
export function Profile({ runtime, request }) {
  const { user, isLoggedIn, urls } = useAuth(runtime)
  const siteName = runtime?.site?.name

  useHead({
    title: siteName ? `Profile — ${siteName}` : 'Profile',
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    robots: 'noindex, nofollow',
  })

  if (!isLoggedIn) {
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
          <a href={urls.profile} target="_blank" rel="noreferrer">Edit profile in WP Admin →</a>
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
          <a href={urls.logout}>Log out</a>
        </li>
      </ul>
    </div>
  )
}
