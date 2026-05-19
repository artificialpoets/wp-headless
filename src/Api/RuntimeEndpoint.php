<?php
/**
 * Runtime REST endpoint.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Api;

use WP_REST_Request;
use WP_REST_Response;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Runtime\RequestDataBuilder;
use WPHeadless\Runtime\RuntimeDataBuilder;
use WPHeadless\Theme\ThemeManager;

class RuntimeEndpoint implements Module {
	/** @var Config */
	private Config $config;

	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	public function __construct( Config $config, ThemeManager $theme_manager ) {
		$this->theme_manager = $theme_manager;
		$this->config = $config;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->config->namespace(),
			'/runtime',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_runtime' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'url' => array(
						'type'        => 'string',
						'required'    => false,
						'description' => __( 'Optional URL to resolve into request context.', 'wp-headless' ),
					),
				),
			)
		);
	}

	/**
	 * Return runtime payload.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_runtime( WP_REST_Request $request ): WP_REST_Response {
		$runtime = new RuntimeDataBuilder( $this->config, $this->theme_manager, new RequestDataBuilder() );

		return new WP_REST_Response(
			$runtime->build( $request['url'] ? (string) $request['url'] : null )
		);
	}
}
