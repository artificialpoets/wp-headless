import { useEffect } from 'react'

/**
 * Sets document.title reactively.
 */
export function useDocumentTitle(title: string | null | undefined): void {
  useEffect(() => {
    if (title) document.title = title
  }, [title])
}
