<?php
/**
 * The REST surface another site's migration talks to, under
 * /wp-json/runwp-migrate/v1/.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Route registration. Every route requires a valid signature; reading
 * routes also require "allow pull", writing routes "allow push".
 */
class RunWP_Migrate_Rest {

	const REST_NAMESPACE = 'runwp-migrate/v1';

	/**
	 * Singleton instance.
	 *
	 * @var RunWP_Migrate_Rest|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return RunWP_Migrate_Rest
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook into the REST API.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declare every route.
	 */
	public function register_routes() {
		$local = new RunWP_Migrate_Local();

		$routes = array(
			// Answered whenever the signature holds, so the other side can
			// tell "wrong key" from "not allowed" and show the right message.
			'info'                => array( 'any', array( $local, 'info' ) ),

			// Reading this site.
			'db/create-statement' => array(
				'pull',
				static function ( WP_REST_Request $request ) use ( $local ) {
					$create = $local->export_create( (string) $request['table'] );
					return is_wp_error( $create ) ? $create : array( 'create' => $create );
				},
			),
			'db/export'           => array(
				'pull',
				static function ( WP_REST_Request $request ) use ( $local ) {
					$max = min( max( 65536, (int) $request['max_bytes'] ), 16777216 );
					return $local->export_rows( (string) $request['table'], $request['cursor'], (array) $request['pairs'], $max );
				},
			),
			'files/read'          => array(
				'pull',
				static function ( WP_REST_Request $request ) use ( $local ) {
					$items = $local->files_read( (array) $request['items'] );
					return is_wp_error( $items ) ? $items : array( 'items' => $items );
				},
			),

			// Both: a pull compares against our files, a push against theirs.
			'files/manifest'      => array(
				'either',
				static function () use ( $local ) {
					return array( 'files' => (object) $local->files_manifest() );
				},
			),

			// Writing this site.
			'db/begin'            => array(
				'push',
				static function () use ( $local ) {
					return self::ok( $local->import_begin() );
				},
			),
			'db/create'           => array(
				'push',
				static function ( WP_REST_Request $request ) use ( $local ) {
					return self::ok( $local->import_create( (string) $request['table'], (string) $request['create'], (string) $request['source_prefix'] ) );
				},
			),
			'db/import'           => array(
				'push',
				static function ( WP_REST_Request $request ) use ( $local ) {
					return self::ok( $local->import_rows( (string) $request['table'], (array) $request['columns'], (array) $request['rows'] ) );
				},
			),
			'db/finish'           => array(
				'push',
				static function ( WP_REST_Request $request ) use ( $local ) {
					return $local->import_finish( (array) $request['tables'], (string) $request['source_prefix'], (string) $request['label'] );
				},
			),
			'db/restore'          => array( 'push', array( $local, 'restore' ) ),
			'files/write'         => array(
				'push',
				static function ( WP_REST_Request $request ) use ( $local ) {
					return self::ok( $local->files_write( (array) $request['items'] ) );
				},
			),
		);

		foreach ( $routes as $route => $definition ) {
			list( $needs, $callback ) = $definition;

			register_rest_route(
				self::REST_NAMESPACE,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => static function ( WP_REST_Request $request ) use ( $callback ) {
						return rest_ensure_response( call_user_func( $callback, $request ) );
					},
					'permission_callback' => static function ( WP_REST_Request $request ) use ( $needs ) {
						return self::authorize( $request, $needs );
					},
				)
			);
		}
	}

	/**
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $needs   'any', 'pull', 'push' or 'either'.
	 * @return true|WP_Error
	 */
	private static function authorize( WP_REST_Request $request, $needs ) {
		$signed = RunWP_Migrate_Auth::verify( $request );
		if ( is_wp_error( $signed ) ) {
			return $signed;
		}

		$pull = RunWP_Migrate_Settings::is_allowed( 'pull' );
		$push = RunWP_Migrate_Settings::is_allowed( 'push' );

		if ( ( 'pull' === $needs && ! $pull ) || ( 'either' === $needs && ! $pull && ! $push ) ) {
			return new WP_Error( 'runwp_migrate_pull_off', __( 'Pulling from this site is switched off. Allow it under Tools → RunWP Migrate on that site.', 'runwp-migrate' ), array( 'status' => 403 ) );
		}

		if ( 'push' === $needs && ! $push ) {
			return new WP_Error( 'runwp_migrate_push_off', __( 'Pushing to this site is switched off. Allow it under Tools → RunWP Migrate on that site.', 'runwp-migrate' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * @param true|WP_Error $result Result of a writing call.
	 * @return array|WP_Error
	 */
	private static function ok( $result ) {
		return is_wp_error( $result ) ? $result : array( 'ok' => true );
	}
}
