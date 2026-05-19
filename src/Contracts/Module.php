<?php
/**
 * Module contract.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Contracts;

interface Module {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
