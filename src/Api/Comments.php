<?php
/**
 * Comment REST behaviour for headless frontends.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Api;

use WPHeadless\Contracts\Module;

/**
 * Aligns the REST comment-creation gate with WordPress's classic
 * wp-comments-post.php behaviour.
 *
 * Core's WP_REST_Comments_Controller rejects unauthenticated comment creation
 * unless the `rest_allow_anonymous_comments` filter returns true (it defaults
 * to false). A headless theme posts comments through the REST API, so without
 * this every logged-out visitor's comment 401s — the form is broken on a fresh
 * install even though the classic comment form would have worked.
 *
 * We mirror the classic gate: anonymous comments are allowed unless the site
 * requires registration (Settings → Discussion → "Users must be registered and
 * logged in to comment"). Site owners can override at a higher priority or via
 * the `wp_headless_allow_anonymous_comments` filter.
 */
class Comments implements Module {

	public function register(): void {
		add_filter( 'rest_allow_anonymous_comments', array( $this, 'allow_anonymous_comments' ) );
	}

	/**
	 * @param bool $allow Whether the REST API currently allows anonymous comments.
	 * @return bool
	 */
	public function allow_anonymous_comments( $allow ): bool {
		// When the site requires registration, keep core's decision (reject) so
		// login is genuinely enforced.
		if ( get_option( 'comment_registration' ) ) {
			$result = (bool) $allow;
		} else {
			$result = true;
		}

		return (bool) apply_filters( 'wp_headless_allow_anonymous_comments', $result );
	}
}
