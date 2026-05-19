import { useState } from 'react'
import { useComments } from '../hooks/use-comments'
import { postComment, WPApiError } from '../lib/api'
import { formatDate } from '../lib/format'
import { Pagination } from './pagination'
import type { WPHeadlessRuntime } from '../types/wp-headless'
import type { WPComment } from '../types/api'
import styles from './comments.module.css'

interface CommentsProps {
  runtime: WPHeadlessRuntime | null
  postId: number
  commentStatus: 'open' | 'closed'
}

interface CommentNode extends WPComment {
  children: CommentNode[]
}

export function Comments({ runtime, postId, commentStatus }: CommentsProps) {
  const [page, setPage] = useState(1)
  const { comments, loading, total, totalPages } = useComments(runtime, postId, { page })
  const [replyTo, setReplyTo] = useState<number | null>(null)
  const isOpen = commentStatus === 'open'

  const tree = buildCommentTree(comments)
  const displayCount = total || comments.length

  return (
    <section className={styles.wrap} id="comments-section">
      <h2 className={styles.heading}>
        {loading
          ? 'Comments'
          : displayCount > 0
            ? `${displayCount} Comment${displayCount !== 1 ? 's' : ''}`
            : 'Comments'}
      </h2>

      {loading && <p className={styles.status}>Loading comments…</p>}

      {!loading && displayCount === 0 && isOpen && (
        <p className={styles.status}>Be the first to comment.</p>
      )}

      {comments.length > 0 && (
        <ol className={styles.list}>
          {tree.map((node) => (
            <CommentNodeView
              key={node.id}
              comment={node}
              runtime={runtime}
              isOpen={isOpen}
              replyTo={replyTo}
              setReplyTo={setReplyTo}
            />
          ))}
        </ol>
      )}

      {totalPages > 1 && (
        <div className={styles.pagination}>
          <Pagination
            currentPage={page}
            totalPages={totalPages}
            onPageChange={(n) => {
              setPage(n)
              setReplyTo(null)
              if (typeof document !== 'undefined') {
                document.getElementById('comments-section')?.scrollIntoView({ behavior: 'instant' })
              }
            }}
          />
        </div>
      )}

      {isOpen && replyTo === null && (
        <CommentForm runtime={runtime} postId={postId} parent={0} />
      )}

      {!isOpen && !loading && (
        <p className={styles.status}>Comments are closed.</p>
      )}
    </section>
  )
}

interface CommentNodeProps {
  comment: CommentNode
  runtime: WPHeadlessRuntime | null
  isOpen: boolean
  replyTo: number | null
  setReplyTo: (id: number | null) => void
  depth?: number
}

function CommentNodeView({ comment, runtime, isOpen, replyTo, setReplyTo, depth = 0 }: CommentNodeProps) {
  const language = runtime?.site?.language
  const timezone = runtime?.site?.timezone

  return (
    <li className={styles.comment} id={`comment-${comment.id}`} style={{ marginLeft: depth ? '1rem' : 0 }}>
      <div className={styles.commentMeta}>
        {comment.author_avatar_urls?.['48'] && (
          <img
            src={comment.author_avatar_urls['48']}
            alt=""
            className={styles.avatar}
            width="40"
            height="40"
            loading="lazy"
          />
        )}
        <div>
          <strong className={styles.commentAuthor}>{comment.author_name}</strong>
          <time className={styles.commentDate} dateTime={comment.date}>
            {formatDate(comment.date, language, timezone)}
          </time>
        </div>
      </div>

      <div
        className={styles.commentContent}
        dangerouslySetInnerHTML={{ __html: comment.content.rendered }}
      />

      {isOpen && (
        <div className={styles.commentActions}>
          <button
            type="button"
            className={styles.replyButton}
            aria-label={replyTo === comment.id
              ? `Cancel reply to ${comment.author_name}`
              : `Reply to ${comment.author_name}`}
            aria-expanded={replyTo === comment.id}
            onClick={() => setReplyTo(replyTo === comment.id ? null : comment.id)}
          >
            {replyTo === comment.id ? 'Cancel' : 'Reply'}
          </button>
        </div>
      )}

      {isOpen && replyTo === comment.id && (
        <CommentForm
          runtime={runtime}
          postId={comment.post}
          parent={comment.id}
          onDone={() => setReplyTo(null)}
          inline
        />
      )}

      {comment.children?.length > 0 && (
        <ol className={styles.replies}>
          {comment.children.map((child) => (
            <CommentNodeView
              key={child.id}
              comment={child}
              runtime={runtime}
              isOpen={isOpen}
              replyTo={replyTo}
              setReplyTo={setReplyTo}
              depth={depth + 1}
            />
          ))}
        </ol>
      )}
    </li>
  )
}

interface CommentFormProps {
  runtime: WPHeadlessRuntime | null
  postId: number
  parent?: number
  onDone?: () => void
  inline?: boolean
}

function CommentForm({ runtime, postId, parent = 0, onDone, inline = false }: CommentFormProps) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [website, setWebsite] = useState('')
  const [body, setBody] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [submitted, setSubmitted] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setSubmitting(true)
    setSubmitError(null)
    try {
      await postComment(runtime, {
        post: postId,
        parent,
        author_name: name.trim(),
        author_email: email.trim(),
        author_url: website.trim() || undefined,
        content: body.trim(),
      })
      setSubmitted(true)
      setName(''); setEmail(''); setWebsite(''); setBody('')
      if (onDone) setTimeout(onDone, 1500)
    } catch (err) {
      if (err instanceof WPApiError && err.wpCode === 'rest_comment_login_required') {
        setSubmitError(
          'This site requires login to comment. In WP Admin → Settings → Discussion, uncheck "Users must be registered and logged in to comment."'
        )
      } else {
        setSubmitError(err instanceof Error ? err.message : String(err))
      }
    } finally {
      setSubmitting(false)
    }
  }

  if (submitted) {
    return (
      <div className={`${styles.form} ${inline ? styles.formInline : ''}`}>
        <p className={styles.success}>Thank you! Your comment is awaiting moderation.</p>
      </div>
    )
  }

  return (
    <div className={`${styles.form} ${inline ? styles.formInline : ''}`}>
      {!inline && <h3 className={styles.formHeading}>Leave a comment</h3>}
      {inline && <h4 className={styles.formHeading}>Reply</h4>}

      <form onSubmit={handleSubmit}>
        <div className={styles.fields}>
          <label className={styles.label}>
            Name <span aria-hidden="true">*</span>
            <input type="text" value={name} onChange={(e) => setName(e.target.value)} required className={styles.input} autoComplete="name" />
          </label>
          <label className={styles.label}>
            Email <span aria-hidden="true">*</span>
            <input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required className={styles.input} autoComplete="email" />
          </label>
          <label className={styles.label}>
            Website
            <input type="url" value={website} onChange={(e) => setWebsite(e.target.value)} className={styles.input} autoComplete="url" />
          </label>
        </div>
        <label className={styles.label}>
          Comment <span aria-hidden="true">*</span>
          <textarea value={body} onChange={(e) => setBody(e.target.value)} required rows={inline ? 4 : 6} className={styles.textarea} />
        </label>
        {submitError && <p className={styles.error}>{submitError}</p>}
        <button type="submit" disabled={submitting} className={styles.submit}>
          {submitting ? 'Posting…' : inline ? 'Post Reply' : 'Post Comment'}
        </button>
      </form>
    </div>
  )
}

function buildCommentTree(comments: WPComment[]): CommentNode[] {
  const byId = new Map<number, CommentNode>()
  const roots: CommentNode[] = []
  for (const c of comments) {
    byId.set(c.id, { ...c, children: [] })
  }
  for (const c of comments) {
    const node = byId.get(c.id)
    if (!node) continue
    const parent = c.parent && byId.get(c.parent)
    if (parent) parent.children.push(node)
    else roots.push(node)
  }
  return roots
}
