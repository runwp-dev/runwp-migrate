<?php
/**
 * Media library transfer.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists, reads and writes files inside the uploads directory, and nowhere
 * else. Files are compared by size and modification time; a written file
 * gets its sender's time, so it matches on the next run and is not sent
 * again. Nothing is ever deleted on the receiving side.
 */
class RunWP_Migrate_Files {

	/** Suffix of a file still being received. */
	const PART = '.rwm-part';

	/**
	 * Absolute uploads directory, normalised, without trailing slash.
	 *
	 * @return string
	 */
	public static function base() {
		$uploads = wp_get_upload_dir();
		return untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	}

	/**
	 * Top-level folders in uploads that hold caches, logs and backups rather
	 * than media.
	 *
	 * @return string[]
	 */
	public static function excluded_dirs() {
		return (array) apply_filters(
			'runwp_migrate_excluded_upload_dirs',
			array( 'cache', 'wc-logs', 'wpo-cache', 'et-cache', 'backup', 'backups', 'backwpup', 'updraft', 'ai1wm-backups', 'wp-migrate-db', 'runwp-migrate' )
		);
	}

	/**
	 * Whether a path relative to uploads may be listed, read or written.
	 * Executable types are refused in both directions: a media library has
	 * no business holding them, and an upload folder that runs PHP is the
	 * classic way into a site.
	 *
	 * @param string $relative Relative path with forward slashes.
	 * @return bool
	 */
	public static function is_allowed( $relative ) {
		$relative = (string) $relative;

		if ( '' === $relative || false !== strpos( $relative, "\0" ) || false !== strpos( $relative, '\\' ) || '/' === $relative[0] ) {
			return false;
		}

		$segments = explode( '/', $relative );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment[0] ) {
				return false;
			}
		}

		if ( in_array( strtolower( $segments[0] ), self::excluded_dirs(), true ) ) {
			return false;
		}

		if ( self::PART === substr( $relative, -strlen( self::PART ) ) ) {
			return false;
		}

		$extension = strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) );

		return ! preg_match( '/^(php\d?|phtml|pht|phps|phar|cgi|pl|py|sh|bash|exe|com|bat|htaccess|htpasswd|ini)$/', $extension );
	}

	/**
	 * Every transferable file: relative path => [size, mtime].
	 *
	 * @return array<string, int[]>
	 */
	public static function manifest() {
		$base  = self::base();
		$files = array();

		if ( ! is_dir( $base ) ) {
			return $files;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}

			$relative = substr( wp_normalize_path( $file->getPathname() ), strlen( $base ) + 1 );
			if ( self::is_allowed( $relative ) ) {
				$files[ $relative ] = array( (int) $file->getSize(), (int) $file->getMTime() );
			}
		}

		ksort( $files );

		return $files;
	}

	/**
	 * Read parts of files. A file deleted since it was listed comes back
	 * marked missing instead of failing the whole batch.
	 *
	 * @param array $items Each {path, offset, length}.
	 * @return array|WP_Error Each {path, offset, data (base64)} or {path, missing}.
	 */
	public static function read( array $items ) {
		$out = array();

		foreach ( $items as $item ) {
			$path = self::resolve( isset( $item['path'] ) ? $item['path'] : '', false );
			if ( is_wp_error( $path ) && 'runwp_migrate_missing' === $path->get_error_code() ) {
				$out[] = array(
					'path'    => $item['path'],
					'missing' => true,
				);
				continue;
			}
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$offset = max( 0, (int) $item['offset'] );
			$length = max( 0, (int) $item['length'] );
			$data   = '';

			if ( $length > 0 ) {
				$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $handle ) {
					/* translators: %s: file path. */
					return new WP_Error( 'runwp_migrate_read', sprintf( __( 'Could not read %s.', 'runwp-migrate' ), $item['path'] ) );
				}
				fseek( $handle, $offset );
				$data = (string) fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}

			$out[] = array(
				'path'   => $item['path'],
				'offset' => $offset,
				'data'   => base64_encode( $data ),
			);
		}

		return $out;
	}

	/**
	 * Write parts of files. A file is assembled under a temporary name and
	 * only takes its real one once complete, so a cut-off transfer never
	 * leaves a broken image in the media library.
	 *
	 * @param array $items Each {path, offset, data (base64), size, mtime, final}.
	 * @return true|WP_Error
	 */
	public static function write( array $items ) {
		foreach ( $items as $item ) {
			$path = self::resolve( isset( $item['path'] ) ? $item['path'] : '', true );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			$part   = $path . self::PART;
			$offset = max( 0, (int) $item['offset'] );
			$data   = base64_decode( (string) $item['data'], true );

			if ( false === $data ) {
				return new WP_Error( 'runwp_migrate_write', __( 'Received file data is corrupt.', 'runwp-migrate' ) );
			}

			if ( $offset > 0 && ( ! file_exists( $part ) || filesize( $part ) !== $offset ) ) {
				/* translators: %s: file path. */
				return new WP_Error( 'runwp_migrate_write', sprintf( __( 'Parts of %s arrived out of order. Start the migration again.', 'runwp-migrate' ), $item['path'] ) );
			}

			$handle = fopen( $part, 0 === $offset ? 'wb' : 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $handle || false === fwrite( $handle, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				/* translators: %s: file path. */
				return new WP_Error( 'runwp_migrate_write', sprintf( __( 'Could not write %s. Check the permissions of the uploads folder.', 'runwp-migrate' ), $item['path'] ) );
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			if ( ! empty( $item['final'] ) ) {
				clearstatcache( true, $part );
				if ( filesize( $part ) !== (int) $item['size'] ) {
					wp_delete_file( $part );
					/* translators: %s: file path. */
					return new WP_Error( 'runwp_migrate_write', sprintf( __( '%s arrived incomplete. Start the migration again.', 'runwp-migrate' ), $item['path'] ) );
				}

				rename( $part, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				touch( $path, (int) $item['mtime'] );
			}
		}

		return true;
	}

	/**
	 * Absolute path for a relative one, provided it stays inside uploads.
	 *
	 * @param string $relative Relative path.
	 * @param bool   $create   Create missing folders (for writing).
	 * @return string|WP_Error
	 */
	private static function resolve( $relative, $create ) {
		$relative = (string) $relative;

		if ( ! self::is_allowed( $relative ) ) {
			/* translators: %s: file path. */
			return new WP_Error( 'runwp_migrate_path', sprintf( __( '%s may not be transferred.', 'runwp-migrate' ), $relative ), array( 'status' => 403 ) );
		}

		$base = self::base();
		$path = $base . '/' . $relative;
		$dir  = dirname( $path );

		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Resolve symlinks before trusting the location.
		$real_base = realpath( $base );
		$real_dir  = realpath( $dir );

		if ( false === $real_base || false === $real_dir || ( $real_dir !== $real_base && 0 !== strpos( $real_dir . '/', $real_base . '/' ) ) ) {
			/* translators: %s: file path. */
			return new WP_Error( 'runwp_migrate_path', sprintf( __( '%s lies outside the uploads folder.', 'runwp-migrate' ), $relative ), array( 'status' => 403 ) );
		}

		if ( ! $create && ! is_file( $path ) ) {
			/* translators: %s: file path. */
			return new WP_Error( 'runwp_migrate_missing', sprintf( __( '%s no longer exists.', 'runwp-migrate' ), $relative ), array( 'status' => 404 ) );
		}

		return $path;
	}
}
