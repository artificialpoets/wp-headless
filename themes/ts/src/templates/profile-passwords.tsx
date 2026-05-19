import { useState, useEffect, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../hooks/use-auth'
import { useHead } from '../hooks/use-head'
import { Breadcrumbs } from '../components/breadcrumbs'
import { fetchAppPasswords, createAppPassword, deleteAppPassword } from '../lib/api'
import type { AppPassword, NewAppPassword } from '../lib/api'
import { formatDate } from '../lib/format'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './profile-passwords.module.css'

interface ProfilePasswordsProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function ProfilePasswords({ runtime, request }: ProfilePasswordsProps) {
  const { isLoggedIn } = useAuth(runtime)
  const siteName = runtime?.site?.name
  const language = runtime?.site?.language
  const timezone = runtime?.site?.timezone

  const [passwords, setPasswords] = useState<AppPassword[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [newSecret, setNewSecret] = useState<NewAppPassword | null>(null)

  useHead({
    title: siteName ? `Application Passwords — ${siteName}` : 'Application Passwords',
    canonical: request?.url,
    lang: runtime?.site?.language,
    siteName,
    robots: 'noindex, nofollow',
  })

  useEffect(() => {
    if (!isLoggedIn) {
      setLoading(false)
      return
    }
    let cancelled = false
    fetchAppPasswords(runtime)
      .then((data) => { if (!cancelled) { setPasswords(data); setLoading(false) } })
      .catch((err: Error) => { if (!cancelled) { setError(err.message); setLoading(false) } })
    return () => { cancelled = true }
  }, [isLoggedIn])

  if (!isLoggedIn) {
    return (
      <div className={styles.wrap}>
        <Breadcrumbs items={[
          { label: 'Home', to: '/' },
          { label: 'Profile', to: '/profile/' },
          { label: 'Application Passwords' },
        ]} />
        <h1 className={styles.title}>Please sign in</h1>
        <p className={styles.intro}>You need to be logged in to manage application passwords.</p>
        <Link to="/login/" className={styles.primary}>Sign in</Link>
      </div>
    )
  }

  async function handleCreate(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      const result = await createAppPassword(runtime, { name: name.trim() })
      setNewSecret(result)
      setName('')
      const list = await fetchAppPasswords(runtime)
      setPasswords(list)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(uuid: string) {
    if (!window.confirm('Revoke this application password? This cannot be undone.')) return
    try {
      await deleteAppPassword(runtime, uuid)
      setPasswords(passwords.filter((p) => p.uuid !== uuid))
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }

  return (
    <div className={styles.wrap}>
      <Breadcrumbs items={[
        { label: 'Home', to: '/' },
        { label: 'Profile', to: '/profile/' },
        { label: 'Application Passwords' },
      ]} />
      <h1 className={styles.title}>Application Passwords</h1>
      <p className={styles.intro}>
        Use application passwords with the REST API or third-party tools that need to
        access {siteName} on your behalf, without giving out your main password.
      </p>

      {newSecret && (
        <div className={styles.success}>
          <h2 className={styles.successTitle}>New password generated</h2>
          <p>
            Copy this password now — <strong>you won't be able to see it again</strong>.
          </p>
          <pre className={styles.secret}>{newSecret.password}</pre>
          <button
            type="button"
            className={styles.dismiss}
            onClick={() => setNewSecret(null)}
          >
            I've saved it
          </button>
        </div>
      )}

      <section className={styles.section}>
        <h2 className={styles.sectionTitle}>Create new</h2>
        <form onSubmit={handleCreate} className={styles.form}>
          <label className={styles.label}>
            App name
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              placeholder="e.g. iOS Shortcut, Editorial Workflow"
              className={styles.input}
            />
          </label>
          <button type="submit" className={styles.submit} disabled={submitting || !name.trim()}>
            {submitting ? 'Creating…' : 'Create'}
          </button>
        </form>
        {error && <p className={styles.error}>{error}</p>}
      </section>

      <section className={styles.section}>
        <h2 className={styles.sectionTitle}>Active ({passwords.length})</h2>
        {loading && <p>Loading…</p>}
        {!loading && passwords.length === 0 && (
          <p className={styles.empty}>You haven't created any application passwords yet.</p>
        )}
        {passwords.length > 0 && (
          <ul className={styles.list}>
            {passwords.map((p) => (
              <li key={p.uuid} className={styles.item}>
                <div>
                  <strong className={styles.name}>{p.name}</strong>
                  <div className={styles.meta}>
                    Created {formatDate(p.created, language, timezone)}
                    {p.last_used && <> · Last used {formatDate(p.last_used, language, timezone)}</>}
                    {p.last_ip && <> · {p.last_ip}</>}
                  </div>
                </div>
                <button
                  type="button"
                  className={styles.revoke}
                  onClick={() => handleDelete(p.uuid)}
                  aria-label={`Revoke ${p.name}`}
                >
                  Revoke
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <p className={styles.footer}>
        <Link to="/profile/">← Back to profile</Link>
      </p>
    </div>
  )
}
