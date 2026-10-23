<?php
/**
 * Storage keys of the static publisher. Pure functions, no WordPress state.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * How a URL path becomes a storage key. An edge in front of the bucket
 * must apply the same mapping, so these rules are public API:
 *
 * - A document path without a file extension is a directory with an
 *   `index.html`: `/` → `index.html`, `/about-us/` → `about-us/index.html`.
 * - A path with a file extension is its own key: `/robots.txt` → `robots.txt`.
 * - A route's data artifact (the data-export envelope the SPA reads on
 *   client-side navigation) lives under `__data/routes/`, mirroring the
 *   document: `/` → `__data/routes/index.json`,
 *   `/about-us` → `__data/routes/about-us/index.json`.
 * - Files the publisher manages itself sit under `__data/__`.
 */
final class Keys {
	/** Run status: running, started, published, error class. Public. */
	public const STATUS = '__data/__status.json';

	/** Path → absolute URL of every redirect WordPress answers. */
	public const REDIRECTS = '__data/__redirects.json';

	/** The site's 404 page, for an edge to answer unknown paths with. */
	public const NOT_FOUND = '__data/__shell/404.html';

	/** The page an edge answers with while WordPress sleeps; it wakes it for an editor. */
	public const WAKE = '__data/__shell/503.html';

	/** When an editor was last seen: `{"lastEditor": ISO}`. Public. */
	public const ACTIVITY = '__data/__activity.json';

	/** Prefix of every key the publisher manages itself (never swept). */
	public const RESERVED_PREFIX = '__data/__';

	/**
	 * Key of a document path (query strings are ignored).
	 *
	 * @param string $path URL path, e.g. `/about-us/` or `/robots.txt`.
	 */
	public static function document( string $path ): string {
		$path = '/' . ltrim( (string) strtok( $path, '?' ), '/' );
		if ( preg_match( '#\.[A-Za-z0-9]{2,5}$#', $path ) ) {
			return ltrim( $path, '/' );
		}
		return ltrim( rtrim( $path, '/' ) . '/index.html', '/' );
	}

	/**
	 * Key of a route's data artifact.
	 *
	 * @param string $path Route path key from DataExport (`/` or `/about-us`).
	 */
	public static function route_data( string $path ): string {
		$path = trim( $path, '/' );
		return '__data/routes/' . ( '' === $path ? '' : $path . '/' ) . 'index.json';
	}

	/**
	 * Whether a key is safe to hand to a storage target: relative, no
	 * empty or dot segments, no backslashes or control characters.
	 *
	 * @param string $key Candidate key.
	 */
	public static function is_safe( string $key ): bool {
		if ( '' === $key || '/' === $key[0] || false !== strpos( $key, '\\' ) || preg_match( '/[\x00-\x1f]/', $key ) ) {
			return false;
		}
		foreach ( explode( '/', $key ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}
}
