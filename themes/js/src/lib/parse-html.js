import { createElement, Fragment } from 'react'

/**
 * Minimal HTML → React tree parser.
 *
 * Behaviour:
 *   - Element with `data-wph-block-name="ns/block"` AND a Component registered
 *     under that name in `registry` → renders <Component attrs={…} innerHTML={…} />
 *   - Every other element → re-created as a React element with attributes
 *     mapped to React prop names (`class` → `className`, etc.) and children
 *     walked recursively
 *   - `<script>` tags are stripped (defense in depth — content.rendered is
 *     trusted WP output, but we never want to evaluate it)
 *   - Inline `style="…"` is parsed into a `{prop: value}` style object so
 *     React doesn't complain
 *
 * Runs only in the browser (uses DOMParser).
 *
 * @param {string} html
 * @param {Record<string, import('react').ComponentType<any>>} registry
 * @returns {import('react').ReactNode}
 */
export function parseHtmlToReact(html, registry = {}) {
  if (typeof DOMParser === 'undefined' || typeof html !== 'string' || html === '') {
    return null
  }
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html')
  const body = doc.body
  const out = []
  for (let i = 0; i < body.childNodes.length; i++) {
    const child = walk(body.childNodes[i], i, registry)
    if (child !== null && child !== undefined) out.push(child)
  }
  return createElement(Fragment, null, ...out)
}

/** Walk one DOM node, return a React node, plain string, or null. */
function walk(node, key, registry) {
  if (node.nodeType === 3 /* text */) {
    return node.textContent
  }
  if (node.nodeType !== 1 /* element */) {
    return null
  }

  const tag = node.tagName.toLowerCase()

  // Strip script tags — never evaluate untrusted script content via DOMParser.
  if (tag === 'script' || tag === 'noscript') {
    return null
  }

  // Block-component registry match.
  const blockName = node.getAttribute('data-wph-block-name')
  if (blockName && registry[blockName]) {
    const Component = registry[blockName]
    let attrs = {}
    try {
      attrs = JSON.parse(node.getAttribute('data-wph-block-attrs') || '{}')
    } catch {
      // ignore
    }
    return createElement(Component, {
      key,
      attrs,
      innerHTML: node.innerHTML,
      className: node.getAttribute('class') || '',
    })
  }

  const props = collectProps(node)
  props.key = key

  if (VOID_TAGS.has(tag)) {
    return createElement(tag, props)
  }

  const children = []
  for (let i = 0; i < node.childNodes.length; i++) {
    const child = walk(node.childNodes[i], i, registry)
    if (child !== null && child !== undefined) children.push(child)
  }
  return createElement(tag, props, ...children)
}

/** HTML attribute → React prop name. */
const ATTR_MAP = {
  class: 'className',
  for: 'htmlFor',
  tabindex: 'tabIndex',
  readonly: 'readOnly',
  maxlength: 'maxLength',
  minlength: 'minLength',
  cellpadding: 'cellPadding',
  cellspacing: 'cellSpacing',
  rowspan: 'rowSpan',
  colspan: 'colSpan',
  usemap: 'useMap',
  frameborder: 'frameBorder',
  allowfullscreen: 'allowFullScreen',
  autoplay: 'autoPlay',
  contenteditable: 'contentEditable',
  spellcheck: 'spellCheck',
  srcset: 'srcSet',
  crossorigin: 'crossOrigin',
  acceptcharset: 'acceptCharset',
  enctype: 'encType',
  formaction: 'formAction',
  formenctype: 'formEncType',
  formmethod: 'formMethod',
  formnovalidate: 'formNoValidate',
  formtarget: 'formTarget',
  novalidate: 'noValidate',
  marginwidth: 'marginWidth',
  marginheight: 'marginHeight',
  itemprop: 'itemProp',
  itemscope: 'itemScope',
  itemtype: 'itemType',
  datetime: 'dateTime',
  hreflang: 'hrefLang',
  charset: 'charSet',
  referrerpolicy: 'referrerPolicy',
}

const VOID_TAGS = new Set([
  'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
  'link', 'meta', 'param', 'source', 'track', 'wbr',
])

function collectProps(el) {
  const props = {}
  for (const attr of el.attributes) {
    const name = attr.name
    if (name.startsWith('on')) continue // strip inline event handlers
    if (name.startsWith('data-wph-')) continue // private to us
    if (name === 'style') {
      props.style = parseInlineStyle(attr.value)
    } else if (name === 'aria-hidden' || name.startsWith('aria-') || name.startsWith('data-')) {
      props[name] = attr.value
    } else {
      props[ATTR_MAP[name] ?? name] = attr.value
    }
  }
  return props
}

function parseInlineStyle(s) {
  if (!s) return undefined
  const out = {}
  for (const rule of s.split(';')) {
    const idx = rule.indexOf(':')
    if (idx < 0) continue
    const key = rule.slice(0, idx).trim()
    const value = rule.slice(idx + 1).trim()
    if (!key) continue
    const camel = key.replace(/-(\w)/g, (_, c) => c.toUpperCase())
    out[camel] = value
  }
  return out
}
