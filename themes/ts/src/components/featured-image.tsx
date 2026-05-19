import type { WPFeaturedImage } from '../types/api'
import styles from './featured-image.module.css'

interface FeaturedImageProps {
  image: WPFeaturedImage | null
  alt?: string
  loading?: 'eager' | 'lazy'
  sizes?: string
}

/**
 * Featured image for a post or page.
 *
 * Accepts the `featured_image` object added by wp-headless ContentFields:
 *   { id, url, thumbnail, alt, caption, srcset, sizes, width, height, mime }
 */
export function FeaturedImage({ image, alt, loading = 'eager', sizes }: FeaturedImageProps) {
  if (!image?.url) return null

  const computedSizes = sizes ?? (image.sizes || '(max-width: 1200px) 100vw, 1200px')

  return (
    <figure className={styles.figure}>
      <img
        src={image.url as string}
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
        <figcaption className={styles.caption} dangerouslySetInnerHTML={{ __html: image.caption as string }} />
      )}
    </figure>
  )
}
