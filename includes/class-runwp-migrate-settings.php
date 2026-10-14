<?php
/**
 * Everything the plugin stores: this site's key, what it allows, and the
 * sites it can reach.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Options, keys and the connection list.
 */
class RunWP_Migrate_Settings {

	/** Shared secret other sites sign their requests with. */
	const OPT_KEY = 'runwp_migrate_key';

	/** Whether other sites may read this site's database and files. */
	const OPT_ALLOW_PULL = 'runwp_migrate_allow_pull';

	/** Whether other sites may overwrite this site's database and files. */
	const OPT_ALLOW_PUSH = 'runwp_migrate_allow_push';

	/** Sites this one can pull from and push to: name => {url, key, added}. */
	const OPT_CONNECTIONS = 'runwp_migrate_connections';

	/** What the last migration into this site set aside, for restoring it. */
	const OPT_BACKUP = 'runwp_migrate_backup';

	/** State of the migration currently running from this site. */
	const OPT_RUN = 'runwp_migrate_run';

	/** Recently used request nonces: hash => expiry. */
	const OPT_NONCES = 'runwp_migrate_nonces';

	/**
	 * Options that describe this installation rather than its content. They
	 * survive every migration into the site. None of the plugin's options
	 * are ever sent out of it.
	 *
	 * The running migration's state is deliberately not among them: a pull
	 * ends by swapping this site's tables, and the state of a finished run
	 * should go with the old ones.
	 *
	 * @return string[]
	 */
	public static function own_options() {
		return array( self::OPT_KEY, self::OPT_ALLOW_PULL, self::OPT_ALLOW_PUSH, self::OPT_CONNECTIONS, self::OPT_NONCES );
	}

	/**
	 * This site's key, created on first use.
	 *
	 * @return string
	 */
	public static function key() {
		$key = (string) get_option( self::OPT_KEY, '' );

		if ( '' === $key ) {
			$key = self::generate_key();
			update_option( self::OPT_KEY, $key, false );
		}

		return $key;
	}

	/**
	 * Replace the key. Every site that held the old one loses access.
	 *
	 * @return string The new key.
	 */
	public static function regenerate_key() {
		$key = self::generate_key();
		update_option( self::OPT_KEY, $key, false );

		return $key;
	}

	/**
	 * 256 random bits, URL-safe so the key survives being pasted anywhere.
	 *
	 * @return string
	 */
	public static function generate_key() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $direction 'pull' or 'push'.
	 * @return bool
	 */
	public static function is_allowed( $direction ) {
		return (bool) get_option( 'push' === $direction ? self::OPT_ALLOW_PUSH : self::OPT_ALLOW_PULL, false );
	}

	/**
	 * @param string $direction 'pull' or 'push'.
	 * @param bool   $allowed   On or off.
	 */
	public static function set_allowed( $direction, $allowed ) {
		update_option( 'push' === $direction ? self::OPT_ALLOW_PUSH : self::OPT_ALLOW_PULL, $allowed ? 1 : 0, false );
	}

	/**
	 * What another site needs to connect to this one: the address on the
	 * first line, the key on the second.
	 *
	 * @return string
	 */
	public static function connection_info() {
		return untrailingslashit( home_url() ) . "\n" . self::key();
	}

	/**
	 * Read connection info pasted from another site. Tolerates the two parts
	 * on one line or two, and stray whitespace around them.
	 *
	 * @param string $text Pasted text.
	 * @return array{url:string, key:string}|WP_Error
	 */
	public static function parse_connection_info( $text ) {
		$parts = preg_split( '/\s+/', trim( (string) $text ) );

		if ( count( $parts ) !== 2 ) {
			return new WP_Error( 'runwp_migrate_bad_info', __( 'Paste the connection info exactly as the other site shows it: its address and its key.', 'runwp-migrate' ) );
		}

		return self::validate_connection( $parts[0], $parts[1] );
	}

	/**
	 * @param string $url Site address.
	 * @param string $key That site's key.
	 * @return array{url:string, key:string}|WP_Error
	 */
	public static function validate_connection( $url, $key ) {
		$url  = untrailingslashit( esc_url_raw( trim( (string) $url ) ) );
		$key  = trim( (string) $key );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return new WP_Error( 'runwp_migrate_bad_url', __( 'That is not a valid site address.', 'runwp-migrate' ) );
		}

		// The key travels in no request, but the database does. Over plain
		// HTTP anyone on the way could read it, so only local development
		// hosts may go without TLS.
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) && ! self::is_local_host( $host ) ) {
			return new WP_Error( 'runwp_migrate_insecure', __( 'The other site must be reached over HTTPS.', 'runwp-migrate' ) );
		}

		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $key ) ) {
			return new WP_Error( 'runwp_migrate_bad_key', __( 'That key is not valid. Copy it again from the other site.', 'runwp-migrate' ) );
		}

		return array(
			'url' => $url,
			'key' => $key,
		);
	}

	/**
	 * Development hosts: names without a dot, the usual local TLDs and the
	 * private address ranges.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_local_host( $host ) {
		$host = strtolower( $host );

		if ( false === strpos( $host, '.' ) || preg_match( '/\.(test|local|localhost|ddev\.site|lndo\.site|internal)$/', $host ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		return false;
	}

	/**
	 * @return array<string, array{url:string, key:string, added:int}>
	 */
	public static function connections() {
		$connections = get_option( self::OPT_CONNECTIONS, array() );
		return is_array( $connections ) ? $connections : array();
	}

	/**
	 * @param string $name Connection name.
	 * @return array{url:string, key:string, added:int}|null
	 */
	public static function connection( $name ) {
		$connections = self::connections();
		return isset( $connections[ $name ] ) ? $connections[ $name ] : null;
	}

	/**
	 * Names are typed on the command line ("wp runwp-migrate pull live"), so
	 * they are kept to lower-case letters, digits and dashes.
	 *
	 * @param string $name Raw name.
	 * @return string '' when nothing usable is left.
	 */
	public static function sanitize_name( $name ) {
		return trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( remove_accents( (string) $name ) ) ), '-' );
	}

	/**
	 * @param string $name Connection name.
	 * @param string $url  Site address.
	 * @param string $key  That site's key.
	 */
	public static function add_connection( $name, $url, $key ) {
		$connections          = self::connections();
		$connections[ $name ] = array(
			'url'   => $url,
			'key'   => $key,
			'added' => time(),
		);
		update_option( self::OPT_CONNECTIONS, $connections, false );
	}

	/**
	 * @param string $name Connection name.
	 */
	public static function remove_connection( $name ) {
		$connections = self::connections();
		unset( $connections[ $name ] );
		update_option( self::OPT_CONNECTIONS, $connections, false );
	}
}
