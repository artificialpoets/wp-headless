import { useMemo, type ComponentType } from 'react'
import { parseHtmlToReact } from '../lib/parse-html'
import { blockComponents } from '../templates/registry'
import { loadBlockStylesheets } from '../lib/per-block-css'
import type { WPHeadlessRuntime } from '../types/wp-headless'

interface BlockContentProps {
  html?: string
  runtime: WPHeadlessRuntime | null
  as?: keyof JSX.IntrinsicElements
  className?: string
}

/**
 * Drop-in replacement for `dangerouslySetInnerHTML` on `content.rendered`.
 * See JS twin for full behavior.
 */
export function BlockContent({ html = '', runtime, as: Tag = 'div', className }: BlockContentProps) {
  const hasRegistry = Object.keys(blockComponents).length > 0

  useMemo(() => {
    if (!html) return
    loadBlockStylesheets(html, runtime)
  }, [html, runtime])

  const registry = useMemo(() => wrapRegistry(blockComponents, runtime), [runtime])
  const tree = useMemo(() => (hasRegistry ? parseHtmlToReact(html, registry) : null), [html, registry, hasRegistry])

  if (!hasRegistry) {
    const Element = Tag as 'div'
    return <Element className={className} dangerouslySetInnerHTML={{ __html: html }} />
  }

  const Element = Tag as 'div'
  return <Element className={className}>{tree}</Element>
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function wrapRegistry(map: Record<string, ComponentType<any>>, runtime: WPHeadlessRuntime | null): Record<string, ComponentType<any>> {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const out: Record<string, ComponentType<any>> = {}
  for (const [name, Component] of Object.entries(map)) {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    out[name] = (props: any) => <Component {...props} runtime={runtime} />
  }
  return out
}
