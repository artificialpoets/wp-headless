import { useMemo } from 'react'
import { parseHtmlToReact } from '../lib/parse-html'
import { blockComponents } from '../templates/registry'
import { loadBlockStylesheets } from '../lib/per-block-css'

/**
 * Drop-in replacement for
 *   <div dangerouslySetInnerHTML={{ __html: post.content.rendered }} />
 *
 * When the theme's registry has at least one entry in `blockComponents`,
 * `content.rendered` is parsed into a React tree and matching blocks are
 * swapped for their registered React components. Otherwise we fall back
 * to `dangerouslySetInnerHTML` (zero overhead).
 *
 * Also triggers per-block stylesheet auto-loading when the content
 * references blocks whose stylesheets weren't bundled into the React app.
 *
 * Props delivered to registered block components:
 *   - runtime    — the full WP_HEADLESS runtime
 *   - attrs      — block attributes (the JSON from `data-wph-block-attrs`)
 *   - innerHTML  — the rendered HTML inside the block element
 *   - className  — the original `class` attribute on the block wrapper
 *
 * @param {{ html: string, runtime: object, as?: string, className?: string }} props
 */
export function BlockContent({ html = '', runtime, as: Tag = 'div', className }) {
  const hasRegistry = Object.keys(blockComponents).length > 0

  // Auto-load per-block stylesheets for any blocks the content references.
  useMemo(() => {
    if (!html) return
    loadBlockStylesheets(html, runtime)
  }, [html, runtime])

  if (!hasRegistry) {
    return <Tag className={className} dangerouslySetInnerHTML={{ __html: html }} />
  }

  const registry = useMemo(() => wrapRegistry(blockComponents, runtime), [runtime])
  const tree = useMemo(() => parseHtmlToReact(html, registry), [html, registry])

  return <Tag className={className}>{tree}</Tag>
}

/**
 * Bind `runtime` to each registered block component so consumers don't have
 * to thread it through manually.
 */
function wrapRegistry(map, runtime) {
  const out = {}
  for (const [name, Component] of Object.entries(map)) {
    out[name] = (props) => <Component {...props} runtime={runtime} />
  }
  return out
}
