import { useState } from 'react'
import { usePost } from '../hooks/use-post'
import { useHead } from '../hooks/use-head'
import { FeaturedImage } from '../components/featured-image'
import { Comments } from '../components/comments'
import { Breadcrumbs } from '../components/breadcrumbs'
import { BlockContent } from '../components/block-content'
import { breadcrumbsSchema } from '../lib/json-ld'
import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import styles from './page.module.css'

interface PageProps {
  runtime: WPHeadlessRuntime | null
  request: WPHeadlessRequest
}

export function Page({ runtime, request }: PageProps) {
  const id = request.queried_object?.id
  const { post, loading, error } = usePost(runtime, id ?? null, 'page')
  const siteName = runtime?.site?.name

  const plainTitle = post?.title?.rendered?.replace(/<[^>]+>/g, '') ?? ''
  const plainExcerpt = post?.excerpt?.rendered?.replace(/<[^>]+>/g, '').trim().slice(0, 200) ?? ''

  useHead({
    title: plainTitle && siteName ? `${plainTitle} — ${siteName}` : plainTitle || siteName,
    description: plainExcerpt,
    canonical: post?.permalink || request?.url,
    lang: runtime?.site?.language,
    siteName,
    ogType: 'article',
    ogImage: (post?.featured_image?.url as string) || undefined,
    robots: request?.robots,
    jsonLd: post
      ? breadcrumbsSchema([
          { label: 'Home', url: runtime?.site?.url },
          { label: plainTitle, url: post.permalink },
        ])
      : undefined,
  })

  if (loading) return <div className={styles.wrap}><p className={styles.loading}>Loading…</p></div>
  if (error || !post) return <div className={styles.wrap}><p className={styles.error}>Could not load page.</p></div>

  const isProtected = !!(post.content?.protected)

  return (
    <article className={styles.wrap}>
      <Breadcrumbs items={[{ label: 'Home', to: '/' }, { label: plainTitle }]} />

      <FeaturedImage image={post.featured_image} />

      <h1
        className={styles.title}
        dangerouslySetInnerHTML={{ __html: post.title.rendered }}
      />

      {isProtected ? (
        <PasswordGate runtime={runtime} postId={post.id} />
      ) : (
        <BlockContent
          html={post.content.rendered}
          runtime={runtime}
          className={styles.content}
        />
      )}

      <Comments runtime={runtime} postId={post.id} commentStatus={post.comment_status} />
    </article>
  )
}

interface PasswordGateProps {
  runtime: WPHeadlessRuntime | null
  postId: number
}

function PasswordGate({ runtime, postId }: PasswordGateProps) {
  const [submitting, setSubmitting] = useState(false)
  const siteUrl = runtime?.site?.url
  const action = siteUrl ? new URL('/wp-login.php?action=postpass', siteUrl).toString() : ''

  return (
    <form
      className={styles.passwordForm}
      method="post"
      action={action}
      onSubmit={() => setSubmitting(true)}
    >
      <p className={styles.passwordIntro}>
        This content is password protected. Enter the password below to view it.
      </p>
      <input type="hidden" name="post_id" value={postId} />
      <input
        type="password"
        name="post_password"
        placeholder="Password"
        className={styles.passwordInput}
        required
        autoComplete="current-password"
      />
      <button type="submit" className={styles.passwordButton} disabled={submitting}>
        {submitting ? 'Unlocking…' : 'Unlock'}
      </button>
    </form>
  )
}
