<?php
/**
 * The static publish run.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

use WPHeadless\Export\DataExport;

/**
 * One publish writes, in order: the theme build (so no document ever points
 * at a chunk storage lacks), every published singular and its data
 * artifact, the archives and crawler files, the 404 shell and the redirect
 * map. It then sweeps the copies this run did not see and records the run
 * in the public `__data/__status.json`.
 *
 * Rules a stored copy follows:
 *
 * - A 200 is stored. A body (plus content type and cache policy) whose
 *   hash matches the last write to this target is not rewritten.
 * - A timeout, 408, 429 or 5xx keeps the last good copy.
 * - A 404, 410, redirect or any other status removes the copy: the sweep
 *   deletes every key the publisher wrote before and this run did not see.
 *   A post made private, drafted, trashed or password-protected leaves the
 *   route list, so its copy goes the same way.
 * - A run in which no published post could be read sweeps nothing.
 *
 * A run takes the lock or returns null. A change that lands during a run
 * marks the site dirty again, and the run goes round once more (at most
 * MAX_ROUNDS). A run that throws records the error class in the status
 * file, keeps the site dirty, fires `wp_headless_static_publish_failed` and
 * rethrows.
 */
class Publisher {
	/** Option set when a visible change arrives; cleared by the run that publishes it. */
	public const DIRTY = 'wp_headless_static_dirty';

	/** Option: { target, hashes: key → content hash } of what the publisher wrote. */
	public const MANIFEST = 'wp_headless_static_manifest';

	/** Option: ISO time of the last successful publish. */
	public const LAST = 'wp_headless_static_published';

	/** A path no site routes, read to capture the 404 page. */
	public const NOT_FOUND_PROBE = '/__wp-headless-static-not-found__/';

	/** Fallback Cache-Control when the origin's answer is not public. */
	public const DEFAULT_CACHE = 'public, max-age=60, s-maxage=300, stale-while-revalidate=3600';

	public const MAX_ROUNDS = 3;

	public const LOCK_TTL = 900;

	/** Manifest saves during a run, so a run that dies keeps its progress. */
	private const SAVE_EVERY = 200;

	private Storage $storage;

	private Fetcher $fetcher;

	private Routes $routes;

	private RedirectMap $redirects;

	private ?DataExport $data;

	private ?AssetMirror $assets;

	/** @var array{document_cache:string, dist:string, mount:string} */
	private array $settings;

	/** @var array<string,string> Key → content hash. */
	private array $hashes = array();

	/** @var bool Whether $hashes holds the manifest (only then is it saved). */
	private bool $loaded = false;

	/** @var array<string,bool> Keys this run wrote or kept. */
	private array $seen = array();

	private int $writes = 0;

	private int $reads = 0;

	/** @var array{status:int,headers:array<string,string>,body:string}|null Last stored response. */
	private ?array $last = null;

	/** @var callable|null */
	private $log = null;

	/**
	 * @param Storage               $storage   Target.
	 * @param Fetcher               $fetcher   Origin reader.
	 * @param array<string,string>  $settings  { document_cache, dist, mount }; empty dist skips the asset mirror.
	 * @param Routes|null           $routes    Route collector.
	 * @param RedirectMap|null      $redirects Redirect map builder.
	 * @param DataExport|null       $data      Data-artifact builder; null skips artifacts.
	 * @param AssetMirror|null      $assets    Asset lister; null skips the mirror.
	 */
	public function __construct( Storage $storage, Fetcher $fetcher, array $settings = array(), ?Routes $routes = null, ?RedirectMap $redirects = null, ?DataExport $data = null, ?AssetMirror $assets = null ) {
		$this->storage   = $storage;
		$this->fetcher   = $fetcher;
		$this->routes    = $routes ?? new Routes();
		$this->redirects = $redirects ?? new RedirectMap();
		$this->data      = $data;
		$this->assets    = $assets;
		$this->settings  = array(
			'document_cache' => '' !== (string) ( $settings['document_cache'] ?? '' ) ? (string) $settings['document_cache'] : self::DEFAULT_CACHE,
			'dist'           => (string) ( $settings['dist'] ?? '' ),
			'mount'          => trim( (string) ( $settings['mount'] ?? '_wp-headless' ), '/' ),
		);
	}

	/**
	 * Publish the site.
	 *
	 * @param array{force?:bool, log?:callable} $args `force` rewrites every object
	 *                                                 regardless of its hash.
	 * @return array<string,mixed>|null Counts, or null when another run holds
	 *                                  the lock (this change is then queued).
	 * @throws \Throwable What the run threw, after recording it.
	 */
	public function run( array $args = array() ): ?array {
		if ( ! Lock::acquire( self::LOCK_TTL ) ) {
			update_option( self::DIRTY, time(), false );
			return null;
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::LOCK_TTL ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$this->log = isset( $args['log'] ) && is_callable( $args['log'] ) ? $args['log'] : null;
		$force     = ! empty( $args['force'] );
		$started   = time();
		$stats     = array();
		try {
			$rounds = 0;
			do {
				delete_option( self::DIRTY );
				$started = time();
				$this->write_status(
					array(
						'running' => true,
						'started' => gmdate( 'c', $started ),
					)
				);
				$stats     = self::add_up( $stats, $this->publish_once( $force && 0 === $rounds ) );
				$published = gmdate( 'c' );
				update_option( self::LAST, $published, false );
				$this->write_status(
					array(
						'started'       => gmdate( 'c', $started ),
						'published'     => $published,
						'nextScheduled' => $this->next_scheduled(),
					)
				);
				$rounds++;
			} while ( $rounds < self::MAX_ROUNDS && self::dirty_in_database() );
			$stats['rounds'] = $rounds;
		} catch ( \Throwable $e ) {
			$this->record_failure( $started, $e );
			throw $e;
		} finally {
			Lock::release();
		}

		/**
		 * Fires after a successful publish. Hosts purge their CDN here
		 * (one wildcard invalidation is usually cheapest).
		 *
		 * @param array<string,mixed> $stats   Counts per kind of object.
		 * @param Storage             $storage The target written to.
		 */
		do_action( 'wp_headless_static_published', $stats, $this->storage );
		return $stats;
	}

	/**
	 * One pass over everything.
	 *
	 * @param bool $force Rewrite every object regardless of its hash.
	 * @return array<string,mixed>
	 */
	private function publish_once( bool $force ): array {
		$owned        = $this->load_manifest();
		$this->hashes = $force ? array_fill_keys( array_keys( $owned ), '' ) : $owned;
		$this->loaded = true;
		$this->seen   = array( Keys::STATUS => true );
		$this->writes = 0;
		$stats        = array(
			'assets'    => array(),
			'documents' => array(),
			'data'      => array(),
			'site'      => array(),
		);

		foreach ( $this->asset_files() as $asset ) {
			$body = is_readable( $asset['file'] ) ? file_get_contents( $asset['file'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->tally( $stats['assets'], false === $body ? 'failed' : $this->put_if_changed( $asset['key'], $body, $asset['type'], $asset['cache'] ) );
		}

		$routes = $this->routes->singulars();
		$read   = 0;
		foreach ( $routes as $route ) {
			$page    = '/' === $route['path'] ? '/' : $route['path'] . '/';
			$outcome = $this->publish_document( $page );
			$read   += in_array( $outcome, array( 'stored', 'unchanged' ), true ) ? 1 : 0;
			$this->note( $stats['documents'], $outcome, $page );
			if ( null !== $this->data ) {
				$this->tally( $stats['data'], $this->publish_artifact( $route ) );
			}
		}

		/**
		 * Filters the non-singular paths the static publisher reads and
		 * stores: archives with their pagination, crawler files, and any
		 * route only a plugin knows about.
		 *
		 * @param array<int,string>                                   $paths  Absolute paths.
		 * @param array<int,array{path:string,id:int,type:string}>    $routes The singular routes.
		 */
		$paths = (array) apply_filters( 'wp_headless_static_paths', array_merge( $this->routes->archives(), $this->routes->files() ), $routes );
		foreach ( array_unique( array_filter( $paths, 'is_string' ) ) as $path ) {
			if ( '' === $path || '/' !== $path[0] ) {
				continue;
			}
			$this->note( $stats['documents'], $this->publish_document( $path ), $path );
			if ( '/wp-sitemap.xml' === $path && null !== $this->last ) {
				foreach ( Routes::sitemap_children( $this->last['body'], home_url( '/' ) ) as $child ) {
					$this->note( $stats['documents'], $this->publish_document( $child ), $child );
				}
			}
		}

		$this->note( $stats['site'], $this->publish_document( self::NOT_FOUND_PROBE, Keys::NOT_FOUND, array( 404 ) ), 'the 404 page' );
		$this->note(
			$stats['site'],
			$this->put_if_changed( Keys::REDIRECTS, (string) wp_json_encode( (object) $this->redirects->build(), JSON_UNESCAPED_SLASHES ), 'application/json; charset=utf-8', $this->settings['document_cache'] ),
			'the redirect map'
		);

		// A run that could read no published post (an empty route list from
		// a database hiccup, an origin that is down) never sweeps on its word.
		$stats['swept'] = 0;
		if ( $read > 0 ) {
			$stats['swept'] = $this->sweep( $owned );
		} else {
			$this->say( 'no published post could be read; nothing swept' );
		}
		$this->save_manifest();
		$stats['routes'] = count( $routes );
		return $stats;
	}

	/**
	 * Read one path and store it.
	 *
	 * @param string             $path   Path with optional query.
	 * @param string|null        $key    Storage key; default from the path.
	 * @param array<int,int>     $accept Statuses worth storing.
	 * @return string stored | unchanged | failed | skipped
	 */
	private function publish_document( string $path, ?string $key = null, array $accept = array( 200 ) ): string {
		$key        = $key ?? Keys::document( $path );
		$this->last = null;
		if ( 0 === ++$this->reads % 100 ) {
			Lock::extend( self::LOCK_TTL ); // A long run keeps its lock.
		}
		$res = $this->fetcher->get( $path );
		if ( null === $res || $res['status'] >= 500 || in_array( $res['status'], array( 408, 429 ), true ) ) {
			// A failed read keeps the last good copy.
			$this->seen[ $key ] = true;
			return 'failed';
		}
		if ( ! in_array( $res['status'], $accept, true ) ) {
			return 'skipped';
		}
		$this->last = $res;
		$type       = (string) ( $res['headers']['content-type'] ?? 'text/html; charset=UTF-8' );
		$cache      = (string) ( $res['headers']['cache-control'] ?? '' );
		if ( '' === $cache || preg_match( '/\b(private|no-store|no-cache)\b/i', $cache ) ) {
			$cache = $this->settings['document_cache'];
		}
		return $this->put_if_changed( $key, $res['body'], $type, $cache );
	}

	/**
	 * Store a route's data-export artifact. Its `generated` time changes on
	 * every build, so it is left out of the hash.
	 *
	 * @param array{path:string,id:int,type:string} $route Route.
	 */
	private function publish_artifact( array $route ): string {
		$key = Keys::route_data( $route['path'] );
		try {
			$post     = get_post( $route['id'] );
			$artifact = $post ? $this->data->build( $post ) : null;
		} catch ( \Throwable $e ) {
			error_log( '[wp-headless] static data artifact failed: ' . get_class( $e ) . ': ' . substr( $e->getMessage(), 0, 300 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$this->seen[ $key ] = true;
			return 'failed';
		}
		if ( null === $artifact ) {
			return 'skipped';
		}
		$basis = json_decode( $artifact['json'], true );
		if ( is_array( $basis ) ) {
			unset( $basis['generated'] );
		}
		return $this->put_if_changed(
			Keys::route_data( $artifact['path'] ),
			$artifact['json'],
			'application/json; charset=utf-8',
			$this->settings['document_cache'],
			is_array( $basis ) ? (string) wp_json_encode( $basis ) : null
		);
	}

	/**
	 * Write an object unless the target already holds the same bytes.
	 *
	 * @param string      $key   Key.
	 * @param string      $body  Bytes.
	 * @param string      $type  Content-Type.
	 * @param string      $cache Cache-Control.
	 * @param string|null $basis What to hash instead of the body.
	 * @return string stored | unchanged | failed
	 */
	private function put_if_changed( string $key, string $body, string $type, string $cache, ?string $basis = null ): string {
		$hash               = substr( hash( 'sha256', $type . "\n" . $cache . "\n" . ( $basis ?? $body ) ), 0, 32 );
		$this->seen[ $key ] = true;
		if ( isset( $this->hashes[ $key ] ) && hash_equals( $this->hashes[ $key ], $hash ) ) {
			return 'unchanged';
		}
		try {
			$this->storage->put( $key, $body, $type, $cache );
		} catch ( StorageError $e ) {
			error_log( '[wp-headless] static put failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return 'failed';
		}
		$this->hashes[ $key ] = $hash;
		if ( 0 === ++$this->writes % self::SAVE_EVERY ) {
			$this->save_manifest();
		}
		return 'stored';
	}

	/**
	 * Delete every key a previous run wrote that this run did not see.
	 * Reserved files and mirrored assets are never swept.
	 *
	 * @param array<string,string> $owned Manifest at the start of the run.
	 * @return int Objects deleted.
	 */
	private function sweep( array $owned ): int {
		$deleted = 0;
		foreach ( array_keys( $owned ) as $key ) {
			$key = (string) $key;
			if ( isset( $this->seen[ $key ] ) || 0 === strpos( $key, Keys::RESERVED_PREFIX ) || 0 === strpos( $key, $this->settings['mount'] . '/' ) ) {
				continue;
			}
			try {
				$this->storage->delete( $key );
			} catch ( StorageError $e ) {
				error_log( '[wp-headless] static delete failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				continue;
			}
			unset( $this->hashes[ $key ] );
			$deleted++;
			$this->say( 'removed /' . $key );
		}
		return $deleted;
	}

	/**
	 * Files of the theme build to mirror.
	 *
	 * @return array<int, array{key:string, file:string, type:string, cache:string}>
	 */
	private function asset_files(): array {
		if ( null === $this->assets || '' === $this->settings['dist'] ) {
			return array();
		}
		return $this->assets->files( $this->settings['dist'], $this->settings['mount'] );
	}

	/**
	 * Record a run that threw: the site stays dirty so the next run publishes
	 * the change that started this one, and the public status file names the
	 * error class only. The message goes to the PHP error log.
	 *
	 * @param int        $started Run start (Unix).
	 * @param \Throwable $error   What it threw.
	 */
	private function record_failure( int $started, \Throwable $error ): void {
		update_option( self::DIRTY, time(), false );
		error_log( '[wp-headless] static publish failed: ' . get_class( $error ) . ': ' . substr( $error->getMessage(), 0, 300 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		if ( $this->loaded ) {
			$this->save_manifest();
		}
		try {
			$this->write_status(
				array(
					'started'  => gmdate( 'c', $started ),
					'failedAt' => gmdate( 'c' ),
					'error'    => self::error_name( $error ),
				)
			);
		} catch ( \Throwable $ignored ) {
			unset( $ignored ); // The target itself is what failed; the log has it.
		}

		/**
		 * Fires when a static publish throws, after it is recorded. The site
		 * stays dirty; the next change or `wp headless static publish`
		 * retries. Hosts alert here.
		 *
		 * @param \Throwable $error What the run threw.
		 */
		do_action( 'wp_headless_static_publish_failed', $error );
	}

	/**
	 * Write `__data/__status.json`. Public: it carries times, a running flag
	 * and an error class, never messages, paths or counts.
	 *
	 * @param array<string,mixed> $fields Fields over the defaults.
	 */
	private function write_status( array $fields ): void {
		$last   = get_option( self::LAST, '' );
		$status = array_merge(
			array(
				'v'         => 1,
				'running'   => false,
				'started'   => null,
				'published' => is_string( $last ) && '' !== $last ? $last : null,
			),
			$fields
		);
		$this->storage->put( Keys::STATUS, (string) wp_json_encode( $status, JSON_UNESCAPED_SLASHES ), 'application/json; charset=utf-8', 'no-cache, max-age=0' );
	}

	/**
	 * Counts of every round so far: outcome counts and `swept` add up,
	 * `routes` is the latest round's.
	 *
	 * @param array<string,mixed> $total Counts so far.
	 * @param array<string,mixed> $round This round's counts.
	 * @return array<string,mixed>
	 */
	private static function add_up( array $total, array $round ): array {
		foreach ( $round as $name => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $outcome => $count ) {
					$total[ $name ][ $outcome ] = ( $total[ $name ][ $outcome ] ?? 0 ) + (int) $count;
				}
				$total[ $name ] = $total[ $name ] ?? array();
			} elseif ( 'swept' === $name ) {
				$total['swept'] = ( $total['swept'] ?? 0 ) + (int) $value;
			} else {
				$total[ $name ] = $value;
			}
		}
		return $total;
	}

	/**
	 * Whether another request marked the site dirty during this run. Read
	 * from the database: the in-process options cache still holds this
	 * run's own delete.
	 */
	private static function dirty_in_database(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s LIMIT 1", self::DIRTY ) );
	}

	/**
	 * The short class name of an error. Anonymous classes carry their
	 * defining file path in their name, so they are reported as `anonymous`.
	 *
	 * @param \Throwable $error Error.
	 */
	public static function error_name( \Throwable $error ): string {
		$class = get_class( $error );
		if ( false !== strpos( $class, '@anonymous' ) ) {
			return 'anonymous';
		}
		$pos = strrpos( $class, '\\' );
		return false === $pos ? $class : substr( $class, $pos + 1 );
	}

	/**
	 * When the next scheduled post goes live (ISO, UTC), or null. A host
	 * that puts WordPress to sleep must wake it then: core publishes
	 * scheduled posts from cron, which only runs while WordPress is up.
	 */
	private function next_scheduled(): ?string {
		$next = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'future',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		if ( empty( $next ) ) {
			return null;
		}
		$gmt = (string) get_post_field( 'post_date_gmt', (int) $next[0] );
		if ( '' === $gmt || 0 === strpos( $gmt, '0000' ) ) {
			return null;
		}
		return gmdate( 'c', (int) strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * Manifest hashes for this target, or none when it was kept for another.
	 *
	 * @return array<string,string>
	 */
	private function load_manifest(): array {
		$stored = get_option( self::MANIFEST, array() );
		if ( ! is_array( $stored ) || ( $stored['target'] ?? '' ) !== $this->storage->id() || ! is_array( $stored['hashes'] ?? null ) ) {
			return array();
		}
		return array_map( 'strval', $stored['hashes'] );
	}

	private function save_manifest(): void {
		update_option(
			self::MANIFEST,
			array(
				'target' => $this->storage->id(),
				'hashes' => $this->hashes,
			),
			false
		);
	}

	/**
	 * Count an outcome and log the ones worth a line.
	 *
	 * @param array<string,int> $bucket  Counts.
	 * @param string            $outcome Outcome.
	 * @param string            $what    Path or name, for the log only.
	 */
	private function note( array &$bucket, string $outcome, string $what ): void {
		$this->tally( $bucket, $outcome );
		if ( 'stored' !== $outcome && 'unchanged' !== $outcome ) {
			$this->say( $outcome . ' ' . $what );
		}
	}

	/**
	 * @param array<string,int> $bucket  Counts.
	 * @param string            $outcome Outcome.
	 */
	private function tally( array &$bucket, string $outcome ): void {
		$bucket[ $outcome ] = ( $bucket[ $outcome ] ?? 0 ) + 1;
	}

	private function say( string $line ): void {
		if ( null !== $this->log ) {
			( $this->log )( $line );
		}
	}
}
