import type { WPHeadlessRuntime } from '../types/wp-headless'

/**
 * Auto-load per-block stylesheets when the block's class is present in
 * rendered content. See JS twin for full doc.
 */
export function loadBlockStylesheets(html: string, runtime: WPHeadlessRuntime | null | undefined): void {
  if (typeof document === 'undefined' || !html) return
  const stylesheets = runtime?.theme?.blockStylesheets
  if (!stylesheets) return

  const classToUrl = new Map<string, string>()
  for (const [blockName, url] of Object.entries(stylesheets)) {
    if (!url) continue
    const token = 'wp-block-' + blockName.replace('/', '-')
    classToUrl.set(token, url)
  }
  if (classToUrl.size === 0) return

  const presentTokens = new Set<string>()
  const re = /class="([^"]+)"/g
  let m: RegExpExecArray | null
  while ((m = re.exec(html))) {
    for (const cls of m[1].split(/\s+/)) {
      if (cls.startsWith('wp-block-') && classToUrl.has(cls)) {
        presentTokens.add(cls)
      }
    }
  }

  for (const token of presentTokens) {
    const url = classToUrl.get(token)
    if (!url) continue
    const full = absoluteUrl(url, runtime?.site?.url)
    if (document.querySelector(`link[href="${full}"]`)) continue
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = full
    link.setAttribute('data-managed', 'wp-headless-block')
    document.head.appendChild(link)
  }
}

function absoluteUrl(url: string, base: string | undefined): string {
  try {
    return new URL(url, base || window.location.origin).toString()
  } catch {
    return url
  }
}
