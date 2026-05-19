/**
 * JSON-LD schema builders. Returns either a single node or null when not
 * enough data is available.
 */

import type { WPHeadlessSite } from '../types/wp-headless'
import type { WPPost } from '../types/api'

const stripTags = (s: string | undefined | null): string =>
  typeof s === 'string' ? s.replace(/<[^>]+>/g, '').trim() : ''

export function websiteSchema(site: WPHeadlessSite | undefined | null): object | null {
  if (!site?.url) return null
  return {
    '@type': 'WebSite',
    '@id': `${site.url}#website`,
    url: site.url,
    name: site.name,
    description: site.description,
    inLanguage: site.language,
    potentialAction: {
      '@type': 'SearchAction',
      target: { '@type': 'EntryPoint', urlTemplate: `${site.url}?s={search_term_string}` },
      'query-input': 'required name=search_term_string',
    },
  }
}

export function organizationSchema(site: WPHeadlessSite | undefined | null): object | null {
  if (!site?.url) return null
  return {
    '@type': 'Organization',
    '@id': `${site.url}#organization`,
    name: site.name,
    url: site.url,
    logo: site.logo?.url ? { '@type': 'ImageObject', url: site.logo.url } : undefined,
  }
}

export function articleSchema(post: WPPost | null | undefined, site: WPHeadlessSite | undefined | null): object | null {
  if (!post) return null
  const authorName = post.author_info?.name
  const featured = post.featured_image
  return {
    '@type': 'Article',
    '@id': `${post.permalink}#article`,
    mainEntityOfPage: post.permalink,
    headline: stripTags(post.title?.rendered),
    description: stripTags(post.excerpt?.rendered),
    image: featured?.url ? [featured.url] : undefined,
    datePublished: post.date,
    dateModified: post.modified || post.date,
    author: authorName
      ? {
          '@type': 'Person',
          name: authorName,
          url: post.author_info?.link,
          image: post.author_info?.avatar,
        }
      : undefined,
    publisher: site?.url
      ? {
          '@type': 'Organization',
          name: site.name,
          logo: site.logo?.url ? { '@type': 'ImageObject', url: site.logo.url } : undefined,
        }
      : undefined,
    inLanguage: site?.language,
  }
}

interface BreadcrumbInput { label: string; url?: string }

export function breadcrumbsSchema(crumbs: BreadcrumbInput[]): object | null {
  const items = (crumbs ?? [])
    .filter((c) => c && c.label)
    .map((c, idx) => ({
      '@type': 'ListItem',
      position: idx + 1,
      name: c.label,
      item: c.url || undefined,
    }))
  if (items.length === 0) return null
  return { '@type': 'BreadcrumbList', itemListElement: items }
}

export function personSchema(
  user: { display_name?: string; name?: string; description?: string; avatar?: string; link?: string; author_link?: string } | null | undefined,
  site: WPHeadlessSite | undefined | null
): object | null {
  if (!user) return null
  return {
    '@type': 'Person',
    '@id': user.author_link || user.link,
    name: user.display_name || user.name,
    url: user.author_link || user.link,
    image: user.avatar,
    description: user.description,
    worksFor: site?.url
      ? { '@type': 'Organization', name: site.name, url: site.url }
      : undefined,
  }
}

export function collectionSchema({
  url,
  name,
  description,
}: { url?: string; name?: string; description?: string }): object | null {
  if (!url) return null
  return {
    '@type': 'CollectionPage',
    '@id': `${url}#collection`,
    url,
    name,
    description,
  }
}
