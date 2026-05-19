import styles from './featured-image.module.css'

/**
 * Featured image for a post or page.
 *
 * Accepts the `featured_image` object added by wp-headless ContentFields:
 *   { id, url, thumbnail, alt, caption, srcset, sizes, width, height, mime }
 *
 * `loading` defaults to "eager" because featured images on singular templates
 * are above the fold — set loading="lazy" via the prop when used in a list.
 *
 * @param {{ image: object|null, alt?: string, loading?: 'eager'|'lazy', sizes?: string }} props
 */
export function FeaturedImage({ image, alt, loading = 'eager', sizes }) {
  if (!image?.url) return null

  const computedSizes = sizes ?? image.sizes ?? '(max-width: 1200px) 100vw, 1200px'

  return (
    <figure className={styles.figure}>
      <img
        src={image.url}
        alt={alt ?? image.alt ?? ''}
        srcSet={image.srcset || undefined}
        sizes={image.srcset ? computedSizes : undefined}
        width={image.width || undefined}
        height={image.height || undefined}
        className={styles.image}
        loading={loading}
        decoding="async"
      />
      {image.caption && (
        <figcaption className={styles.caption} dangerouslySetInnerHTML={{ __html: image.caption }} />
      )}
    </figure>
  )
}
