/**
 * Type definitions for the window.WP_HEADLESS runtime payload
 * injected by the wp-headless WordPress plugin.
 */

export interface WPHeadlessImage {
  id: number
  url: string | false
  thumbnail: string | false
  alt: string
}

export interface WPHeadlessSite {
  name: string
  description: string
  url: string
  language: string
  charset: string
  textDirection: 'ltr' | 'rtl'
  timezone: string
  favicon: WPHeadlessImage | null
  logo: WPHeadlessImage | null
  header_image?: WPHeadlessHeaderImage | null
  background?: WPHeadlessBackground | null
  admin_email?: string | null
}

export interface WPHeadlessHeaderImage {
  url: string
  width: number | null
  height: number | null
  thumbnail: string | null
  attachment_id: number | null
}

export interface WPHeadlessBackground {
  image: string | null
  color: string | null
  repeat: string
  size: string
  attachment: string
  position: string
}

export interface WPHeadlessUrls {
  login: string
  logout: string
  register: string
  lost_password: string
  admin: string
  profile: string
  registration_enabled: boolean
}

export interface WPHeadlessUserCapabilities {
  edit_posts: boolean
  edit_others_posts: boolean
  publish_posts: boolean
  delete_posts: boolean
  moderate_comments: boolean
  manage_options: boolean
}

export interface WPHeadlessUser {
  id: number
  slug: string
  display_name: string
  username: string
  email: string | null
  roles: string[]
  avatar: string
  profile_link: string
  author_link: string
  capabilities: WPHeadlessUserCapabilities
}

export interface WPHeadlessPostType {
  name: string
  label: string
  singular: string
  rest_base: string
  has_archive: boolean | string
  archive_link: string | null
  hierarchical: boolean
  show_in_rest: boolean
  builtin: boolean
  supports_comments: boolean
  supports_thumbnail: boolean
}

export interface WPHeadlessDiscussion {
  comment_registration: boolean
  require_name_email: boolean
  default_comment_status: string
  thread_comments: boolean
  thread_comments_depth: number
  page_comments: boolean
  comments_per_page: number
  default_comments_page: string
  comment_order: string
  show_avatars: boolean
}

export interface WPHeadlessRest {
  root: string
  wpV2: string
  headless: string
  namespace: string
  nonce: string
}

export interface WPHeadlessFrontend {
  assetBaseUrl: string
  assetMount: string
  hasFrontendBuild: boolean
}

export interface WPHeadlessMenuLocation {
  id: number
  name: string | null
  slug: string | null
}

export interface WPHeadlessQueriedObject {
  kind: 'post' | 'term' | 'post_type' | 'user' | 'attachment'
  id?: number
  post_type?: string
  slug?: string
  status?: string
  title?: string
  link?: string
  taxonomy?: string
  name?: string
  label?: string
  rest_base?: string
  description?: string
  display_name?: string
  count?: number
  avatar?: string | null
  // Attachment-specific
  caption?: string
  alt?: string
  mime?: string
  url?: string
  width?: number | null
  height?: number | null
  srcset?: string | null
  sizes?: string | null
  parent_id?: number
  parent_link?: string | null
  // Term-specific
  parent?: number
  ancestors?: Array<{ id: number; slug: string; name: string; link: string }>
  // Post type-specific (CPT archives)
  has_archive?: boolean | string
  archive_link?: string | null
  singular?: string
}

export interface WPHeadlessPostSummary {
  id: number
  post_type: string
  slug: string
  status: string
  title: string
  link: string
}

export interface WPHeadlessDateArchive {
  year: number
  month?: number
  day?: number
}

export interface WPHeadlessRequest {
  url: string
  path: string
  kind?: string
  is_front_page: boolean
  is_home: boolean
  is_singular: boolean
  is_archive: boolean
  is_author?: boolean
  is_date?: boolean
  is_year?: boolean
  is_month?: boolean
  is_day?: boolean
  is_attachment?: boolean
  is_post_type_archive?: boolean
  is_preview?: boolean
  is_auth?: boolean
  is_embed?: boolean
  is_search: boolean
  is_404: boolean
  page?: number
  search_query?: string | null
  date_archive?: WPHeadlessDateArchive | null
  robots?: string | null
  queried_object: WPHeadlessQueriedObject | null
  post: WPHeadlessPostSummary | null
  queried_object_id: number
}

export interface WPHeadlessThemeStyles {
  color?: {
    palette?: Array<{ slug?: string; name?: string; color?: string }>
    gradients?: Array<{ slug?: string; name?: string; gradient?: string }>
  }
  typography?: {
    fontSizes?: Array<{ slug?: string; name?: string; size?: string }>
    fontFamilies?: Array<{ slug?: string; name?: string; fontFamily?: string }>
  }
  spacing?: {
    spacingSizes?: Array<{ slug?: string; name?: string; size?: string }>
  }
  layout?: {
    contentSize?: string | null
    wideSize?: string | null
  }
}

export interface WPHeadlessBlockStyleVariation {
  name: string
  label: string
  isDefault: boolean
}

export interface WPHeadlessTheme {
  styles?: WPHeadlessThemeStyles
  blockStyles?: Record<string, WPHeadlessBlockStyleVariation[]>
  blockStylesheets?: Record<string, string>
}

export interface WPHeadlessRuntime {
  site: WPHeadlessSite
  rest: WPHeadlessRest
  frontend: WPHeadlessFrontend
  menus: {
    locations: Record<string, WPHeadlessMenuLocation>
  }
  urls: WPHeadlessUrls
  user: WPHeadlessUser | null
  postTypes: WPHeadlessPostType[]
  discussion: WPHeadlessDiscussion
  customCss: string
  theme?: WPHeadlessTheme
  request: WPHeadlessRequest
}

declare global {
  interface Window {
    WP_HEADLESS: WPHeadlessRuntime
  }
}
