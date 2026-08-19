<?php
/**
 * Static route-data export.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Export;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Runtime\RequestDataBuilder;

/**
 * Materializes a path-keyed JSON document per route at publish time — the
 * data half of the "documents ship from the edge" model. A visitor
 * navigating client-side needs the route's content; serving it as a static
 * artifact (S3/CDN/filesystem — wherever the host puts it) costs zero
 * origin compute, at any scale. WordPress is only touched by writes and
 * by fallback reads when an artifact is missing.
 *
 * The module deliberately does NOT serve or store anything itself: it
 * builds the artifact and hands it to hosts through two actions —
 * `wp_headless_data_exported` and `wp_headless_data_deleted`. Content is
 * produced by an INTERNAL REST dispatch (`rest_do_request`) against the
 * same `wp/v2` collection query a frontend makes (`?slug=`), so the
 * exported JSON is shape-identical to the live API — registered rest
 * fields, enrichments and filters included. A theme that consumes
 * `/wp/v2/{base}?slug=x` today can consume the artifact's `content` key
 * without a parser change.
 *
 * Exports run on publish transitions (editor time), never on the visitor
 * path. `wp headless export` backfills a whole site.
 */
class DataExport implements Module {
	/**
	 * Envelope format version — bump on breaking shape changes.
	 */
	const FORMAT_VERSION = 1;

	/** @var Config */
	private Config $config;

	/** @var RequestDataBuilder */
	private RequestDataBuilder $request_data;

	/**
	 * @param Config                  $config       Plugin configuration.
	 * @param RequestDataBuilder|null $request_data Optional resolver (tests).
	 */
	public function __construct( Config $config, ?RequestDataBuilder $request_data = null ) {
		$this->config       = $config;
		$this->request_data = $request_data ?? new RequestDataBuilder();
	}

	/**
	 * Hook registrations only.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'on_deleted' ), 10, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'headless export', array( $this, 'cli' ) );
		}
	}

	/**
	 * Publish transitions drive the artifact lifecycle: reaching `publish`
	 * (re-)exports, leaving it retracts.
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 * @param mixed  $post       WP_Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			$this->export_post( $post );
			return;
		}
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->retract_post( $post );
		}
	}

	/**
	 * Permanent deletion retracts the artifact.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    WP_Post (WP >= 5.5 passes it).
	 */
	public function on_deleted( $post_id, $post = null ): void {
		unset( $post_id );
		if ( is_object( $post ) && isset( $post->post_status ) && 'publish' === $post->post_status ) {
			$this->retract_post( $post );
		}
	}

	/**
	 * Build and announce a post's artifact.
	 *
	 * @param object $post WP_Post-shaped object.
	 */
	public function export_post( $post ): void {
		if ( ! $this->exportable_type( (string) $post->post_type ) ) {
			return;
		}
		$permalink = get_permalink( $post->ID );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return;
		}
		$path = $this->export_path_for( $permalink );
		if ( null === $path ) {
			return;
		}
		$content = $this->rest_content( (string) $post->post_type, (string) $post->post_name );
		if ( null === $content ) {
			return; // REST refused (type not exposed, filtered out) — no artifact.
		}
		$envelope = $this->envelope( $path, $permalink, $content );
		$json     = wp_json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return;
		}

		/**
		 * Fires when a route's data artifact has been built.
		 *
		 * Hosts persist `$json` wherever their edge serves it from (S3,
		 * filesystem, KV). `$path` is the canonical storage key: '/' for
		 * the front page, '/about-us' style (no trailing slash) otherwise.
		 *
		 * @param string               $path    Route path key.
		 * @param string               $json    The encoded envelope.
		 * @param array<string,mixed>  $context { post_id, post_type }.
		 */
		do_action(
			'wp_headless_data_exported',
			$path,
			$json,
			array(
				'post_id'   => (int) $post->ID,
				'post_type' => (string) $post->post_type,
			)
		);
	}

	/**
	 * Announce that a route's artifact is gone.
	 *
	 * @param object $post WP_Post-shaped object.
	 */
	public function retract_post( $post ): void {
		if ( ! $this->exportable_type( (string) $post->post_type ) ) {
			return;
		}
		$permalink = get_permalink( $post->ID );
		$path      = is_string( $permalink ) && '' !== $permalink ? $this->export_path_for( $permalink ) : null;
		if ( null === $path ) {
			return;
		}

		/**
		 * Fires when a route's data artifact must be removed.
		 *
		 * @param string              $path    Route path key.
		 * @param array<string,mixed> $context { post_id, post_type }.
		 */
		do_action(
			'wp_headless_data_deleted',
			$path,
			array(
				'post_id'   => (int) $post->ID,
				'post_type' => (string) $post->post_type,
			)
		);
	}

	/**
	 * `wp headless export [--post=<id>]` — backfill artifacts.
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function cli( array $args, array $assoc_args ): void {
		unset( $args );
		if ( isset( $assoc_args['post'] ) ) {
			$post = get_post( (int) $assoc_args['post'] );
			if ( $post && 'publish' === $post->post_status ) {
				$this->export_post( $post );
				\WP_CLI::success( 'Exported post ' . $post->ID . '.' );
			} else {
				\WP_CLI::error( 'Post not found or not published.' );
			}
			return;
		}
		$count = 0;
		foreach ( $this->exportable_types() as $type ) {
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'all',
				)
			);
			foreach ( $posts as $post ) {
				$this->export_post( $post );
				$count++;
			}
		}
		\WP_CLI::success( 'Exported ' . $count . ' route artifact(s).' );
	}

	/**
	 * The canonical storage key for a permalink: '/' for the front page,
	 * otherwise the path without its trailing slash. Cross-origin or
	 * pathless permalinks yield null (never exported).
	 *
	 * @param string $permalink Absolute permalink.
	 */
	public function export_path_for( string $permalink ): ?string {
		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] ) {
			return null;
		}
		if ( '/' === $path ) {
			return '/';
		}
		return untrailingslashit( $path );
	}

	/**
	 * Whether a post type participates in exports: it must be REST-exposed,
	 * and pass the `modules.data_export.post_types` allowlist when set.
	 *
	 * @param string $post_type Post type name.
	 */
	protected function exportable_type( string $post_type ): bool {
		if ( false === $this->config->get( 'modules.data_export.enabled', true ) ) {
			return false;
		}
		$allowlist = $this->config->get( 'modules.data_export.post_types', array() );
		if ( is_array( $allowlist ) && ! empty( $allowlist ) && ! in_array( $post_type, $allowlist, true ) ) {
			return false;
		}
		$object = get_post_type_object( $post_type );
		return is_object( $object ) && ! empty( $object->show_in_rest );
	}

	/**
	 * All exportable post type names (CLI backfill).
	 *
	 * @return array<int,string>
	 */
	protected function exportable_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		return array_values( array_filter( array_map( 'strval', (array) $types ), array( $this, 'exportable_type' ) ) );
	}

	/**
	 * The route's content via an INTERNAL REST dispatch — the same
	 * collection query a frontend makes, so the artifact is shape-identical
	 * to the live API (registered rest fields and filters included).
	 *
	 * @param string $post_type Post type name.
	 * @param string $slug      Post slug.
	 * @return array<int|string,mixed>|null
	 */
	protected function rest_content( string $post_type, string $slug ): ?array {
		$object    = get_post_type_object( $post_type );
		$rest_base = is_object( $object ) && ! empty( $object->rest_base ) ? (string) $object->rest_base : $post_type;

		$request = new \WP_REST_Request( 'GET', '/wp/v2/' . $rest_base );
		$request->set_query_params( array( 'slug' => $slug ) );
		$response = rest_do_request( $request );
		if ( ! is_object( $response ) || ( method_exists( $response, 'is_error' ) && $response->is_error() ) ) {
			return null;
		}
		$server = rest_get_server();
		$data   = $server->response_to_data( $response, false );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Assemble the artifact envelope. `request` mirrors the runtime's
	 * resolved request shape so the consumer can dispatch without its own
	 * resolver round-trip; `content` is the REST collection response.
	 *
	 * @param string                  $permalink Absolute permalink.
	 * @param string                  $path      Route path key.
	 * @param array<int|string,mixed> $content   REST-shaped content.
	 * @return array<string,mixed>
	 */
	protected function envelope( string $path, string $permalink, array $content ): array {
		return array(
			'version'   => self::FORMAT_VERSION,
			'generated' => gmdate( 'c' ),
			'path'      => $path,
			'request'   => $this->request_data->for_url( $permalink ),
			'content'   => $content,
		);
	}
}
