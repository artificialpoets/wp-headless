<?php
/**
 * Rewrite rules for proxied assets.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Routing;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;

class RewriteRules implements Module {
	/**
	 * Asset query var.
	 */
	public const ASSET_QUERY_VAR = 'wp_headless_asset';

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
		add_action(
			'init',
			function () {
				self::register_rules( $this->config );
			}
		);

		add_filter(
			'query_vars',
			function ( array $query_vars ) {
				$query_vars[] = self::ASSET_QUERY_VAR;
				return $query_vars;
			}
		);
	}

	/**
	 * Register the asset rewrite rule.
	 *
	 * @param Config $config Shared config.
	 * @return void
	 */
	public static function register_rules( Config $config ): void {
		$mount = trim( $config->asset_mount(), '/' );

		if ( '' === $mount ) {
			return;
		}

		add_rewrite_tag( '%' . self::ASSET_QUERY_VAR . '%', '(.+)' );
		add_rewrite_rule(
			'^' . preg_quote( $mount, '#' ) . '/(.+)$',
			'index.php?' . self::ASSET_QUERY_VAR . '=$matches[1]',
			'top'
		);
	}
}
