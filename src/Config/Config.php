<?php
/**
 * Runtime configuration.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Config;

class Config {
	/**
	 * Normalized configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$config = $this->defaults();
		$config = $this->merge( $config, $this->environment_overrides() );
		$config = $this->merge( $config, $this->project_overrides( $config ) );
		$config = apply_filters( 'wp_headless_config', $config );

		$this->config = $this->normalize( is_array( $config ) ? $config : array() );
	}

	/**
	 * Get the full config array.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->config;
	}

	/**
	 * Get a config value using dot notation.
	 *
	 * @param string $key Dot-notated key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$segments = explode( '.', $key );
		$value    = $this->config;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Whether headless mode is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->get( 'enabled', true );
	}

	/**
	 * REST namespace.
	 *
	 * @return string
	 */
	public function namespace(): string {
		return (string) $this->get( 'namespace', 'wp-headless/v1' );
	}

	/**
	 * External project directory.
	 *
	 * @return string
	 */
	public function project_dir(): string {
		return (string) $this->get( 'project_dir', '' );
	}

	/**
	 * Headless themes directory (project_dir/themes/).
	 *
	 * @return string
	 */
	public function themes_dir(): string {
		return trailingslashit( $this->project_dir() ) . 'themes';
	}

	/**
	 * Absolute index file path.
	 *
	 * @return string
	 */
	public function index_file_path(): string {
		$index_file = (string) $this->get( 'frontend.index_file', 'dist/index.html' );

		if ( $this->is_absolute_path( $index_file ) ) {
			return $index_file;
		}

		return trailingslashit( $this->project_dir() ) . ltrim( $index_file, '/' );
	}

	/**
	 * Absolute build root path.
	 *
	 * @return string
	 */
	public function build_root(): string {
		return untrailingslashit( dirname( $this->index_file_path() ) );
	}

	/**
	 * Whether the external frontend build exists.
	 *
	 * @return bool
	 */
	public function frontend_available(): bool {
		return is_readable( $this->index_file_path() );
	}

	/**
	 * Asset mount, without leading or trailing slashes.
	 *
	 * @return string
	 */
	public function asset_mount(): string {
		return (string) $this->get( 'frontend.asset_mount', '_wp-headless' );
	}

	/**
	 * Proxy asset base URL.
	 *
	 * @return string
	 */
	public function asset_route_base_url(): string {
		return home_url( '/' . $this->asset_mount() );
	}

	/**
	 * Public asset base URL used in rewritten HTML.
	 *
	 * @return string
	 */
	public function asset_base_url(): string {
		$configured = (string) $this->get( 'frontend.asset_base_url', '' );

		return '' !== $configured ? untrailingslashit( $configured ) : untrailingslashit( $this->asset_route_base_url() );
	}

	/**
	 * Default configuration.
	 *
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return array(
			'enabled'      => true,
			'namespace'    => 'wp-headless/v1',
			'project_dir'  => WP_CONTENT_DIR . '/headless',
			'project_config' => 'wp-headless.config.php',
			'frontend'     => array(
				'index_file'     => 'dist/index.html',
				'asset_mount'    => '_wp-headless',
				'asset_base_url' => '',
				'inject_wp_head' => true,
				'inject_wp_footer' => true,
			),
			'cors'         => array(
				'enabled'           => true,
				'allow_credentials' => true,
				'allowed_origins'   => array( home_url() ),
				'allowed_methods'   => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ),
				'allowed_headers'   => array( 'Authorization', 'Content-Type', 'X-WP-Nonce' ),
				'exposed_headers'   => array( 'X-WP-Total', 'X-WP-TotalPages', 'Link' ),
				'max_age'           => 86400,
			),
			'rest'         => array(
				// Empty = auto-discover every public, REST-enabled post type
				// (see ContentFields). Set an explicit list to restrict enrichment.
				'post_types' => array(),
			),
			'seo'          => array(
				// Last-resort social image when a page has no featured image,
				// custom logo, or site icon.
				'default_image' => '',
				'schema'        => array(
					'enabled'            => true,
					'article_post_types' => array( 'post' ),
					'search_action'      => true,
					'organization'       => array(
						'name'    => '',
						'logo'    => '',
						'same_as' => array(),
					),
				),
				'llms'          => array(
					'enabled'     => true,
					// llms-full.txt (full post content) is opt-in.
					'full'        => false,
					// Empty = auto-discover, same semantics as rest.post_types.
					'post_types'  => array(),
					'max_items'   => 100,
					'description' => '',
				),
				'robots_txt'    => array(
					'enabled'      => true,
					// 'allow' appends nothing restrictive (plus an llms.txt
					// pointer); 'block' emits Disallow stanzas for AI crawlers.
					'ai_policy'    => 'allow',
					// Overrides the built-in AI agent list when non-empty.
					'block_agents' => array(),
					// Raw extra robots.txt lines appended verbatim.
					'extra'        => '',
				),
				'head_cleanup'  => array(
					'enabled'           => true,
					'rsd'               => true,
					'wlw'               => true,
					'generator'         => true,
					'shortlink'         => true,
					'adjacent_posts'    => true,
					'emoji'             => true,
					'oembed_host_js'    => true,
					// Kept by default: the plugin deliberately serves is_embed().
					'oembed_discovery'  => false,
					// Kept by default: RSS surface stays intact.
					'feed_links_extra'  => false,
					// Kept by default: REST discovery is a headless feature.
					'rest_link'         => false,
				),
			),
			// Per-module boot toggles: modules.{key}.enabled => false disables
			// a module (keys match Plugin's module map). Unknown keys are
			// preserved for add-on modules.
			'modules'      => array(),
		);
	}

	/**
	 * Read env and constant overrides.
	 *
	 * @return array<string, mixed>
	 */
	protected function environment_overrides(): array {
		$overrides = array();

		if ( defined( 'WP_HEADLESS_ENABLED' ) ) {
			$overrides['enabled'] = (bool) WP_HEADLESS_ENABLED;
		}

		if ( defined( 'WP_HEADLESS_PROJECT_DIR' ) ) {
			$overrides['project_dir'] = (string) WP_HEADLESS_PROJECT_DIR;
		}

		if ( defined( 'WP_HEADLESS_CONFIG_FILE' ) ) {
			$overrides['project_config'] = (string) WP_HEADLESS_CONFIG_FILE;
		}

		if ( defined( 'WP_HEADLESS_ASSET_BASE_URL' ) ) {
			$overrides['frontend']['asset_base_url'] = (string) WP_HEADLESS_ASSET_BASE_URL;
		}

		if ( defined( 'WP_HEADLESS_CONFIG' ) && is_array( WP_HEADLESS_CONFIG ) ) {
			$overrides = $this->merge( $overrides, WP_HEADLESS_CONFIG );
		}

		return $overrides;
	}

	/**
	 * Load optional external project config.
	 *
	 * @param array<string, mixed> $config Config accumulated so far.
	 * @return array<string, mixed>
	 */
	protected function project_overrides( array $config ): array {
		$config_file = isset( $config['project_config'] ) ? (string) $config['project_config'] : '';
		$project_dir = isset( $config['project_dir'] ) ? (string) $config['project_dir'] : '';

		if ( '' === $config_file ) {
			return array();
		}

		if ( ! $this->is_absolute_path( $config_file ) ) {
			$config_file = trailingslashit( $project_dir ) . ltrim( $config_file, '/' );
		}

		if ( ! is_readable( $config_file ) ) {
			return array();
		}

		$loaded = require $config_file;

		return is_array( $loaded ) ? $loaded : array();
	}

	/**
	 * Normalize config values.
	 *
	 * @param array<string, mixed> $config Raw config.
	 * @return array<string, mixed>
	 */
	protected function normalize( array $config ): array {
		$config['enabled']        = ! empty( $config['enabled'] );
		$config['namespace']      = trim( (string) ( $config['namespace'] ?? 'wp-headless/v1' ), '/' );
		$config['project_dir']    = $this->normalize_path_value( (string) ( $config['project_dir'] ?? '' ), WP_CONTENT_DIR );
		$config['project_config'] = (string) ( $config['project_config'] ?? 'wp-headless.config.php' );

		if ( ! isset( $config['frontend'] ) || ! is_array( $config['frontend'] ) ) {
			$config['frontend'] = array();
		}

		$config['frontend']['index_file']       = $this->normalize_index_file( (string) ( $config['frontend']['index_file'] ?? 'dist/index.html' ) );
		$config['frontend']['asset_mount']      = trim( (string) ( $config['frontend']['asset_mount'] ?? '_wp-headless' ), '/' );
		$config['frontend']['asset_base_url']   = untrailingslashit( (string) ( $config['frontend']['asset_base_url'] ?? '' ) );
		$config['frontend']['inject_wp_head']   = ! empty( $config['frontend']['inject_wp_head'] );
		$config['frontend']['inject_wp_footer'] = ! empty( $config['frontend']['inject_wp_footer'] );

		if ( ! isset( $config['cors'] ) || ! is_array( $config['cors'] ) ) {
			$config['cors'] = array();
		}

		$config['cors']['enabled']           = ! empty( $config['cors']['enabled'] );
		$config['cors']['allow_credentials'] = ! empty( $config['cors']['allow_credentials'] );
		$config['cors']['allowed_origins']   = $this->normalize_string_list( $config['cors']['allowed_origins'] ?? array() );
		$config['cors']['allowed_methods']   = $this->normalize_string_list( $config['cors']['allowed_methods'] ?? array() );
		$config['cors']['allowed_headers']   = $this->normalize_string_list( $config['cors']['allowed_headers'] ?? array() );
		$config['cors']['exposed_headers']   = $this->normalize_string_list( $config['cors']['exposed_headers'] ?? array() );
		$config['cors']['max_age']           = absint( $config['cors']['max_age'] ?? 86400 );

		if ( ! isset( $config['rest'] ) || ! is_array( $config['rest'] ) ) {
			$config['rest'] = array();
		}

		$config['rest']['post_types'] = $this->normalize_string_list( $config['rest']['post_types'] ?? array() );

		if ( ! isset( $config['seo'] ) || ! is_array( $config['seo'] ) ) {
			$config['seo'] = array();
		}

		$config['seo']['default_image'] = (string) ( $config['seo']['default_image'] ?? '' );

		$schema                                    = is_array( $config['seo']['schema'] ?? null ) ? $config['seo']['schema'] : array();
		$schema['enabled']                         = ! empty( $schema['enabled'] );
		$schema['article_post_types']              = $this->normalize_string_list( $schema['article_post_types'] ?? array() );
		$schema['search_action']                   = ! empty( $schema['search_action'] );
		$organization                              = is_array( $schema['organization'] ?? null ) ? $schema['organization'] : array();
		$schema['organization']                    = array(
			'name'    => (string) ( $organization['name'] ?? '' ),
			'logo'    => (string) ( $organization['logo'] ?? '' ),
			'same_as' => $this->normalize_string_list( $organization['same_as'] ?? array() ),
		);
		$config['seo']['schema']                   = $schema;

		$llms                          = is_array( $config['seo']['llms'] ?? null ) ? $config['seo']['llms'] : array();
		$llms['enabled']               = ! empty( $llms['enabled'] );
		$llms['full']                  = ! empty( $llms['full'] );
		$llms['post_types']            = $this->normalize_string_list( $llms['post_types'] ?? array() );
		$llms['max_items']             = absint( $llms['max_items'] ?? 100 );
		$llms['description']           = (string) ( $llms['description'] ?? '' );
		$config['seo']['llms']         = $llms;

		$robots                        = is_array( $config['seo']['robots_txt'] ?? null ) ? $config['seo']['robots_txt'] : array();
		$robots['enabled']             = ! empty( $robots['enabled'] );
		$robots['ai_policy']           = in_array( $robots['ai_policy'] ?? 'allow', array( 'allow', 'block' ), true )
			? $robots['ai_policy']
			: 'allow';
		$robots['block_agents']        = $this->normalize_string_list( $robots['block_agents'] ?? array() );
		$robots['extra']               = (string) ( $robots['extra'] ?? '' );
		$config['seo']['robots_txt']   = $robots;

		$cleanup                       = is_array( $config['seo']['head_cleanup'] ?? null ) ? $config['seo']['head_cleanup'] : array();
		foreach ( array( 'enabled', 'rsd', 'wlw', 'generator', 'shortlink', 'adjacent_posts', 'emoji', 'oembed_host_js', 'oembed_discovery', 'feed_links_extra', 'rest_link' ) as $cleanup_key ) {
			$cleanup[ $cleanup_key ] = ! empty( $cleanup[ $cleanup_key ] );
		}
		$config['seo']['head_cleanup'] = $cleanup;

		// Module toggles pass through untouched: unknown keys belong to
		// add-on modules and must survive normalization.
		if ( ! isset( $config['modules'] ) || ! is_array( $config['modules'] ) ) {
			$config['modules'] = array();
		}

		return $config;
	}

	/**
	 * Normalize an index file value while preserving absolute paths.
	 *
	 * @param string $index_file Raw index file config.
	 * @return string
	 */
	protected function normalize_index_file( string $index_file ): string {
		$index_file = trim( $index_file );

		if ( $this->is_absolute_path( $index_file ) ) {
			return untrailingslashit( $index_file );
		}

		return ltrim( $index_file, '/' );
	}

	/**
	 * Merge arrays recursively with override semantics.
	 *
	 * @param array<string, mixed> $base Base values.
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	protected function merge( array $base, array $overrides ): array {
		foreach ( $overrides as $key => $value ) {
			if ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && is_array( $value ) ) {
				$base[ $key ] = $this->merge( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	/**
	 * Normalize a string list.
	 *
	 * @param mixed $values Raw values.
	 * @return array<int, string>
	 */
	protected function normalize_string_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $values as $value ) {
			$value = trim( (string) $value );

			if ( '' !== $value ) {
				$normalized[] = $value;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize a path-like config value.
	 *
	 * @param string $path Raw path value.
	 * @param string $base Base path for relative values.
	 * @return string
	 */
	protected function normalize_path_value( string $path, string $base ): string {
		$path = trim( $path );

		if ( '' === $path ) {
			return '';
		}

		if ( ! $this->is_absolute_path( $path ) ) {
			$path = trailingslashit( $base ) . ltrim( $path, '/' );
		}

		return untrailingslashit( $path );
	}

	/**
	 * Check whether a path is absolute.
	 *
	 * @param string $path Path to inspect.
	 * @return bool
	 */
	protected function is_absolute_path( string $path ): bool {
		return 1 === preg_match( '#^(?:[A-Z]:[\\\\/]|/)#i', $path );
	}
}
