import { createElement, Fragment, type ComponentType, type ReactNode } from 'react'

/**
 * Minimal HTML → React tree parser. See JS twin for the full doc.
 */
export function parseHtmlToReact(
  html: string,
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  registry: Record<string, ComponentType<any>> = {}
): ReactNode {
  if (typeof DOMParser === 'undefined' || typeof html !== 'string' || html === '') {
    return null
  }
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html')
  const body = doc.body
  const out: ReactNode[] = []
  for (let i = 0; i < body.childNodes.length; i++) {
    const child = walk(body.childNodes[i], i, registry)
    if (child !== null && child !== undefined) out.push(child)
  }
  return createElement(Fragment, null, ...out)
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function walk(node: Node, key: number, registry: Record<string, ComponentType<any>>): ReactNode {
  if (node.nodeType === 3 /* text */) {
    return node.textContent
  }
  if (node.nodeType !== 1 /* element */) {
    return null
  }
  const el = node as Element
  const tag = el.tagName.toLowerCase()

  if (tag === 'script' || tag === 'noscript') {
    return null
  }

  const blockName = el.getAttribute('data-wph-block-name')
  if (blockName && registry[blockName]) {
    const Component = registry[blockName]
    let attrs: Record<string, unknown> = {}
    try {
      attrs = JSON.parse(el.getAttribute('data-wph-block-attrs') || '{}')
    } catch {
      // ignore
    }
    return createElement(Component, {
      key,
      attrs,
      innerHTML: el.innerHTML,
      className: el.getAttribute('class') || '',
    })
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const props = collectProps(el) as any
  props.key = key

  if (VOID_TAGS.has(tag)) {
    return createElement(tag, props)
  }

  const children: ReactNode[] = []
  for (let i = 0; i < el.childNodes.length; i++) {
    const child = walk(el.childNodes[i], i, registry)
    if (child !== null && child !== undefined) children.push(child)
  }
  return createElement(tag, props, ...children)
}

const ATTR_MAP: Record<string, string> = {
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

function collectProps(el: Element): Record<string, unknown> {
  const props: Record<string, unknown> = {}
  for (const attr of Array.from(el.attributes)) {
    const name = attr.name
    if (name.startsWith('on')) continue
    if (name.startsWith('data-wph-')) continue
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

function parseInlineStyle(s: string): Record<string, string> | undefined {
  if (!s) return undefined
  const out: Record<string, string> = {}
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
