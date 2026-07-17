<?php
/**
 * llms.txt endpoint module.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Seo;

use WP;
use WP_Query;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;

/**
 * Serves /llms.txt (and optionally /llms-full.txt) — the site map for
 * LLM/AI agents (llmstxt.org). Essential for headless sites: the served
 * HTML body is an empty SPA shell, so this endpoint is a primary
 * machine-readable content channel.
 */
class LlmsTxt implements Module {
	public const QUERY_VAR      = 'wp_headless_llms';
	public const FULL_QUERY_VAR = 'wp_headless_llms_full';

	/** @var Config */
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function register(): void {
		if ( ! $this->config->get( 'seo.llms.enabled', true ) ) {
			return;
		}

		add_action(
			'init',
			function () {
				self::register_rules( $this->config );
			}
		);

		add_filter(
			'query_vars',
			function ( array $query_vars ) {
				$query_vars[] = self::QUERY_VAR;
				$query_vars[] = self::FULL_QUERY_VAR;
				return $query_vars;
			}
		);

		// Priority 0: stream the document and exit before FrontendBridge
		// (or anything else) can claim the request — same pattern as
		// AssetProxy::maybe_serve_asset().
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 0 );

		// After register_rules() has run on init@10: self-heal stale caches.
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
	}

	/**
	 * Register the llms.txt rewrite rules.
	 *
	 * Static so Plugin::activate() can prime the rules before it flushes
	 * (mirrors RewriteRules::register_rules()).
	 *
	 * @param Config $config Shared config.
	 * @return void
	 */
	public static function register_rules( Config $config ): void {
		if ( ! $config->get( 'seo.llms.enabled', true ) ) {
			return;
		}

		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		if ( $config->get( 'seo.llms.full', false ) ) {
			add_rewrite_rule( '^llms-full\.txt$', 'index.php?' . self::FULL_QUERY_VAR . '=1', 'top' );
		}
	}

	/**
	 * Serve the requested llms document, when this is one.
	 *
	 * @param WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function maybe_serve( WP $wp ): void {
		$index_var = isset( $wp->query_vars[ self::QUERY_VAR ] ) ? (string) $wp->query_vars[ self::QUERY_VAR ] : '';
		$full_var  = isset( $wp->query_vars[ self::FULL_QUERY_VAR ] ) ? (string) $wp->query_vars[ self::FULL_QUERY_VAR ] : '';

		if ( '' !== $index_var ) {
			$this->serve( false );
		}

		// The full document only answers when opted in — the query var is
		// registered unconditionally, so guard direct ?query-var requests too.
		if ( '' !== $full_var && $this->config->get( 'seo.llms.full', false ) ) {
			$this->serve( true );
		}
	}

	/**
	 * Assemble, filter, render, and stream one llms document. Never returns.
	 *
	 * @param bool $full Whether to serve llms-full.txt (full post content).
	 * @return void
	 */
	protected function serve( bool $full ): void {
		$data = $this->collect_data( $full );

		/**
		 * Filters the assembled llms.txt data before rendering.
		 *
		 * @param array<string, mixed> $data   Document data (title, summary,
		 *                                     description, sections, optional).
		 * @param Config               $config Plugin config.
		 */
		$data = (array) apply_filters( 'wp_headless_llms_txt_data', $data, $this->config );

		/**
		 * Filters the rendered llms.txt plain-text output.
		 *
		 * @param string               $output Rendered document.
		 * @param array<string, mixed> $data   Data it was rendered from.
		 */
		$output = apply_filters( 'wp_headless_llms_txt_output', $this->render_output( $data, $full ), $data );

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		// Deliberately cacheable (unlike admin/REST responses): the document
		// is published content only, and crawlers poll it aggressively.
		header( 'Cache-Control: public, max-age=3600' );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text document, tags stripped during assembly.
		exit;
	}

	/**
	 * Assemble the document data from site state.
	 *
	 * @param bool $full Whether to include full post content per item.
	 * @return array<string, mixed>
	 */
	protected function collect_data( bool $full ): array {
		$data = array(
			'title'       => (string) get_bloginfo( 'name' ),
			'summary'     => (string) get_bloginfo( 'description' ),
			'description' => (string) $this->config->get( 'seo.llms.description', '' ),
			'sections'    => array(),
			'optional'    => array(),
		);

		$max_items = (int) $this->config->get( 'seo.llms.max_items', 100 );

		foreach ( $this->post_types() as $post_type ) {
			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => $max_items,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				)
			);

			if ( empty( $query->posts ) ) {
				continue;
			}

			$items = array();

			foreach ( $query->posts as $post ) {
				$item = array(
					'title'       => (string) get_the_title( $post ),
					'url'         => (string) get_permalink( $post ),
					'description' => $this->item_description( $post ),
				);

				if ( $full ) {
					$item['content'] = $this->item_content( $post );
				}

				$items[] = $item;
			}

			$data['sections'][ $this->post_type_label( $post_type ) ] = $items;
		}

		$sitemap_url = function_exists( 'get_sitemap_url' ) ? (string) get_sitemap_url( 'index' ) : '';

		$data['optional'] = array(
			array(
				'title' => 'Sitemap',
				'url'   => '' !== $sitemap_url ? $sitemap_url : home_url( '/wp-sitemap.xml' ),
			),
			array(
				'title' => 'REST API',
				'url'   => (string) rest_url(),
			),
		);

		return $data;
	}

	/**
	 * Render the assembled data as an llms.txt markdown document.
	 *
	 * Pure string assembly — no WordPress calls — so it stays unit-testable
	 * and add-ons can safely re-render filtered data.
	 *
	 * @param array<string, mixed> $data Document data.
	 * @param bool                 $full Whether to render the full variant.
	 * @return string
	 */
	public function render_output( array $data, bool $full = false ): string {
		$blocks = array();

		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' !== $title ) {
			$blocks[] = '# ' . $title;
		}

		$summary = trim( (string) ( $data['summary'] ?? '' ) );
		if ( '' !== $summary ) {
			$blocks[] = '> ' . $summary;
		}

		$description = trim( (string) ( $data['description'] ?? '' ) );
		if ( '' !== $description ) {
			$blocks[] = $description;
		}

		foreach ( (array) ( $data['sections'] ?? array() ) as $label => $items ) {
			$section_blocks = $full
				? $this->render_full_section( (array) $items )
				: $this->render_index_section( (array) $items );

			if ( array() === $section_blocks ) {
				continue;
			}

			$blocks[] = '## ' . $label;

			foreach ( $section_blocks as $section_block ) {
				$blocks[] = $section_block;
			}
		}

		$optional_lines = array();

		foreach ( (array) ( $data['optional'] ?? array() ) as $item ) {
			$line = $this->render_item_line( (array) $item );

			if ( '' !== $line ) {
				$optional_lines[] = $line;
			}
		}

		if ( array() !== $optional_lines ) {
			$blocks[] = '## Optional';
			$blocks[] = implode( "\n", $optional_lines );
		}

		if ( array() === $blocks ) {
			return '';
		}

		return implode( "\n\n", $blocks ) . "\n";
	}

	/**
	 * Flush the rewrite cache when our rules are missing — self-heals sites
	 * that updated the plugin (or toggled seo.llms.*) without re-activating.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites(): void {
		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return;
		}

		$missing_index = ! isset( $rules['^llms\.txt$'] );
		$missing_full  = $this->config->get( 'seo.llms.full', false ) && ! isset( $rules['^llms-full\.txt$'] );

		if ( $missing_index || $missing_full ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * The link list for one index section: zero or one block.
	 *
	 * @param array<int, mixed> $items Section items.
	 * @return array<int, string>
	 */
	private function render_index_section( array $items ): array {
		$lines = array();

		foreach ( $items as $item ) {
			$line = $this->render_item_line( (array) $item );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return array() === $lines ? array() : array( implode( "\n", $lines ) );
	}

	/**
	 * The blocks for one full section: per item an H3 heading, its URL,
	 * and its plain-text content.
	 *
	 * @param array<int, mixed> $items Section items.
	 * @return array<int, string>
	 */
	private function render_full_section( array $items ): array {
		$blocks = array();

		foreach ( $items as $item ) {
			$item  = (array) $item;
			$title = trim( (string) ( $item['title'] ?? '' ) );
			$url   = trim( (string) ( $item['url'] ?? '' ) );

			if ( '' === $title && '' === $url ) {
				continue;
			}

			$blocks[] = '### ' . ( '' !== $title ? $title : $url );

			if ( '' !== $url ) {
				$blocks[] = $url;
			}

			$content = trim( (string) ( $item['content'] ?? '' ) );

			if ( '' !== $content ) {
				$blocks[] = $content;
			}
		}

		return $blocks;
	}

	/**
	 * One '- [Title](url): description' line; description omitted when empty.
	 *
	 * @param array<string, mixed> $item Item data.
	 * @return string
	 */
	private function render_item_line( array $item ): string {
		$title = trim( (string) ( $item['title'] ?? '' ) );
		$url   = trim( (string) ( $item['url'] ?? '' ) );

		if ( '' === $title && '' === $url ) {
			return '';
		}

		$line        = '- [' . ( '' !== $title ? $title : $url ) . '](' . $url . ')';
		$description = trim( (string) ( $item['description'] ?? '' ) );

		if ( '' !== $description ) {
			$line .= ': ' . $description;
		}

		return $line;
	}

	/**
	 * Post types to index: the configured list, or every public REST-exposed
	 * type minus attachments (same semantics as ContentFields).
	 *
	 * @return array<int, string>
	 */
	private function post_types(): array {
		$configured = (array) $this->config->get( 'seo.llms.post_types', array() );

		if ( array() !== $configured ) {
			return array_values( array_map( 'strval', $configured ) );
		}

		$types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'names'
		);
		unset( $types['attachment'] );

		return array_values( $types );
	}

	/**
	 * Human label for a post type, falling back to its slug.
	 *
	 * @param string $post_type Post type name.
	 * @return string
	 */
	private function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		if ( $object && isset( $object->labels->name ) && '' !== (string) $object->labels->name ) {
			return (string) $object->labels->name;
		}

		return $post_type;
	}

	/**
	 * Short plain-text description for the index list: excerpt first,
	 * content fallback, clipped to a link-list-friendly length.
	 *
	 * @param mixed $post Post object.
	 * @return string
	 */
	private function item_description( $post ): string {
		$excerpt = (string) get_the_excerpt( $post );

		if ( '' !== trim( $excerpt ) ) {
			return $this->clip( $excerpt );
		}

		$content = isset( $post->post_content ) ? (string) $post->post_content : '';

		return $this->clip( wp_strip_all_tags( strip_shortcodes( $content ) ) );
	}

	/**
	 * Full plain-text content for llms-full.txt: whitespace-collapsed,
	 * never clipped, excerpt fallback when the content is empty.
	 *
	 * @param mixed $post Post object.
	 * @return string
	 */
	private function item_content( $post ): string {
		$content = isset( $post->post_content ) ? (string) $post->post_content : '';
		$text    = wp_strip_all_tags( strip_shortcodes( $content ) );

		if ( '' === trim( $text ) ) {
			$text = isset( $post->post_excerpt ) ? wp_strip_all_tags( (string) $post->post_excerpt ) : '';
		}

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Collapse whitespace and clip to a description-friendly length.
	 *
	 * SeoHead carries the same helper; both stay private on purpose so the
	 * two modules remain independently removable.
	 *
	 * @param string $text  Raw text.
	 * @param int    $limit Maximum length in characters.
	 * @return string
	 */
	private function clip( string $text, int $limit = 160 ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$clipped = mb_substr( $text, 0, $limit - 1 );
		$space   = mb_strrpos( $clipped, ' ' );

		if ( false !== $space && $space > 0 ) {
			$clipped = mb_substr( $clipped, 0, $space );
		}

		return $clipped . '…';
	}
}
