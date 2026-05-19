/**
 * WordPress REST API response types, including custom fields
 * added by the wp-headless plugin.
 */

export interface WPFeaturedImage {
  id: number
  url: string | false
  thumbnail: string | false
  alt: string
  caption: string | false
  srcset: string | false
  sizes?: string | false
  width?: number | null
  height?: number | null
  mime?: string
}

export interface WPAuthorInfo {
  id: number
  name: string
  slug: string
  /** Single avatar URL added by the wp-headless plugin. */
  avatar: string
  description?: string
  url?: string
  link?: string
}

export interface WPEmbeddedTerm {
  id: number
  name: string
  slug: string
  taxonomy: string
  link: string
}

export interface WPAdjacentSummary {
  id: number
  slug: string
  title: string
  link: string
  post_type: string
}

export interface WPPost {
  id: number
  date: string
  date_gmt: string
  modified: string
  slug: string
  status: string
  type: string
  link: string
  format?: string
  sticky?: boolean
  comment_status: 'open' | 'closed'
  comment_count?: number
  title: { rendered: string }
  content: { rendered: string; protected: boolean }
  excerpt: { rendered: string; protected: boolean }
  author: number
  featured_media: number
  // wp-headless custom REST fields
  featured_image_url: string | null
  featured_image: WPFeaturedImage | null
  author_info: WPAuthorInfo | null
  permalink: string
  adjacent?: { previous: WPAdjacentSummary | null; next: WPAdjacentSummary | null }
  /** Standard WP REST field — array of category IDs, not objects. Use _embedded for names. */
  categories?: number[]
  /** Standard WP REST field — array of tag IDs, not objects. Use _embedded for names. */
  tags?: number[]
  _embedded?: {
    author?: Array<{ id: number; name: string; avatar_urls?: Record<string, string> }>
    'wp:term'?: WPEmbeddedTerm[][]
  }
}

export interface WPComment {
  id: number
  post: number
  parent: number
  author_name: string
  author_url: string
  date: string
  content: { rendered: string }
  status: string
  author_avatar_urls?: Record<string, string>
}

export interface WPTerm {
  id: number
  count: number
  description: string
  link: string
  name: string
  slug: string
  taxonomy: string
}

export interface WPUser {
  id: number
  name: string
  slug: string
  description: string
  link: string
  url: string
  avatar_urls?: Record<string, string>
}

export interface WPMenuItem {
  id: number
  title: string
  url: string
  target: string
  classes: string[]
  description: string
  type: string
  object: string
  object_id: number
  children: WPMenuItem[]
}

export interface WPMenu {
  id: number
  name: string
  slug: string
  description: string
  items: WPMenuItem[]
}

export interface WPPostsResponse {
  posts: WPPost[]
  total: number
  totalPages: number
}
