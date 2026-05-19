import styles from './pagination.module.css'

interface PaginationProps {
  currentPage: number
  totalPages: number
  onPageChange: (page: number) => void
}

/**
 * Previous / next pagination control.
 *
 * Accessible: `aria-label` describes WHERE each button goes (e.g. "Go to
 * page 2 of 5"), the page indicator is `aria-live="polite"` so screen
 * readers announce position changes, and disabled state uses the real
 * `disabled` attribute (also drops the button from tab order).
 */
export function Pagination({ currentPage, totalPages, onPageChange }: PaginationProps) {
  if (totalPages <= 1) return null

  const prevDisabled = currentPage <= 1
  const nextDisabled = currentPage >= totalPages
  const prevPage = Math.max(1, currentPage - 1)
  const nextPage = Math.min(totalPages, currentPage + 1)

  return (
    <nav className={styles.pagination} aria-label="Pagination">
      <button
        type="button"
        className={styles.button}
        onClick={() => onPageChange(prevPage)}
        disabled={prevDisabled}
        aria-label={prevDisabled
          ? 'Previous page (unavailable, you are on page 1)'
          : `Go to page ${prevPage} of ${totalPages}`}
      >
        <span aria-hidden="true">← </span>Previous
      </button>
      <span
        className={styles.info}
        aria-live="polite"
        aria-atomic="true"
      >
        Page {currentPage} of {totalPages}
      </span>
      <button
        type="button"
        className={styles.button}
        onClick={() => onPageChange(nextPage)}
        disabled={nextDisabled}
        aria-label={nextDisabled
          ? `Next page (unavailable, you are on page ${totalPages})`
          : `Go to page ${nextPage} of ${totalPages}`}
      >
        Next<span aria-hidden="true"> →</span>
      </button>
    </nav>
  )
}
