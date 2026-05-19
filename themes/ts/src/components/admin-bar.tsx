import { useAuth } from '../hooks/use-auth'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import styles from './admin-bar.module.css'

interface EditPost {
  id: number
  post_type: string
  author?: number
}

interface AdminBarProps {
  runtime: WPHeadlessRuntime | null
  editPost?: EditPost | null
}

export function AdminBar({ runtime, editPost }: AdminBarProps) {
  const { user, isLoggedIn, can, urls } = useAuth(runtime)

  if (!isLoggedIn || !user) return null

  const adminUrl = urls.admin || '/wp-admin/'
  const newPostUrl = adminUrl + 'post-new.php'
  const editUrl = editPost
    ? `${adminUrl}post.php?post=${editPost.id}&action=edit`
    : null

  return (
    <div className={styles.bar} role="navigation" aria-label="Admin toolbar">
      <div className={styles.inner}>
        <a href={adminUrl} className={styles.brand}>
          <span aria-hidden="true">⌂</span>
          <span>{runtime?.site?.name || 'WP Admin'}</span>
        </a>

        {can('publish_posts') && (
          <a href={newPostUrl} className={styles.link}>+ New Post</a>
        )}

        {editUrl && editPost && (
          <a href={editUrl} className={styles.link}>Edit {editPost.post_type === 'page' ? 'Page' : 'Post'}</a>
        )}

        <div className={styles.spacer} />

        <a href={urls.profile || `${adminUrl}profile.php`} className={styles.user}>
          {user.avatar && (
            <img src={user.avatar} alt="" className={styles.avatar} width={20} height={20} />
          )}
          <span>{user.display_name}</span>
        </a>

        <a href={urls.logout} className={styles.link}>Log out</a>
      </div>
    </div>
  )
}
