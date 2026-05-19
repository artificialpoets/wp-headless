<?php
/**
 * Annotates each rendered block with data attributes carrying its block name
 * and attributes JSON. This lets the React frontend identify which blocks
 * appear in `post.content.rendered` and swap in custom React components for
 * specific block types via a client-side registry.
 *
 * Filterable on/off via `wp_headless_annotate_blocks`. Returns the original
 * HTML untouched if the filter returns false, or if the block has no
 * blockName (raw HTML, freeform).
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Contracts\Module;

class BlockAnnotator implements Module {

	public function register(): void {
		add_filter( 'render_block', array( $this, 'annotate' ), 100, 2 );
	}

	/**
	 * Inject `data-wph-block-name` and `data-wph-block-attrs` into the outermost
	 * element of each block's rendered HTML.
	 *
	 * @param string               $html  Block HTML.
	 * @param array<string, mixed> $block Parsed block array (blockName, attrs, innerHTML, …).
	 * @return string
	 */
	public function annotate( string $html, array $block ): string {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( '' === $name ) {
			return $html;
		}

		if ( ! (bool) apply_filters( 'wp_headless_annotate_blocks', true, $block ) ) {
			return $html;
		}

		$attrs = $block['attrs'] ?? array();
		$attrs_json = wp_json_encode( empty( $attrs ) ? new \stdClass() : $attrs );
		if ( false === $attrs_json ) {
			$attrs_json = '{}';
		}

		// Match the first opening tag — accept self-closing variants and tags
		// that include their own attributes. Capture groups:
		//   1: leading whitespace, 2: <tagname, 3: rest of attrs, 4: />|>
		$pattern = '#^(\s*)(<[a-zA-Z][a-zA-Z0-9-]*)\b([^>]*?)(\s*/?>)#s';
		if ( ! preg_match( $pattern, $html ) ) {
			// Block emits raw text / has no wrapping element — nothing to annotate.
			return $html;
		}

		$replacement = '$1$2 data-wph-block-name="' . esc_attr( $name ) . '" data-wph-block-attrs="' . esc_attr( $attrs_json ) . '"$3$4';
		return (string) preg_replace( $pattern, $replacement, $html, 1 );
	}
}
