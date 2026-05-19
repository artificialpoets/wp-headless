/**
 * Mock WP_HEADLESS runtime for local Vite development.
 *
 * This file is only imported when import.meta.env.DEV is true.
 * It provides realistic placeholder data so the app renders without
 * a live WordPress backend.
 *
 * Update the values here to match your local WP setup when testing
 * client-side navigation via the /resolve endpoint.
 */

/** @type {import('../types/wp-headless').WPHeadlessRuntime} */
const devRuntime = {
  site: {
    name: 'My Headless Site',
    description: 'A WordPress site served headlessly.',
    url: 'http://localhost:8888',
    language: 'en-US',
    charset: 'UTF-8',
    textDirection: 'ltr',
    timezone: 'America/New_York',
    favicon: null,
    logo: null,
    header_image: null,
    background: null,
  },
  rest: {
    root: 'http://localhost:8888/wp-json/',
    wpV2: 'http://localhost:8888/wp-json/wp/v2',
    headless: 'http://localhost:8888/wp-json/wp-headless/v1',
    namespace: 'wp-headless/v1',
  },
  frontend: {
    assetBaseUrl: 'http://localhost:5173',
    assetMount: '_wp-headless',
    hasFrontendBuild: false,
  },
  menus: {
    locations: {
      primary: { id: 1, name: 'Primary Menu', slug: 'primary-menu' },
    },
  },
  urls: {
    login: 'http://localhost:8888/wp-login.php',
    logout: 'http://localhost:8888/wp-login.php?action=logout',
    register: 'http://localhost:8888/wp-login.php?action=register',
    lost_password: 'http://localhost:8888/wp-login.php?action=lostpassword',
    admin: 'http://localhost:8888/wp-admin/',
    profile: 'http://localhost:8888/wp-admin/profile.php',
    registration_enabled: false,
  },
  user: null,
  postTypes: [
    { name: 'post', label: 'Posts', singular: 'Post', rest_base: 'posts', has_archive: true, archive_link: null, hierarchical: false, show_in_rest: true, builtin: true, supports_comments: true, supports_thumbnail: true },
  ],
  discussion: {
    comment_registration: false,
    require_name_email: true,
    default_comment_status: 'open',
    thread_comments: true,
    thread_comments_depth: 5,
    page_comments: false,
    comments_per_page: 50,
    default_comments_page: 'newest',
    comment_order: 'asc',
    show_avatars: true,
  },
  customCss: '',
  theme: {
    styles: {},
    blockStyles: {},
    blockStylesheets: {},
  },
  request: {
    url: 'http://localhost:5173/',
    path: '/',
    kind: 'posts_archive',
    is_front_page: true,
    is_home: true,
    is_singular: false,
    is_archive: false,
    is_author: false,
    is_date: false,
    is_year: false,
    is_month: false,
    is_day: false,
    is_attachment: false,
    is_post_type_archive: false,
    is_preview: false,
    is_auth: false,
    is_embed: false,
    is_search: false,
    is_404: false,
    page: 1,
    search_query: null,
    date_archive: null,
    robots: null,
    queried_object: null,
    post: null,
    queried_object_id: 0,
  },
}

export default devRuntime
