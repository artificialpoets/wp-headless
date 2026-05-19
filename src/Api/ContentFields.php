<?php
/**
 * REST field enhancements for frontend consumers.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Api;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;

class ContentFields implements Module {
	/**
	 * Config.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Shared config.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_fields' ) );
	}

	/**
	 * Register custom REST fields.
	 *
	 * @return void
	 */
	public function register_fields(): void {
		$post_types = (array) apply_filters( 'wp_headless_rest_post_types', $this->config->get( 'rest.post_types', array( 'post', 'page' ) ), $this->config );
		$post_types = array_values(
			array_filter(
				$post_types,
				static function ( $post_type ) {
					return is_string( $post_type ) && post_type_exists( $post_type );
				}
			)
		);

		if ( empty( $post_types ) ) {
			return;
		}

		$fields = array(
			'featured_image_url' => array(
				'get_callback' => array( $this, 'get_featured_image_url' ),
				'schema'       => array(
					'description' => __( 'Featured image URL.', 'wp-headless' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
				),
			),
			'featured_image'     => array(
				'get_callback' => array( $this, 'get_featured_image' ),
				'schema'       => array(
					'description' => __( 'Featured image metadata.', 'wp-headless' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
				),
			),
			'author_info'        => array(
				'get_callback' => array( $this, 'get_author_info' ),
				'schema'       => array(
					'description' => __( 'Author summary.', 'wp-headless' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
				),
			),
			'permalink'          => array(
				'get_callback' => array( $this, 'get_permalink_field' ),
				'schema'       => array(
					'description' => __( 'Resolved permalink.', 'wp-headless' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
				),
			),
			'comment_count'      => array(
				'get_callback' => array( $this, 'get_comment_count' ),
				'schema'       => array(
					'description' => __( 'Approved comment count.', 'wp-headless' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
				),
			),
			'adjacent'           => array(
				'get_callback' => array( $this, 'get_adjacent' ),
				'schema'       => array(
					'description' => __( 'Previous and next posts (chronological).', 'wp-headless' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
			),
		);

		$fields = apply_filters( 'wp_headless_rest_fields', $fields, $post_types, $this->config );

		foreach ( $fields as $name => $args ) {
			register_rest_field( $post_types, $name, $args );
		}
	}

	/**
	 * Get featured image url.
	 *
	 * @param array<string, mixed> $object REST object.
	 * @return string|null
	 */
	public function get_featured_image_url( array $object ): ?string {
		$image_id = get_post_thumbnail_id( (int) $object['id'] );

		return $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : null;
	}

	/**
	 * Get featured image metadata.
	 *
	 * @param array<string, mixed> $object REST object.
	 * @return array<string, mixed>|null
	 */
	public function get_featured_image( array $object ): ?array {
		$image_id = get_post_thumbnail_id( (int) $object['id'] );

		if ( ! $image_id ) {
			return null;
		}

		$meta   = wp_get_attachment_metadata( $image_id );
		$width  = isset( $meta['width'] ) ? (int) $meta['width'] : null;
		$height = isset( $meta['height'] ) ? (int) $meta['height'] : null;

		return array(
			'id'        => $image_id,
			'url'       => wp_get_attachment_image_url( $image_id, 'full' ),
			'thumbnail' => wp_get_attachment_image_url( $image_id, 'thumbnail' ),
			'alt'       => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			'caption'   => wp_get_attachment_caption( $image_id ),
			'srcset'    => wp_get_attachment_image_srcset( $image_id, 'full' ),
			'sizes'     => wp_get_attachment_image_sizes( $image_id, 'full' ),
			'width'     => $width,
			'height'    => $height,
			'mime'      => get_post_mime_type( $image_id ),
		);
	}

	/**
	 * Get author summary.
	 *
	 * @param array<string, mixed> $object REST object.
	 * @return array<string, mixed>|null
	 */
	public function get_author_info( array $object ): ?array {
		$author_id = isset( $object['author'] ) ? (int) $object['author'] : 0;

		if ( ! $author_id ) {
			return null;
		}

		return array(
			'id'          => $author_id,
			'name'        => get_the_author_meta( 'display_name', $author_id ),
			'slug'        => get_the_author_meta( 'user_nicename', $author_id ),
			'description' => (string) get_the_author_meta( 'description', $author_id ),
			'url'         => get_the_author_meta( 'user_url', $author_id ),
			'avatar'      => get_avatar_url( $author_id, array( 'size' => 96 ) ),
			'link'        => get_author_posts_url( $author_id ),
		);
	}

	/**
	 * Get permalink field.
	 *
	 * @param array<string, mixed> $object REST object.
	 * @return string
	 */
	public function get_permalink_field( array $object ): string {
		return (string) get_permalink( (int) $object['id'] );
	}

	/**
	 * Approved comment count for the post.
	 *
	 * @param array<string, mixed> $object REST object.
	 * @return int
	 */
	public function get_comment_count( array $object ): int {
		$count = wp_count_comments( (int) $object['id'] );

		return (int) ( $count->approved ?? 0 );
	}

	/**
	 * Previous / next post summary (chronological adjacency, same post_type).
	 *
	 * Only returns adjacency for the singular request post_type so that, for
	 * example, the next/prev links on a page don't surface unrelated posts.
	 *
	 * @param array<string, mixed> $object REST object.
	 * @return array<string, array<string, mixed>|null>
	 */
	public function get_adjacent( array $object ): array {
		$post = get_post( (int) $object['id'] );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'previous' => null,
				'next'     => null,
			);
		}

		$previous = get_previous_post( false, '', 'category' );
		$next     = get_next_post( false, '', 'category' );

		// get_previous_post() reads the global $post; ensure it's set.
		$original = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		$previous = get_previous_post();
		$next     = get_next_post();
		if ( $original ) {
			$GLOBALS['post'] = $original;
			setup_postdata( $original );
		} else {
			wp_reset_postdata();
		}

		return array(
			'previous' => $previous instanceof \WP_Post ? $this->summarize_adjacent( $previous ) : null,
			'next'     => $next instanceof \WP_Post ? $this->summarize_adjacent( $next ) : null,
		);
	}

	/**
	 * Compact summary used in adjacent post payloads.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function summarize_adjacent( \WP_Post $post ): array {
		return array(
			'id'        => (int) $post->ID,
			'slug'      => $post->post_name,
			'title'     => get_the_title( $post ),
			'link'      => get_permalink( $post ),
			'post_type' => $post->post_type,
		);
	}
}
