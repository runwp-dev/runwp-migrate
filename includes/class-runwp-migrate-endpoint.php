<?php
/**
 * The two ends of a migration: this site, or another one over HTTPS.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything a migration asks of the site it reads from or writes to. A
 * pull reads from a remote site and writes here; a push the other way round.
 * The migration itself never needs to know which end is which.
 */
interface RunWP_Migrate_Endpoint {

	/**
	 * Addresses, paths, prefix, tables, limits.
	 *
	 * @return array|WP_Error
	 */
	public function info();

	/**
	 * @param string $table Table name.
	 * @return string|WP_Error CREATE TABLE statement.
	 */
	public function export_create( $table );

	/**
	 * @param string          $table     Table name.
	 * @param string|int|null $cursor    Where the last chunk ended.
	 * @param array           $pairs     Find => replace.
	 * @param int             $max_bytes Chunk size.
	 * @return array|WP_Error
	 */
	public function export_rows( $table, $cursor, array $pairs, $max_bytes );

	/**
	 * @return true|WP_Error
	 */
	public function import_begin();

	/**
	 * @param string $table         Receiving site's table name.
	 * @param string $create        Sender's CREATE TABLE statement.
	 * @param string $source_prefix Sender's prefix.
	 * @return true|WP_Error
	 */
	public function import_create( $table, $create, $source_prefix );

	/**
	 * @param string $table   Receiving site's table name.
	 * @param array  $columns Column names.
	 * @param array  $rows    Encoded rows.
	 * @return true|WP_Error
	 */
	public function import_rows( $table, array $columns, array $rows );

	/**
	 * @param string[] $tables        Receiving site's table names.
	 * @param string   $source_prefix Sender's prefix.
	 * @param string   $label         Origin, for the backup notice.
	 * @return array|WP_Error
	 */
	public function import_finish( array $tables, $source_prefix, $label );

	/**
	 * @return array|WP_Error Relative path => [size, mtime].
	 */
	public function files_manifest();

	/**
	 * @param array $items Each {path, offset, length}.
	 * @return array|WP_Error
	 */
	public function files_read( array $items );

	/**
	 * @param array $items Each {path, offset, data, size, mtime, final}.
	 * @return true|WP_Error
	 */
	public function files_write( array $items );

	/**
	 * @return array|WP_Error
	 */
	public function restore();
}

/**
 * This site, called directly.
 */
class RunWP_Migrate_Local implements RunWP_Migrate_Endpoint {

	/**
	 * Everything the other end needs to know about this site.
	 *
	 * @return array
	 */
	public static function describe() {
		global $wpdb;

		$uploads = wp_get_upload_dir();

		return array(
			'protocol'    => RUNWP_MIGRATE_PROTOCOL,
			'version'     => RUNWP_MIGRATE_VERSION,
			'name'        => get_bloginfo( 'name' ),
			'home'        => get_option( 'home' ),
			'siteurl'     => get_option( 'siteurl' ),
			'abspath'     => wp_normalize_path( ABSPATH ),
			'content_dir' => wp_normalize_path( WP_CONTENT_DIR ),
			'uploads_dir' => wp_normalize_path( $uploads['basedir'] ),
			'prefix'      => $wpdb->prefix,
			'tables'      => RunWP_Migrate_Database::tables(),
			'multisite'   => is_multisite(),
			'allow_pull'  => RunWP_Migrate_Settings::is_allowed( 'pull' ),
			'allow_push'  => RunWP_Migrate_Settings::is_allowed( 'push' ),
			'max_body'    => (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ),
			'wp'          => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
		);
	}

	/** {@inheritDoc} */
	public function info() {
		return self::describe();
	}

	/** {@inheritDoc} */
	public function export_create( $table ) {
		return RunWP_Migrate_Database::create_statement( $table );
	}

	/** {@inheritDoc} */
	public function export_rows( $table, $cursor, array $pairs, $max_bytes ) {
		return RunWP_Migrate_Database::export_rows( $table, $cursor, new RunWP_Migrate_Replacer( $pairs ), $max_bytes );
	}

	/** {@inheritDoc} */
	public function import_begin() {
		return RunWP_Migrate_Database::begin_import();
	}

	/** {@inheritDoc} */
	public function import_create( $table, $create, $source_prefix ) {
		return RunWP_Migrate_Database::create_temp( $table, $create, $source_prefix );
	}

	/** {@inheritDoc} */
	public function import_rows( $table, array $columns, array $rows ) {
		return RunWP_Migrate_Database::insert_rows( $table, $columns, $rows );
	}

	/** {@inheritDoc} */
	public function import_finish( array $tables, $source_prefix, $label ) {
		return RunWP_Migrate_Database::finish_import( $tables, $source_prefix, $label );
	}

	/** {@inheritDoc} */
	public function files_manifest() {
		return RunWP_Migrate_Files::manifest();
	}

	/** {@inheritDoc} */
	public function files_read( array $items ) {
		return RunWP_Migrate_Files::read( $items );
	}

	/** {@inheritDoc} */
	public function files_write( array $items ) {
		return RunWP_Migrate_Files::write( $items );
	}

	/** {@inheritDoc} */
	public function restore() {
		return RunWP_Migrate_Database::restore_backup();
	}
}

/**
 * Another site, reached through its REST API with signed requests.
 */
class RunWP_Migrate_Remote implements RunWP_Migrate_Endpoint {

	/** @var string */
	private $url;

	/** @var string */
	private $key;

	/**
	 * @param string $url Site address.
	 * @param string $key That site's key.
	 */
	public function __construct( $url, $key ) {
		$this->url = untrailingslashit( $url );
		$this->key = $key;
	}

	/**
	 * Sign, send, decode.
	 *
	 * The route goes in the rest_route parameter rather than /wp-json/, which
	 * works whatever the permalink setting and whatever rewrite rules the
	 * host has.
	 *
	 * @param string $route   Route below the namespace, e.g. "db/rows".
	 * @param array  $payload Request data.
	 * @return mixed|WP_Error Decoded response.
	 */
	private function call( $route, array $payload = array() ) {
		$path = '/' . RunWP_Migrate_Rest::REST_NAMESPACE . '/' . $route;
		$body = (string) wp_json_encode( $payload );
		$host = (string) wp_parse_url( $this->url, PHP_URL_HOST );

		$response = wp_remote_post(
			add_query_arg( 'rest_route', $path, $this->url . '/' ),
			array(
				'headers'     => array( 'Content-Type' => 'application/json' ) + RunWP_Migrate_Auth::headers( $this->key, $path, $body ),
				'body'        => $body,
				'timeout'     => 120,
				// A redirect would turn the POST into a GET and lose the body.
				'redirection' => 0,
				// Development hosts tend to run self-signed certificates.
				'sslverify'   => (bool) apply_filters( 'runwp_migrate_sslverify', ! RunWP_Migrate_Settings::is_local_host( $host ), $this->url ),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: 1: site address, 2: error message. */
			return new WP_Error( 'runwp_migrate_http', sprintf( __( 'Could not reach %1$s: %2$s', 'runwp-migrate' ), $this->url, $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 300 && $status < 400 ) {
			/* translators: 1: site address, 2: redirect target. */
			return new WP_Error( 'runwp_migrate_redirect', sprintf( __( '%1$s redirects to %2$s. Connect the sites again using that address.', 'runwp-migrate' ), $this->url, wp_remote_retrieve_header( $response, 'location' ) ) );
		}

		if ( $status >= 400 ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? $data['message']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'The other site answered with HTTP %d.', 'runwp-migrate' ), $status );

			if ( 404 === $status && is_array( $data ) && isset( $data['code'] ) && 'rest_no_route' === $data['code'] ) {
				$message = __( 'RunWP Migrate is not active on the other site.', 'runwp-migrate' );
			}

			return new WP_Error( is_array( $data ) && isset( $data['code'] ) ? $data['code'] : 'runwp_migrate_http', $message );
		}

		if ( null === $data ) {
			return new WP_Error( 'runwp_migrate_response', __( 'The other site sent an answer that is not valid. Is a security plugin or firewall blocking the REST API?', 'runwp-migrate' ) );
		}

		return $data;
	}

	/** {@inheritDoc} */
	public function info() {
		return $this->call( 'info' );
	}

	/** {@inheritDoc} */
	public function export_create( $table ) {
		$data = $this->call( 'db/create-statement', array( 'table' => $table ) );
		return is_wp_error( $data ) ? $data : (string) $data['create'];
	}

	/** {@inheritDoc} */
	public function export_rows( $table, $cursor, array $pairs, $max_bytes ) {
		return $this->call(
			'db/export',
			array(
				'table'     => $table,
				'cursor'    => $cursor,
				'pairs'     => $pairs,
				'max_bytes' => $max_bytes,
			)
		);
	}

	/** {@inheritDoc} */
	public function import_begin() {
		$data = $this->call( 'db/begin' );
		return is_wp_error( $data ) ? $data : true;
	}

	/** {@inheritDoc} */
	public function import_create( $table, $create, $source_prefix ) {
		$data = $this->call(
			'db/create',
			array(
				'table'         => $table,
				'create'        => $create,
				'source_prefix' => $source_prefix,
			)
		);
		return is_wp_error( $data ) ? $data : true;
	}

	/** {@inheritDoc} */
	public function import_rows( $table, array $columns, array $rows ) {
		$data = $this->call(
			'db/import',
			array(
				'table'   => $table,
				'columns' => $columns,
				'rows'    => $rows,
			)
		);
		return is_wp_error( $data ) ? $data : true;
	}

	/** {@inheritDoc} */
	public function import_finish( array $tables, $source_prefix, $label ) {
		return $this->call(
			'db/finish',
			array(
				'tables'        => $tables,
				'source_prefix' => $source_prefix,
				'label'         => $label,
			)
		);
	}

	/** {@inheritDoc} */
	public function files_manifest() {
		$data = $this->call( 'files/manifest' );
		return is_wp_error( $data ) ? $data : (array) $data['files'];
	}

	/** {@inheritDoc} */
	public function files_read( array $items ) {
		$data = $this->call( 'files/read', array( 'items' => $items ) );
		return is_wp_error( $data ) ? $data : (array) $data['items'];
	}

	/** {@inheritDoc} */
	public function files_write( array $items ) {
		$data = $this->call( 'files/write', array( 'items' => $items ) );
		return is_wp_error( $data ) ? $data : true;
	}

	/** {@inheritDoc} */
	public function restore() {
		return $this->call( 'db/restore' );
	}
}
