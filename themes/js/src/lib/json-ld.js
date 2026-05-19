/**
 * JSON-LD schema builders. Each function returns one or more nodes in a
 * shape that can be passed directly to `useHead({ jsonLd: ... })`.
 *
 * Pass an array to render multiple nodes inside a single @graph wrapper;
 * pass a single object to render that one schema.
 */

const stripTags = (s) => (typeof s === 'string' ? s.replace(/<[^>]+>/g, '').trim() : '')

/**
 * WebSite schema with optional SearchAction. Use on the front page.
 *
 * @param {{ name: string, url: string, description?: string }} site
 */
export function websiteSchema(site) {
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

/**
 * Organization schema. If you publish under a personal name, prefer
 * `personSchema()` instead.
 *
 * @param {{ name: string, url: string, logo?: object|null }} site
 */
export function organizationSchema(site) {
  if (!site?.url) return null
  return {
    '@type': 'Organization',
    '@id': `${site.url}#organization`,
    name: site.name,
    url: site.url,
    logo: site.logo?.url ? { '@type': 'ImageObject', url: site.logo.url } : undefined,
  }
}

/**
 * Article schema for a single post.
 *
 * @param {object} post — full WP post object
 * @param {object} site — runtime.site
 */
export function articleSchema(post, site) {
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

/**
 * BreadcrumbList schema. Pass an array of {label, url}.
 */
export function breadcrumbsSchema(crumbs) {
  const items = (crumbs ?? [])
    .filter((c) => c && c.label)
    .map((c, idx) => ({
      '@type': 'ListItem',
      position: idx + 1,
      name: c.label,
      item: c.url || c.to || undefined,
    }))
  if (items.length === 0) return null
  return {
    '@type': 'BreadcrumbList',
    itemListElement: items,
  }
}

/**
 * Person schema for an author archive.
 */
export function personSchema(user, site) {
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

/**
 * CollectionPage schema for archive listings.
 */
export function collectionSchema({ url, name, description }) {
  if (!url) return null
  return {
    '@type': 'CollectionPage',
    '@id': `${url}#collection`,
    url,
    name,
    description,
  }
}
