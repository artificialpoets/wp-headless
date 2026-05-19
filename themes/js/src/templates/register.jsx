import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../hooks/use-auth'
import { useHead } from '../hooks/use-head'
import { Breadcrumbs } from '../components/breadcrumbs'
import styles from './auth.module.css'

/**
 * Register template — submits to /wp-login.php?action=register.
 *
 * Behaves like the default WP signup form. On success WP shows an inline
 * confirmation page; on failure it redirects to /wp-login.php with
 * `?action=register&user_email=&user_login=&error_msg=...`.
 *
 * @param {{ runtime: object, request: object }} props
 */
export function Register({ runtime, request }) {
  const { isLoggedIn, urls } = useAuth(runtime)
  const siteName = runtime?.site?.name
  const [submitting, setSubmitting] = useState(false)
  const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '')
  const error = params.get('error_msg')

  useHead({
    title: siteName ? `Create an account — ${siteName}` : 'Create an account',
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
  })

  const siteUrl = runtime?.site?.url ?? '/'
  const action = new URL('/wp-login.php?action=register', siteUrl).toString()

  if (isLoggedIn) {
    return (
      <div className={styles.wrap}>
        <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: 'Register' }]} />
        <h1 className={styles.title}>You already have an account</h1>
        <p className={styles.intro}>You are signed in. <a href={urls.logout}>Log out</a> first if you want to register a new account.</p>
      </div>
    )
  }

  if (!urls.registrationEnabled) {
    return (
      <div className={styles.wrap}>
        <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: 'Register' }]} />
        <h1 className={styles.title}>Registration is disabled</h1>
        <p className={styles.intro}>
          New user registration isn't enabled on this site.{' '}
          <Link to="/login/">Sign in</Link> if you already have an account.
        </p>
      </div>
    )
  }

  return (
    <div className={styles.wrap}>
      <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: 'Register' }]} />
      <h1 className={styles.title}>Create your account</h1>

      {error && <p className={styles.error}>{error}</p>}

      <form
        method="post"
        action={action}
        className={styles.form}
        onSubmit={() => setSubmitting(true)}
      >
        <label className={styles.label}>
          Username
          <input
            type="text"
            name="user_login"
            className={styles.input}
            autoComplete="username"
            autoFocus
            required
          />
        </label>

        <label className={styles.label}>
          Email
          <input
            type="email"
            name="user_email"
            className={styles.input}
            autoComplete="email"
            required
          />
        </label>

        <p className={styles.intro}>
          Registration confirmation will be emailed to you.
        </p>

        <button type="submit" className={styles.submit} disabled={submitting}>
          {submitting ? 'Registering…' : 'Register'}
        </button>
      </form>

      <p className={styles.footer}>
        <Link to="/login/">Already have an account? Sign in</Link>
      </p>
    </div>
  )
}
