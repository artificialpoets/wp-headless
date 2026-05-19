<?php
/**
 * URL resolution endpoint for decoupled frontends.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Api;

use WP_REST_Request;
use WP_REST_Response;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Runtime\RequestDataBuilder;

class ResolveEndpoint implements Module {
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
			'/resolve',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'resolve_url' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'url' => array(
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'URL or path to resolve.', 'wp-headless' ),
					),
				),
			)
		);
	}

	/**
	 * Resolve a URL or path into a best-effort WordPress object.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function resolve_url( WP_REST_Request $request ): WP_REST_Response {
		$resolver = new RequestDataBuilder();

		return new WP_REST_Response(
			$resolver->for_url( (string) $request['url'] )
		);
	}
}
