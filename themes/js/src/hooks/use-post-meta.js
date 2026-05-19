/**
 * Read post meta fields exposed via the REST API.
 *
 * WordPress only includes post meta in the REST response when the field is
 * registered with `register_post_meta()` and `show_in_rest: true`. Once it is,
 * the field appears under `post.meta[name]`. This hook is a thin, typed
 * accessor so consuming code reads `usePostMeta(post).price` instead of
 * `post.meta?.price ?? null`.
 *
 * Falsy meta values (empty string, 0, false) are returned as-is. Missing keys
 * return `undefined`.
 *
 * @example
 *   register_post_meta('book', 'isbn', array('type' => 'string', 'show_in_rest' => true, 'single' => true));
 *
 * @param {object|null} post
 * @returns {Record<string, any>}
 */
export function usePostMeta(post) {
  return post?.meta ?? {}
}

/**
 * Read a single post-meta value with optional fallback.
 *
 * @param {object|null} post
 * @param {string} key
 * @param {any} fallback
 */
export function usePostMetaValue(post, key, fallback = undefined) {
  const v = post?.meta?.[key]
  return v === undefined ? fallback : v
}
