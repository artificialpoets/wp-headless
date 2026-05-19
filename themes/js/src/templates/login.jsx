import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../hooks/use-auth'
import { useHead } from '../hooks/use-head'
import { Breadcrumbs } from '../components/breadcrumbs'
import styles from './auth.module.css'

/**
 * Login template — submits to WordPress's /wp-login.php endpoint.
 *
 * After successful auth WP redirects back to the site root (or wherever
 * `redirect_to` points). On failure, WP redirects back to /wp-login.php
 * with an error which our React app handles by parsing `?login=failed`.
 *
 * @param {{ runtime: object, request: object }} props
 */
export function Login({ runtime, request }) {
  const { isLoggedIn, urls, user } = useAuth(runtime)
  const siteName = runtime?.site?.name
  const [submitting, setSubmitting] = useState(false)
  const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '')
  const failed = params.get('login') === 'failed'
  const reauth = params.get('reauth') === '1'

  useHead({
    title: siteName ? `Sign in — ${siteName}` : 'Sign in',
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
  })

  // Action target on wp-login.php
  const siteUrl = runtime?.site?.url ?? '/'
  const action = new URL('/wp-login.php', siteUrl).toString()
  const redirectTo = params.get('redirect_to') || siteUrl

  if (isLoggedIn) {
    return (
      <div className={styles.wrap}>
        <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: 'Sign in' }]} />
        <h1 className={styles.title}>You're signed in</h1>
        <p className={styles.intro}>
          Signed in as <strong>{user.display_name}</strong>.
        </p>
        <p className={styles.actions}>
          <a href={urls.admin} className={styles.primary}>Go to WP Admin</a>
          <a href={urls.logout} className={styles.secondary}>Log out</a>
        </p>
      </div>
    )
  }

  return (
    <div className={styles.wrap}>
      <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: 'Sign in' }]} />
      <h1 className={styles.title}>Sign in to {siteName}</h1>

      {failed && (
        <p className={styles.error}>
          Login failed. Check your username and password and try again.
        </p>
      )}
      {reauth && !failed && (
        <p className={styles.notice}>
          Please log in again to continue.
        </p>
      )}

      <form
        method="post"
        action={action}
        className={styles.form}
        onSubmit={() => setSubmitting(true)}
      >
        <label className={styles.label}>
          Username or Email
          <input
            type="text"
            name="log"
            className={styles.input}
            autoComplete="username"
            autoFocus
            required
          />
        </label>
        <label className={styles.label}>
          Password
          <input
            type="password"
            name="pwd"
            className={styles.input}
            autoComplete="current-password"
            required
          />
        </label>

        <label className={styles.checkboxLabel}>
          <input type="checkbox" name="rememberme" value="forever" defaultChecked />
          Remember me
        </label>

        <input type="hidden" name="redirect_to" value={redirectTo} />

        <button type="submit" className={styles.submit} disabled={submitting}>
          {submitting ? 'Signing in…' : 'Sign In'}
        </button>
      </form>

      <p className={styles.footer}>
        <Link to="/lost-password/">Lost your password?</Link>
        {urls.registrationEnabled && (
          <>
            {' · '}
            <Link to="/register/">Create an account</Link>
          </>
        )}
      </p>
    </div>
  )
}
