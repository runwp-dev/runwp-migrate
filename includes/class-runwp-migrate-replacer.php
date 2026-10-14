<?php
/**
 * URL and path replacement that keeps serialized data valid.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites one site's addresses and paths into another's.
 *
 * Plain text gets a single strtr() pass over all pairs at once: longer finds
 * win, and text already replaced is never searched again, so a target that
 * contains the source ("haza.shop" → "haza.shop.ddev.site") cannot be
 * replaced twice. Serialized values are unserialized, walked and serialized
 * again, so their string lengths stay right — a raw replace would corrupt
 * them.
 *
 * Replacement runs on the sending side, against that site's own data, so no
 * site ever unserializes values it did not store itself.
 */
class RunWP_Migrate_Replacer {

	/**
	 * Find => replace.
	 *
	 * @var array<string, string>
	 */
	private $pairs;

	/**
	 * @param array<string, string> $pairs Find => replace.
	 */
	public function __construct( array $pairs ) {
		$this->pairs = array();

		foreach ( $pairs as $find => $replace ) {
			if ( ! is_scalar( $replace ) ) {
				continue;
			}
			if ( '' !== (string) $find && (string) $find !== (string) $replace ) {
				$this->pairs[ (string) $find ] = (string) $replace;
			}
		}
	}

	/**
	 * The pairs that move one site's data to another: both addresses with and
	 * without scheme, their JSON-escaped and URL-encoded forms, and the
	 * file system paths.
	 *
	 * @param array $from Info of the sending site (home, siteurl, abspath, content_dir, uploads_dir).
	 * @param array $to   Info of the receiving site.
	 * @return array<string, string>
	 */
	public static function pairs_for( array $from, array $to ) {
		$pairs = array();

		foreach ( array( 'home', 'siteurl' ) as $key ) {
			self::add_url( $pairs, $from[ $key ], $to[ $key ] );
		}

		foreach ( array( 'abspath', 'content_dir', 'uploads_dir' ) as $key ) {
			$a = untrailingslashit( (string) $from[ $key ] );
			$b = untrailingslashit( (string) $to[ $key ] );
			if ( '' !== $a && $a !== $b ) {
				$pairs[ $a ] = $b;
			}
		}

		return $pairs;
	}

	/**
	 * @param array  $pairs Pairs, extended in place.
	 * @param string $from  Sending site's address.
	 * @param string $to    Receiving site's address.
	 */
	private static function add_url( array &$pairs, $from, $to ) {
		$from = untrailingslashit( (string) $from );
		$to   = untrailingslashit( (string) $to );

		if ( '' === $from || $from === $to ) {
			return;
		}

		// "//haza.shop" also catches http:// links and protocol-relative ones.
		$bare_from = preg_replace( '#^https?:#i', '', $from );
		$bare_to   = preg_replace( '#^https?:#i', '', $to );

		$forms = array(
			$from      => $to,
			$bare_from => $bare_to,
		);

		foreach ( $forms as $find => $replace ) {
			$pairs[ $find ] = $replace;
			// JSON-encoded, as block attributes and many plugins store them.
			$pairs[ str_replace( '/', '\/', $find ) ] = str_replace( '/', '\/', $replace );
		}

		// URL-encoded, as in redirect parameters and some page builders.
		$pairs[ rawurlencode( $bare_from ) ] = rawurlencode( $bare_to );
	}

	/**
	 * @return array<string, string>
	 */
	public function pairs() {
		return $this->pairs;
	}

	/**
	 * @param mixed $value A column value.
	 * @return mixed
	 */
	public function replace( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! $this->pairs ) {
			return $value;
		}

		// Cheap test first: most values contain none of the finds.
		$replaced = strtr( $value, $this->pairs );
		if ( $replaced === $value ) {
			return $value;
		}

		if ( ! is_serialized( $value ) ) {
			return $replaced;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the site's own data, as maybe_unserialize() would read it.
		$data = @unserialize( $value );

		// Corrupt serialized data: leave it as it is rather than make it worse.
		if ( false === $data && 'b:0;' !== $value ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		return serialize( $this->walk( $data, 0 ) );
	}

	/**
	 * @param mixed $data  Unserialized data.
	 * @param int   $depth Recursion guard.
	 * @return mixed
	 */
	private function walk( $data, $depth ) {
		if ( $depth > 64 ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			// May itself be serialized: options inside options are common.
			return $this->replace( $data );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->walk( $value, $depth + 1 );
			}
			return $data;
		}

		// An object of a class this request has not loaded cannot be changed;
		// for loaded ones only the public properties are reachable from here.
		if ( is_object( $data ) && ! $data instanceof __PHP_Incomplete_Class ) {
			foreach ( get_object_vars( $data ) as $key => $value ) {
				$data->$key = $this->walk( $value, $depth + 1 );
			}
		}

		return $data;
	}
}
