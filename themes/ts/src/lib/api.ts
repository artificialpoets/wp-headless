/**
 * REST API client.
 *
 * All functions take `runtime` as their first argument so they remain
 * stateless and easily testable — no module-level singletons.
 */

import type { WPHeadlessRuntime, WPHeadlessRequest } from '../types/wp-headless'
import type { WPPost, WPComment, WPMenu, WPPostsResponse, WPUser } from '../types/api'

export const wpV2Root = (runtime: WPHeadlessRuntime | null): string =>
  (runtime?.rest?.wpV2 ?? '').replace(/\/$/, '')

const headlessRoot = (runtime: WPHeadlessRuntime | null): string =>
  (runtime?.rest?.headless ?? '').replace(/\/$/, '')

// ---------------------------------------------------------------------------
// Posts
// ---------------------------------------------------------------------------

export async function fetchPosts(
  runtime: WPHeadlessRuntime | null,
  params: Record<string, string | number> = {}
): Promise<WPPostsResponse> {
  const { _rest_base: restBase = 'posts', ...rest } = params
  const query = new URLSearchParams(
    Object.fromEntries(Object.entries(rest).map(([k, v]) => [k, String(v)]))
  ).toString()

  const sep = query ? '&' : '?'
  const url = `${wpV2Root(runtime)}/${restBase}${query ? '?' + query : ''}${sep}_embed`
  const res = await fetch(url)

  if (!res.ok) throw new Error(`fetchPosts failed: ${res.status} ${res.statusText}`)

  const posts = (await res.json()) as WPPost[]
  const total = parseInt(res.headers.get('X-WP-Total') ?? '0', 10)
  const totalPages = parseInt(res.headers.get('X-WP-TotalPages') ?? '0', 10)

  return { posts, total, totalPages }
}

export async function fetchPost(
  runtime: WPHeadlessRuntime | null,
  id: number
): Promise<WPPost> {
  const res = await fetch(`${wpV2Root(runtime)}/posts/${id}?_embed`)
  if (!res.ok) throw new Error(`fetchPost failed: ${res.status} ${res.statusText}`)
  return res.json() as Promise<WPPost>
}

export async function fetchPage(
  runtime: WPHeadlessRuntime | null,
  id: number
): Promise<WPPost> {
  const res = await fetch(`${wpV2Root(runtime)}/pages/${id}?_embed`)
  if (!res.ok) throw new Error(`fetchPage failed: ${res.status} ${res.statusText}`)
  return res.json() as Promise<WPPost>
}

// ---------------------------------------------------------------------------
// Comments
// ---------------------------------------------------------------------------

export interface FetchCommentsOptions {
  page?: number
  per_page?: number
  order?: 'asc' | 'desc'
}

export interface FetchCommentsResult {
  comments: WPComment[]
  total: number
  totalPages: number
}

export async function fetchComments(
  runtime: WPHeadlessRuntime | null,
  postId: number,
  options: FetchCommentsOptions = {}
): Promise<FetchCommentsResult> {
  const page = options.page ?? 1
  const perPage = options.per_page ?? 50
  const order = options.order ?? 'asc'
  const res = await fetch(
    `${wpV2Root(runtime)}/comments?post=${postId}&per_page=${perPage}&page=${page}&orderby=date&order=${order}`
  )
  if (!res.ok) throw new Error(`fetchComments failed: ${res.status} ${res.statusText}`)
  const comments = (await res.json()) as WPComment[]
  const total = parseInt(res.headers.get('X-WP-Total') ?? '0', 10)
  const totalPages = parseInt(res.headers.get('X-WP-TotalPages') ?? '0', 10)
  return { comments, total, totalPages }
}

export class WPApiError extends Error {
  wpCode: string
  constructor(message: string, wpCode: string) {
    super(message)
    this.wpCode = wpCode
  }
}

export async function postComment(
  runtime: WPHeadlessRuntime | null,
  data: {
    post: number
    author_name: string
    author_email: string
    content: string
    author_url?: string
    parent?: number
  }
): Promise<WPComment> {
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
    const errData = await res.json().catch(() => ({})) as { message?: string; code?: string }
    throw new WPApiError(errData?.message ?? `postComment failed: ${res.status}`, errData?.code ?? '')
  }
  return res.json() as Promise<WPComment>
}

// ---------------------------------------------------------------------------
// Pages (used as nav fallback when no primary menu is assigned)
// ---------------------------------------------------------------------------

export async function fetchPages(
  runtime: WPHeadlessRuntime | null,
  params: Record<string, string | number> = {}
): Promise<WPPost[]> {
  const query = new URLSearchParams({
    per_page: '50',
    status: 'publish',
    orderby: 'menu_order',
    order: 'asc',
    ...Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])),
  }).toString()

  const res = await fetch(`${wpV2Root(runtime)}/pages?${query}`)
  if (!res.ok) throw new Error(`fetchPages failed: ${res.status} ${res.statusText}`)
  return res.json() as Promise<WPPost[]>
}

// ---------------------------------------------------------------------------
// Application Passwords (authenticated)
// ---------------------------------------------------------------------------

export interface AppPassword {
  uuid: string
  name: string
  app_id?: string
  created: string
  last_used?: string | null
  last_ip?: string | null
}

export interface NewAppPassword extends AppPassword {
  password: string
}

export async function fetchAppPasswords(runtime: WPHeadlessRuntime | null): Promise<AppPassword[]> {
  const nonce = runtime?.rest?.nonce
  const res = await fetch(`${wpV2Root(runtime)}/users/me/application-passwords`, {
    headers: { ...(nonce ? { 'X-WP-Nonce': nonce } : {}) },
    credentials: 'include',
  })
  if (!res.ok) throw new Error(`fetchAppPasswords failed: ${res.status}`)
  return res.json() as Promise<AppPassword[]>
}

export async function createAppPassword(
  runtime: WPHeadlessRuntime | null,
  data: { name: string; app_id?: string }
): Promise<NewAppPassword> {
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
    const err = (await res.json().catch(() => ({}))) as { message?: string }
    throw new Error(err.message || `createAppPassword failed: ${res.status}`)
  }
  return res.json() as Promise<NewAppPassword>
}

export async function deleteAppPassword(runtime: WPHeadlessRuntime | null, uuid: string): Promise<unknown> {
  const nonce = runtime?.rest?.nonce
  const res = await fetch(`${wpV2Root(runtime)}/users/me/application-passwords/${uuid}`, {
    method: 'DELETE',
    headers: { ...(nonce ? { 'X-WP-Nonce': nonce } : {}) },
    credentials: 'include',
  })
  if (!res.ok) throw new Error(`deleteAppPassword failed: ${res.status}`)
  return res.json()
}

// ---------------------------------------------------------------------------
// Users (authors)
// ---------------------------------------------------------------------------

export async function fetchUser(
  runtime: WPHeadlessRuntime | null,
  id: number
): Promise<WPUser | null> {
  const res = await fetch(`${wpV2Root(runtime)}/users/${id}`)
  if (res.status === 404 || res.status === 401) return null
  if (!res.ok) throw new Error(`fetchUser failed: ${res.status} ${res.statusText}`)
  return res.json() as Promise<WPUser>
}

// ---------------------------------------------------------------------------
// Menus
// ---------------------------------------------------------------------------

export async function fetchMenu(
  runtime: WPHeadlessRuntime | null,
  params: { id?: number; slug?: string; location?: string }
): Promise<WPMenu | null> {
  const query = new URLSearchParams(
    Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)]))
  ).toString()

  const res = await fetch(`${headlessRoot(runtime)}/menus?${query}`)
  if (res.status === 404) return null
  if (!res.ok) throw new Error(`fetchMenu failed: ${res.status} ${res.statusText}`)
  return res.json() as Promise<WPMenu>
}

// ---------------------------------------------------------------------------
// URL resolution
// ---------------------------------------------------------------------------

export async function resolveUrl(
  runtime: WPHeadlessRuntime | null,
  url: string
): Promise<WPHeadlessRequest> {
  const res = await fetch(
    `${headlessRoot(runtime)}/resolve?url=${encodeURIComponent(url)}`
  )
  if (!res.ok) throw new Error(`resolveUrl failed: ${res.status} ${res.statusText}`)
  return res.json() as Promise<WPHeadlessRequest>
}

// ---------------------------------------------------------------------------
// Runtime endpoint
// ---------------------------------------------------------------------------

export async function fetchRuntime(
  runtime: WPHeadlessRuntime | null,
  url?: string
): Promise<Record<string, unknown>> {
  const query = url ? `?url=${encodeURIComponent(url)}` : ''
  const res = await fetch(`${headlessRoot(runtime)}/runtime${query}`)
  if (!res.ok) throw new Error(`fetchRuntime failed: ${res.status} ${res.statusText}`)
  return res.json() as Promise<Record<string, unknown>>
}
