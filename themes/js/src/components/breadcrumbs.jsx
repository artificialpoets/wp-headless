import { Link } from 'react-router-dom'
import styles from './breadcrumbs.module.css'

/**
 * Breadcrumb trail. Items: [{label, to?}].
 * The last item renders as plain text (current page).
 */
export function Breadcrumbs({ items }) {
  if (!items?.length) return null
  return (
    <nav className={styles.nav} aria-label="Breadcrumb">
      <ol className={styles.list}>
        {items.map((item, i) => {
          const isLast = i === items.length - 1
          return (
            <li key={i} className={styles.item}>
              {!isLast && item.to ? (
                <Link to={item.to}>{item.label}</Link>
              ) : (
                <span aria-current={isLast ? 'page' : undefined}>{item.label}</span>
              )}
              {!isLast && <span className={styles.sep} aria-hidden="true"> / </span>}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
