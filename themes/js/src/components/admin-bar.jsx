import { useAuth } from '../hooks/use-auth'
import styles from './admin-bar.module.css'

/**
 * Minimal admin bar shown to logged-in users. Mirrors the most-used links
 * from WP's native admin bar without pulling in the WP-side script.
 *
 * Renders a fixed-top toolbar with:
 *   - "WP Admin" link
 *   - "New Post" link (if the user can publish posts)
 *   - "Edit Post" link (when `editPost` is supplied)
 *   - The current user's display name → profile
 *   - Logout link
 *
 * @param {{ runtime: object, editPost?: { id: number, post_type: string } }} props
 */
export function AdminBar({ runtime, editPost }) {
  const { user, isLoggedIn, can, urls } = useAuth(runtime)

  if (!isLoggedIn) return null

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

        {editUrl && (
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
