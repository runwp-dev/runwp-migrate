<?php
/**
 * Request signing between two installations.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Both sites hold the same key. A request carries an HMAC over its method,
 * route, time, a single-use nonce and a hash of its body, so it cannot be
 * forged without the key, altered on the way, or replayed later.
 */
class RunWP_Migrate_Auth {

	/**
	 * How far a request timestamp may drift from our clock, in seconds. Also
	 * how long a used nonce is remembered.
	 */
	const MAX_CLOCK_SKEW = 300;

	/**
	 * The exact string both sides sign.
	 *
	 * @param string $method    HTTP verb.
	 * @param string $route     REST route, e.g. /runwp-migrate/v1/info.
	 * @param int    $timestamp Unix seconds.
	 * @param string $nonce     Random per request.
	 * @param string $body      Raw request body.
	 * @return string
	 */
	public static function canonical( $method, $route, $timestamp, $nonce, $body ) {
		return implode( "\n", array( strtoupper( $method ), $route, (string) $timestamp, $nonce, hash( 'sha256', (string) $body ) ) );
	}

	/**
	 * @param string $key     Shared key.
	 * @param string $message Canonical request.
	 * @return string Base64 signature.
	 */
	public static function sign( $key, $message ) {
		return base64_encode( hash_hmac( 'sha256', $message, $key, true ) );
	}

	/**
	 * Headers for an outgoing request.
	 *
	 * @param string $key   The receiving site's key.
	 * @param string $route REST route.
	 * @param string $body  Raw body.
	 * @return array<string, string>
	 */
	public static function headers( $key, $route, $body ) {
		$timestamp = time();
		$nonce     = wp_generate_password( 32, false, false );

		return array(
			'X-RunWP-Migrate-Timestamp' => (string) $timestamp,
			'X-RunWP-Migrate-Nonce'     => $nonce,
			'X-RunWP-Migrate-Signature' => self::sign( $key, self::canonical( 'POST', $route, $timestamp, $nonce, $body ) ),
		);
	}

	/**
	 * Check an incoming request against this site's key.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function verify( WP_REST_Request $request ) {
		$timestamp = (int) $request->get_header( 'x_runwp_migrate_timestamp' );
		$nonce     = (string) $request->get_header( 'x_runwp_migrate_nonce' );
		$signature = (string) $request->get_header( 'x_runwp_migrate_signature' );

		if ( ! $timestamp || '' === $nonce || '' === $signature ) {
			return new WP_Error( 'runwp_migrate_unsigned', __( 'The request is not signed.', 'runwp-migrate' ), array( 'status' => 401 ) );
		}

		if ( abs( time() - $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return new WP_Error( 'runwp_migrate_clock', __( 'The request is too old or from the future. Check that both servers have the right time.', 'runwp-migrate' ), array( 'status' => 401 ) );
		}

		$expected = self::sign(
			RunWP_Migrate_Settings::key(),
			self::canonical( $request->get_method(), $request->get_route(), $timestamp, $nonce, $request->get_body() )
		);

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'runwp_migrate_signature', __( 'The signature does not match. The key may have changed; connect the sites again.', 'runwp-migrate' ), array( 'status' => 401 ) );
		}

		// Only after the signature holds, so an unsigned flood cannot grow
		// the list.
		if ( ! self::remember( $nonce ) ) {
			return new WP_Error( 'runwp_migrate_replay', __( 'This request was already processed.', 'runwp-migrate' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Record a nonce as used; false if it already was.
	 *
	 * One option holding the nonces of the last few minutes, pruned on every
	 * request. A transient per nonce would leave two rows in the options
	 * table for each of the thousands of requests a large migration makes,
	 * until the daily cron got round to them.
	 *
	 * @param string $nonce Request nonce.
	 * @return bool
	 */
	private static function remember( $nonce ) {
		$now  = time();
		$hash = substr( hash( 'sha256', $nonce ), 0, 32 );
		$seen = get_option( RunWP_Migrate_Settings::OPT_NONCES, array() );
		$seen = is_array( $seen ) ? $seen : array();

		foreach ( $seen as $key => $expires ) {
			if ( $expires < $now ) {
				unset( $seen[ $key ] );
			}
		}

		if ( isset( $seen[ $hash ] ) ) {
			return false;
		}

		$seen[ $hash ] = $now + self::MAX_CLOCK_SKEW;
		update_option( RunWP_Migrate_Settings::OPT_NONCES, $seen, false );

		return true;
	}
}
