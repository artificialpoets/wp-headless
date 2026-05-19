import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useHead } from '../hooks/use-head'
import { Breadcrumbs } from '../components/breadcrumbs'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './auth.module.css'

interface LostPasswordProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function LostPassword({ runtime, request }: LostPasswordProps) {
  const siteName = runtime?.site?.name
  const [submitting, setSubmitting] = useState(false)

  useHead({
    title: siteName ? `Reset password — ${siteName}` : 'Reset password',
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'website',
  })

  const siteUrl = runtime?.site?.url ?? '/'
  const action = new URL('/wp-login.php?action=lostpassword', siteUrl).toString()

  return (
    <div className={styles.wrap}>
      <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: 'Reset password' }]} />
      <h1 className={styles.title}>Reset your password</h1>
      <p className={styles.intro}>
        Enter your username or email address. You'll receive a link to create a new password.
      </p>

      <form
        method="post"
        action={action}
        className={styles.form}
        onSubmit={() => setSubmitting(true)}
      >
        <label className={styles.label}>
          Username or Email
          <input type="text" name="user_login" className={styles.input} autoComplete="username" autoFocus required />
        </label>

        <input type="hidden" name="redirect_to" value="" />
        <input type="hidden" name="wp-submit" value="Get New Password" />

        <button type="submit" className={styles.submit} disabled={submitting}>
          {submitting ? 'Sending…' : 'Get New Password'}
        </button>
      </form>

      <p className={styles.footer}>
        <Link to="/login/">Back to sign in</Link>
      </p>
    </div>
  )
}
