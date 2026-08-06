<?php
/**
 * Tests for FrontendBridge::resolve_is_404() — reconciling the URL resolver's
 * verdict with WordPress's own main query when the two disagree.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WPHeadless\Runtime\FrontendBridge;

class FrontendBridgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Shape a resolver result the way for_url() returns it.
	 *
	 * @param array<string, mixed> $overrides Fields that differ from the base.
	 * @return array<string, mixed>
	 */
	private function resolved( array $overrides = array() ): array {
		return array_merge(
			array(
				'kind'    => 'post',
				'is_404'  => false,
				'is_auth' => false,
			),
			$overrides
		);
	}

	public function test_resolver_404_is_honoured_regardless_of_wordpress(): void {
		$resolved = $this->resolved( array( 'kind' => 'unresolved', 'is_404' => true ) );

		$this->assertTrue( FrontendBridge::resolve_is_404( $resolved, true ) );
		$this->assertTrue( FrontendBridge::resolve_is_404( $resolved, false ) );
	}

	public function test_a_resolved_route_wordpress_serves_stays_200(): void {
		$this->assertFalse(
			FrontendBridge::resolve_is_404( $this->resolved( array( 'kind' => 'post' ) ), false )
		);
	}

	/**
	 * The bug this method exists for: the resolver matches the route shape of
	 * `/blog/page/2/` on a blog with a single page of posts and reports no 404,
	 * while WordPress's main query found nothing. WordPress wins.
	 */
	public function test_paged_archive_past_the_last_page_takes_wordpresss_404(): void {
		$resolved = $this->resolved( array( 'kind' => 'posts_page', 'page' => 2 ) );

		$this->assertTrue( FrontendBridge::resolve_is_404( $resolved, true ) );
	}

	/**
	 * Same failure via a different route: a term that exists but whose posts
	 * are all drafts resolves fine and returns nothing.
	 */
	public function test_empty_term_archive_takes_wordpresss_404(): void {
		$resolved = $this->resolved( array( 'kind' => 'term_archive' ) );

		$this->assertTrue( FrontendBridge::resolve_is_404( $resolved, true ) );
	}

	/**
	 * Auth routes are the resolver's own: WordPress 404s `/login/` by
	 * definition because no post, term or archive backs it. Deferring to
	 * WordPress here would 404 every login screen.
	 *
	 * @dataProvider authRouteKinds
	 */
	public function test_auth_routes_stay_200_even_though_wordpress_404s_them( string $kind ): void {
		$resolved = $this->resolved( array( 'kind' => $kind, 'is_auth' => true ) );

		$this->assertFalse( FrontendBridge::resolve_is_404( $resolved, true ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function authRouteKinds(): array {
		return array(
			'login'             => array( 'login' ),
			'register'          => array( 'register' ),
			'lost password'     => array( 'lost_password' ),
			'profile'           => array( 'profile' ),
			'profile passwords' => array( 'profile_passwords' ),
		);
	}

	/**
	 * An auth route the resolver itself could not place should still 404 —
	 * an explicit resolver 404 outranks the auth exemption.
	 */
	public function test_resolver_404_outranks_the_auth_exemption(): void {
		$resolved = $this->resolved( array( 'is_404' => true, 'is_auth' => true ) );

		$this->assertTrue( FrontendBridge::resolve_is_404( $resolved, false ) );
	}

	/**
	 * for_url() always returns the full base_template() shape, but callers
	 * (and filters) may hand back a partial array. Missing keys must read as
	 * "not set" rather than raising.
	 */
	public function test_missing_keys_are_treated_as_false(): void {
		$this->assertFalse( FrontendBridge::resolve_is_404( array(), false ) );
		$this->assertTrue( FrontendBridge::resolve_is_404( array(), true ) );
	}
}
