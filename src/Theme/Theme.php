<?php
/**
 * Headless theme value object — a thin wrapper around a WordPress theme that
 * surfaces the React build path (dist/) used by the wp-headless plugin.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Theme;

class Theme {
	/** @var string Theme stylesheet directory name (slug). */
	private string $slug;

	/** @var string Absolute path to theme directory. */
	private string $path;

	/** @var \WP_Theme|null Underlying WP theme object. */
	private ?\WP_Theme $wp_theme;

	/**
	 * @param string         $slug     Theme stylesheet slug (folder name in wp-content/themes).
	 * @param string         $path     Absolute path to theme directory.
	 * @param \WP_Theme|null $wp_theme Underlying WP theme.
	 */
	public function __construct( string $slug, string $path, ?\WP_Theme $wp_theme = null ) {
		$this->slug     = $slug;
		$this->path     = $path;
		$this->wp_theme = $wp_theme;
	}

	public function slug(): string {
		return $this->slug;
	}

	public function path(): string {
		return $this->path;
	}

	public function name(): string {
		if ( $this->wp_theme ) {
			return (string) $this->wp_theme->display( 'Name', false ) ?: $this->slug;
		}
		return $this->slug;
	}

	public function description(): string {
		if ( $this->wp_theme ) {
			return (string) $this->wp_theme->display( 'Description', false );
		}
		return '';
	}

	public function version(): string {
		if ( $this->wp_theme ) {
			return (string) $this->wp_theme->display( 'Version', false );
		}
		return '';
	}

	public function author(): string {
		if ( $this->wp_theme ) {
			return (string) $this->wp_theme->display( 'Author', false );
		}
		return '';
	}

	/** Parent theme slug, or null when this is a parent theme. */
	public function parent(): ?string {
		if ( $this->wp_theme ) {
			$parent = $this->wp_theme->parent();
			if ( $parent instanceof \WP_Theme ) {
				return (string) $parent->get_stylesheet();
			}
		}
		return null;
	}

	/** Absolute path to the theme's React build output. */
	public function dist_path(): string {
		return $this->path . '/dist';
	}

	/** True when dist/index.html exists — i.e. the theme has a deployable React build. */
	public function has_dist(): bool {
		return is_readable( $this->dist_path() . '/index.html' );
	}

	public function screenshot_url(): string {
		if ( $this->wp_theme ) {
			return (string) $this->wp_theme->get_screenshot();
		}
		return '';
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'slug'        => $this->slug,
			'name'        => $this->name(),
			'description' => $this->description(),
			'version'     => $this->version(),
			'author'      => $this->author(),
			'parent'      => $this->parent(),
			'has_dist'    => $this->has_dist(),
		);
	}
}
