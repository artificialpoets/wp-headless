import { useEffect } from 'react'

/**
 * Imperatively manage <head> tags for the current page.
 *
 * One hook to handle:
 *   - document.title
 *   - <html lang>
 *   - <link rel="canonical">
 *   - <link rel="alternate" type="application/rss+xml">
 *   - Open Graph meta (og:title, og:description, og:url, og:type, og:image)
 *   - Twitter card meta (twitter:card, twitter:title, twitter:description, twitter:image)
 *   - Generic description meta
 *
 * Tags created by this hook are tagged with data-managed="wp-headless" and
 * removed/replaced on every change so the head never accumulates stale tags
 * during client-side navigation. Static tags (e.g. <meta charset>) are left alone.
 *
 * @param {{
 *   title?: string,
 *   description?: string,
 *   canonical?: string,
 *   lang?: string,
 *   rssUrl?: string,
 *   ogType?: 'website'|'article'|string,
 *   ogImage?: string,
 *   siteName?: string,
 * }} props
 */
export function useHead({
  title,
  description,
  canonical,
  lang,
  rssUrl,
  ogType,
  ogImage,
  siteName,
  robots,
  jsonLd,
} = {}) {
  useEffect(() => {
    if (typeof document === 'undefined') return

    if (title) document.title = title
    if (lang) document.documentElement.setAttribute('lang', lang.replace('_', '-'))

    const created = []

    // Adopt-or-replace: remove any existing tag with the same identity before
    // adding ours. The server shell already emits core's rel_canonical (and, if
    // an SEO plugin is active, its description/og/twitter/canonical). Appending
    // blindly would leave two canonicals and two descriptions on the initial
    // crawler load — which makes Google ignore both. Removing the existing
    // match first guarantees exactly one authoritative tag per identity, and
    // keeps it fresh across client-side navigation.
    const claim = (selector) => {
      if (!selector) return
      document.head.querySelectorAll(selector).forEach((el) => {
        if (!created.includes(el)) el.remove()
      })
    }

    const setMeta = (attrs) => {
      const selector = attrs.name
        ? `meta[name="${attrs.name}"]`
        : attrs.property
          ? `meta[property="${attrs.property}"]`
          : null
      claim(selector)
      const link = document.createElement('meta')
      for (const [k, v] of Object.entries(attrs)) link.setAttribute(k, v)
      link.setAttribute('data-managed', 'wp-headless')
      document.head.appendChild(link)
      created.push(link)
    }

    const setLink = (attrs) => {
      const selector = attrs.rel === 'canonical'
        ? 'link[rel="canonical"]'
        : attrs.rel === 'alternate' && attrs.type
          ? `link[rel="alternate"][type="${attrs.type}"]`
          : null
      claim(selector)
      const link = document.createElement('link')
      for (const [k, v] of Object.entries(attrs)) link.setAttribute(k, v)
      link.setAttribute('data-managed', 'wp-headless')
      document.head.appendChild(link)
      created.push(link)
    }

    if (description) {
      setMeta({ name: 'description', content: description })
      setMeta({ property: 'og:description', content: description })
      setMeta({ name: 'twitter:description', content: description })
    }
    if (title) {
      setMeta({ property: 'og:title', content: title })
      setMeta({ name: 'twitter:title', content: title })
    }
    if (canonical) {
      setLink({ rel: 'canonical', href: canonical })
      setMeta({ property: 'og:url', content: canonical })
    }
    if (ogType) {
      setMeta({ property: 'og:type', content: ogType })
    }
    if (siteName) {
      setMeta({ property: 'og:site_name', content: siteName })
    }
    if (ogImage) {
      setMeta({ property: 'og:image', content: ogImage })
      setMeta({ name: 'twitter:image', content: ogImage })
      setMeta({ name: 'twitter:card', content: 'summary_large_image' })
    } else {
      setMeta({ name: 'twitter:card', content: 'summary' })
    }
    if (rssUrl) {
      setLink({ rel: 'alternate', type: 'application/rss+xml', title: siteName ? `${siteName} RSS` : 'RSS', href: rssUrl })
    }
    if (robots) {
      setMeta({ name: 'robots', content: robots })
    }
    if (jsonLd) {
      // Render either a single object or an array of @graph nodes.
      const payload = Array.isArray(jsonLd)
        ? { '@context': 'https://schema.org', '@graph': jsonLd }
        : { '@context': 'https://schema.org', ...jsonLd }
      const script = document.createElement('script')
      script.type = 'application/ld+json'
      script.setAttribute('data-managed', 'wp-headless')
      script.textContent = JSON.stringify(payload)
      document.head.appendChild(script)
      created.push(script)
    }

    return () => {
      for (const node of created) {
        if (node.parentNode) node.parentNode.removeChild(node)
      }
    }
  }, [title, description, canonical, lang, rssUrl, ogType, ogImage, siteName, robots, jsonLdKey(jsonLd)])
}

/**
 * Memoization helper — JSON-stringify the JSON-LD blob once for the effect
 * dependency array so passing a new-but-equivalent object each render doesn't
 * tear down and re-add the same `<script>`.
 */
function jsonLdKey(jsonLd) {
  if (!jsonLd) return null
  try {
    return JSON.stringify(jsonLd)
  } catch {
    return null
  }
}
