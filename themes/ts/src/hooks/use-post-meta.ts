/**
 * Read post meta fields exposed via the REST API.
 *
 * WordPress only includes post meta in the REST response when the field is
 * registered with `register_post_meta()` and `show_in_rest: true`. Once it is,
 * the field appears under `post.meta[name]`.
 */

import type { WPPost } from '../types/api'

export function usePostMeta(post: WPPost | null | undefined): Record<string, unknown> {
  return (post as unknown as { meta?: Record<string, unknown> })?.meta ?? {}
}

export function usePostMetaValue<T = unknown>(
  post: WPPost | null | undefined,
  key: string,
  fallback?: T
): T | unknown {
  const meta = (post as unknown as { meta?: Record<string, unknown> })?.meta
  const v = meta?.[key]
  return v === undefined ? fallback : v
}
