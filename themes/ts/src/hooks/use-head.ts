import { useEffect } from 'react'

export interface UseHeadOptions {
  title?: string
  description?: string
  canonical?: string
  lang?: string
  rssUrl?: string
  ogType?: 'website' | 'article' | 'profile' | string
  ogImage?: string
  siteName?: string
  robots?: string | null
  jsonLd?: object | object[] | null
}

/**
 * Imperatively manage <head> tags for the current page. See JS theme for full doc.
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
}: UseHeadOptions = {}): void {
  useEffect(() => {
    if (typeof document === 'undefined') return

    if (title) document.title = title
    if (lang) document.documentElement.setAttribute('lang', lang.replace('_', '-'))

    const created: HTMLElement[] = []

    const setMeta = (attrs: Record<string, string>): void => {
      const link = document.createElement('meta')
      for (const [k, v] of Object.entries(attrs)) link.setAttribute(k, v)
      link.setAttribute('data-managed', 'wp-headless')
      document.head.appendChild(link)
      created.push(link)
    }

    const setLink = (attrs: Record<string, string>): void => {
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
    if (ogType) setMeta({ property: 'og:type', content: ogType })
    if (siteName) setMeta({ property: 'og:site_name', content: siteName })
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

function jsonLdKey(jsonLd: object | object[] | null | undefined): string | null {
  if (!jsonLd) return null
  try {
    return JSON.stringify(jsonLd)
  } catch {
    return null
  }
}
