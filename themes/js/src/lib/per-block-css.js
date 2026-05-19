/**
 * Auto-load per-block stylesheets when a block class is present in rendered
 * content. The plugin's `runtime.theme.blockStylesheets` is a map of
 * blockName → stylesheet URL. We translate the WordPress class convention
 * `wp-block-{namespace}-{block}` back to `namespace/block` and inject a
 * `<link rel="stylesheet">` only for blocks the current content actually
 * uses.
 *
 * Safe to call multiple times — each href is injected once.
 *
 * @param {string} html `post.content.rendered` (or any HTML string)
 * @param {object} runtime The WP_HEADLESS runtime
 */
export function loadBlockStylesheets(html, runtime) {
  if (typeof document === 'undefined' || !html) return
  const stylesheets = runtime?.theme?.blockStylesheets
  if (!stylesheets) return

  // Build a reverse map: classToken → blockName → url
  const classToUrl = new Map()
  for (const [blockName, url] of Object.entries(stylesheets)) {
    if (!url) continue
    const token = 'wp-block-' + blockName.replace('/', '-')
    classToUrl.set(token, url)
  }
  if (classToUrl.size === 0) return

  // Find which block classes are present in the HTML once.
  const presentTokens = new Set()
  const re = /class="([^"]+)"/g
  let m
  while ((m = re.exec(html))) {
    for (const cls of m[1].split(/\s+/)) {
      if (cls.startsWith('wp-block-') && classToUrl.has(cls)) {
        presentTokens.add(cls)
      }
    }
  }

  // Inject any missing stylesheets.
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

function absoluteUrl(url, base) {
  try {
    return new URL(url, base || window.location.origin).toString()
  } catch {
    return url
  }
}
