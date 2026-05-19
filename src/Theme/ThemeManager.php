<?php
/**
 * Headless theme manager.
 *
 * In this iteration of the plugin a "headless theme" is simply the active
 * WordPress theme — activated through Appearance → Themes like any other.
 * The plugin enters headless mode whenever the active theme has a readable
 * dist/index.html; otherwise it stands down and lets WordPress render
 * through the normal template hierarchy.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Theme;

class ThemeManager {

	/** Resolve the active theme as a Theme value object. */
	public function get_active_theme(): ?Theme {
		if ( ! function_exists( 'wp_get_theme' ) || ! function_exists( 'get_stylesheet_directory' ) ) {
			return null;
		}

		$dir = get_stylesheet_directory();

		if ( ! is_dir( $dir ) ) {
			return null;
		}

		$wp_theme = wp_get_theme();

		if ( $wp_theme->errors() ) {
			return null;
		}

		return new Theme( (string) $wp_theme->get_stylesheet(), $dir, $wp_theme );
	}

	/**
	 * Resolve the dist directory for the active theme, falling back to the
	 * parent theme when a child theme has no build of its own.
	 *
	 * @return string|null Absolute path or null when no build exists.
	 */
	public function resolve_dist_path(): ?string {
		$theme = $this->get_active_theme();

		if ( null === $theme ) {
			return null;
		}

		if ( $theme->has_dist() ) {
			return $theme->dist_path();
		}

		// Parent theme fallback.
		$template_dir = get_template_directory();
		$child_dir    = get_stylesheet_directory();

		if ( $template_dir !== $child_dir ) {
			$parent_dist = $template_dir . '/dist';
			if ( is_readable( $parent_dist . '/index.html' ) ) {
				return $parent_dist;
			}
		}

		return null;
	}

	/** Resolved absolute path to index.html, or null if unavailable. */
	public function resolve_index_path(): ?string {
		$dist = $this->resolve_dist_path();

		if ( null === $dist ) {
			return null;
		}

		$index = $dist . '/index.html';

		return is_readable( $index ) ? $index : null;
	}

	/** Whether the active theme has a deployable React build. */
	public function has_build(): bool {
		return null !== $this->resolve_index_path();
	}
}
