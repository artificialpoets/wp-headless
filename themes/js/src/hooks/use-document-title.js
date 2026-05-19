import { useEffect } from 'react'

/**
 * Sets document.title reactively.
 *
 * @param {string|null|undefined} title
 */
export function useDocumentTitle(title) {
  useEffect(() => {
    if (title) document.title = title
  }, [title])
}
