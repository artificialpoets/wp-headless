/**
 * REST API client.
 *
 * All functions take `runtime` as their first argument so they remain
 * stateless and easily testable — no module-level singletons.
 *
 * The REST root URLs come from window.WP_HEADLESS.rest, which is injected
 * into the page by the wp-headless plugin before </head>.
 */

/** @param {import('../types/wp-headless').WPHeadlessRuntime} runtime */
export const wpV2Root = (runtime) =>
  (runtime?.rest?.wpV2 ?? '').replace(/\/$/, '')

/** @param {import('../types/wp-headless').WPHeadlessRuntime} runtime */
const headlessRoot = (runtime) =>
  (runtime?.rest?.headless ?? '').replace(/\/$/, '')

// ---------------------------------------------------------------------------
// Posts
// ---------------------------------------------------------------------------

/**
 * Fetch a list of posts.
 *
 * Pass `_rest_base` (a synthetic param consumed by this function, not sent
 * to WP) to query a custom post type's REST collection instead of `/posts`.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {Record<string, string|number>} [params]
 * @returns {Promise<{ posts: object[], total: number, totalPages: number }>}
 */
export async function fetchPosts(runtime, params = {}) {
  const { _rest_base: restBase = 'posts', ...rest } = params
  const query = new URLSearchParams(
    Object.fromEntries(Object.entries(rest).map(([k, v]) => [k, String(v)]))
  ).toString()

  const sep = query ? '&' : '?'
  const url = `${wpV2Root(runtime)}/${restBase}${query ? '?' + query : ''}${sep}_embed`
  const res = await fetch(url)

  if (!res.ok) throw new Error(`fetchPosts failed: ${res.status} ${res.statusText}`)

  const posts = await res.json()
  const total = parseInt(res.headers.get('X-WP-Total') ?? '0', 10)
  const totalPages = parseInt(res.headers.get('X-WP-TotalPages') ?? '0', 10)

  return { posts, total, totalPages }
}

/**
 * Fetch a single post by ID.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {number} id
 * @returns {Promise<object>}
 */
export async function fetchPost(runtime, id) {
  const res = await fetch(`${wpV2Root(runtime)}/posts/${id}?_embed`)
  if (!res.ok) throw new Error(`fetchPost failed: ${res.status} ${res.statusText}`)
  return res.json()
}

/**
 * Fetch a single page by ID.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {number} id
 * @returns {Promise<object>}
 */
export async function fetchPage(runtime, id) {
  const res = await fetch(`${wpV2Root(runtime)}/pages/${id}?_embed`)
  if (!res.ok) throw new Error(`fetchPage failed: ${res.status} ${res.statusText}`)
  return res.json()
}

// ---------------------------------------------------------------------------
// Comments
// ---------------------------------------------------------------------------

/**
 * Fetch approved comments for a post.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {number} postId
 * @param {{ page?: number, per_page?: number, order?: 'asc'|'desc' }} [options]
 * @returns {Promise<{ comments: object[], total: number, totalPages: number }>}
 */
export async function fetchComments(runtime, postId, options = {}) {
  const page = options.page ?? 1
  const perPage = options.per_page ?? 50
  const order = options.order ?? 'asc'
  const res = await fetch(
    `${wpV2Root(runtime)}/comments?post=${postId}&per_page=${perPage}&page=${page}&orderby=date&order=${order}`
  )
  if (!res.ok) throw new Error(`fetchComments failed: ${res.status} ${res.statusText}`)
  const comments = await res.json()
  const total = parseInt(res.headers.get('X-WP-Total') ?? '0', 10)
  const totalPages = parseInt(res.headers.get('X-WP-TotalPages') ?? '0', 10)
  return { comments, total, totalPages }
}

/**
 * Post a new comment.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {{ post: number, author_name: string, author_email: string, content: string, author_url?: string, parent?: number }} data
 * @returns {Promise<object>}
 */
export async function postComment(runtime, data) {
  const nonce = runtime?.rest?.nonce
  const res = await fetch(`${wpV2Root(runtime)}/comments`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
    },
    body: JSON.stringify(data),
  })
  if (!res.ok) {
    const errData = await res.json().catch(() => ({}))
    const error = new Error(errData?.message ?? `postComment failed: ${res.status}`)
    error.wpCode = errData?.code ?? ''
    throw error
  }
  return res.json()
}

// ---------------------------------------------------------------------------
// Pages (used as nav fallback when no primary menu is assigned)
// ---------------------------------------------------------------------------

/**
 * Fetch published pages ordered by menu_order.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {Record<string, string|number>} [params]
 * @returns {Promise<object[]>}
 */
export async function fetchPages(runtime, params = {}) {
  const query = new URLSearchParams({
    per_page: '50',
    status: 'publish',
    orderby: 'menu_order',
    order: 'asc',
    ...Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])),
  }).toString()

  const res = await fetch(`${wpV2Root(runtime)}/pages?${query}`)
  if (!res.ok) throw new Error(`fetchPages failed: ${res.status} ${res.statusText}`)
  return res.json()
}

// ---------------------------------------------------------------------------
// Application Passwords (authenticated requests against current user)
// ---------------------------------------------------------------------------

/**
 * List application passwords for the current user.
 * Requires authentication (cookie + REST nonce, or basic-auth with an app pwd).
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @returns {Promise<object[]>}
 */
export async function fetchAppPasswords(runtime) {
  const nonce = runtime?.rest?.nonce
  const res = await fetch(`${wpV2Root(runtime)}/users/me/application-passwords`, {
    headers: {
      ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
    },
    credentials: 'include',
  })
  if (!res.ok) throw new Error(`fetchAppPasswords failed: ${res.status}`)
  return res.json()
}

/**
 * Create a new application password. The plain-text password is returned
 * ONCE in the `password` field — it can never be retrieved again.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {{ name: string, app_id?: string }} data
 * @returns {Promise<{ uuid: string, password: string, name: string, app_id?: string, created: string }>}
 */
export async function createAppPassword(runtime, data) {
  const nonce = runtime?.rest?.nonce
  const res = await fetch(`${wpV2Root(runtime)}/users/me/application-passwords`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
    },
    credentials: 'include',
    body: JSON.stringify(data),
  })
  if (!res.ok) {
    const err = await res.json().catch(() => ({}))
    throw new Error(err.message || `createAppPassword failed: ${res.status}`)
  }
  return res.json()
}

/**
 * Revoke an application password by uuid.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {string} uuid
 */
export async function deleteAppPassword(runtime, uuid) {
  const nonce = runtime?.rest?.nonce
  const res = await fetch(`${wpV2Root(runtime)}/users/me/application-passwords/${uuid}`, {
    method: 'DELETE',
    headers: {
      ...(nonce ? { 'X-WP-Nonce': nonce } : {}),
    },
    credentials: 'include',
  })
  if (!res.ok) throw new Error(`deleteAppPassword failed: ${res.status}`)
  return res.json()
}

// ---------------------------------------------------------------------------
// Users (authors)
// ---------------------------------------------------------------------------

/**
 * Fetch a single user by id.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {number} id
 * @returns {Promise<object|null>}
 */
export async function fetchUser(runtime, id) {
  const res = await fetch(`${wpV2Root(runtime)}/users/${id}`)
  if (res.status === 404 || res.status === 401) return null
  if (!res.ok) throw new Error(`fetchUser failed: ${res.status} ${res.statusText}`)
  return res.json()
}

// ---------------------------------------------------------------------------
// Menus
// ---------------------------------------------------------------------------

/**
 * Fetch a nav menu from the wp-headless REST endpoint.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {{ id?: number, slug?: string, location?: string }} params
 * @returns {Promise<object|null>}
 */
export async function fetchMenu(runtime, params) {
  const query = new URLSearchParams(
    Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)]))
  ).toString()

  const res = await fetch(`${headlessRoot(runtime)}/menus?${query}`)
  if (res.status === 404) return null
  if (!res.ok) throw new Error(`fetchMenu failed: ${res.status} ${res.statusText}`)
  return res.json()
}

// ---------------------------------------------------------------------------
// URL resolution
// ---------------------------------------------------------------------------

/**
 * Resolve a URL or path to its WordPress request context.
 * Used for client-side navigation after the initial page load.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {string} url URL or path to resolve.
 * @returns {Promise<object>}
 */
export async function resolveUrl(runtime, url) {
  const res = await fetch(
    `${headlessRoot(runtime)}/resolve?url=${encodeURIComponent(url)}`
  )
  if (!res.ok) throw new Error(`resolveUrl failed: ${res.status} ${res.statusText}`)
  return res.json()
}

// ---------------------------------------------------------------------------
// Runtime endpoint (full payload for a given URL)
// ---------------------------------------------------------------------------

/**
 * Fetch the full runtime payload for a given URL.
 * Useful for SSG or server-side rendering scenarios.
 *
 * @param {import('../types/wp-headless').WPHeadlessRuntime} runtime
 * @param {string} [url]
 * @returns {Promise<object>}
 */
export async function fetchRuntime(runtime, url) {
  const query = url ? `?url=${encodeURIComponent(url)}` : ''
  const res = await fetch(`${headlessRoot(runtime)}/runtime${query}`)
  if (!res.ok) throw new Error(`fetchRuntime failed: ${res.status} ${res.statusText}`)
  return res.json()
}
