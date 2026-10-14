<?php
/**
 * Table export and import.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Moves tables in chunks without taking the receiving site down.
 *
 * Rows go into temporary copies ("_rwt_" + table) while the site keeps
 * running on its real tables. At the end one RENAME TABLE statement swaps all
 * of them at once and keeps the previous tables as "_rwb_" + table, which is
 * the backup a restore puts back. Until that statement runs, nothing the
 * site uses has changed.
 */
class RunWP_Migrate_Database {

	const TEMP_PREFIX   = '_rwt_';
	const BACKUP_PREFIX = '_rwb_';

	/** Rows fetched per query while filling a chunk. */
	const BATCH_ROWS = 200;

	/** Upper bound for rows in one chunk, whatever their size. */
	const MAX_ROWS = 5000;

	/**
	 * This site's tables: base tables carrying its prefix, with row counts
	 * and sizes as the server estimates them.
	 *
	 * @return array<int, array{name:string, rows:int, size:int}>
	 */
	public static function tables() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS size
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = %s AND TABLE_NAME LIKE %s
				ORDER BY TABLE_NAME',
				'BASE TABLE',
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);

		$tables = array();
		foreach ( (array) $rows as $row ) {
			$tables[] = array(
				'name' => $row['name'],
				'rows' => (int) $row['row_count'],
				'size' => (int) $row['size'],
			);
		}

		return $tables;
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function is_own_table( $table ) {
		return in_array( $table, wp_list_pluck( self::tables(), 'name' ), true );
	}

	/**
	 * @param string $table One of this site's tables.
	 * @return string|WP_Error
	 */
	public static function create_statement( $table ) {
		global $wpdb;

		if ( ! self::is_own_table( $table ) ) {
			return self::unknown_table( $table );
		}

		$row = $wpdb->get_row( 'SHOW CREATE TABLE `' . $table . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- validated against the table list.

		return isset( $row[1] ) ? $row[1] : new WP_Error( 'runwp_migrate_db', $wpdb->last_error );
	}

	/**
	 * One chunk of rows, already rewritten for the receiving site.
	 *
	 * Tables with a single-column primary key are paged by key, which stays
	 * fast however far in it is; the rest by offset. A chunk ends at
	 * $max_bytes of data, so a few huge rows cannot blow the request size.
	 *
	 * @param string                 $table     One of this site's tables.
	 * @param string|int|null        $cursor    Where the previous chunk ended.
	 * @param RunWP_Migrate_Replacer $replacer  URL and path rewriting.
	 * @param int                    $max_bytes Size budget.
	 * @return array{columns:string[], rows:array, cursor:mixed, done:bool, count:int}|WP_Error
	 */
	public static function export_rows( $table, $cursor, RunWP_Migrate_Replacer $replacer, $max_bytes ) {
		global $wpdb;

		if ( ! self::is_own_table( $table ) ) {
			return self::unknown_table( $table );
		}

		if ( null !== $cursor && ! is_scalar( $cursor ) ) {
			$cursor = null;
		}

		$key   = self::primary_key( $table );
		$skip  = self::skipped_columns( $table );
		$where = self::row_filter( $table );

		$columns = array();
		$out     = array();
		$bytes   = 0;
		$done    = false;
		$offset  = null === $key ? (int) $cursor : 0;

		while ( count( $out ) < self::MAX_ROWS && $bytes < $max_bytes ) {
			$conditions = $where ? array( $where ) : array();

			if ( null !== $key && null !== $cursor ) {
				$conditions[] = $wpdb->prepare( '`' . $key . '` > %s', $cursor ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}

			$sql = 'SELECT * FROM `' . $table . '`' . ( $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '' );
			$sql .= null !== $key
				? ' ORDER BY `' . $key . '` LIMIT ' . self::BATCH_ROWS
				: ' LIMIT ' . $offset . ', ' . self::BATCH_ROWS;

			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers validated, values prepared.

			if ( null === $rows && $wpdb->last_error ) {
				return new WP_Error( 'runwp_migrate_db', $wpdb->last_error );
			}

			foreach ( (array) $rows as $row ) {
				if ( ! $columns ) {
					$columns = array_keys( $row );
				}

				$values = array();
				foreach ( $row as $column => $value ) {
					if ( null !== $value && ! in_array( $column, $skip, true ) ) {
						$value = $replacer->replace( $value );
					}
					$values[] = self::encode( $value );
					$bytes   += null === $value ? 4 : strlen( $value );
				}
				$out[] = $values;

				if ( null !== $key ) {
					$cursor = $row[ $key ];
				} else {
					++$offset;
				}

				// Stop between rows, never inside one: the cursor already
				// points behind the last row sent.
				if ( $bytes >= $max_bytes || count( $out ) >= self::MAX_ROWS ) {
					break 2;
				}
			}

			if ( count( (array) $rows ) < self::BATCH_ROWS ) {
				$done = true;
				break;
			}
		}

		return array(
			'columns' => $columns,
			'rows'    => $out,
			'cursor'  => null === $key ? $offset : $cursor,
			'done'    => $done,
			'count'   => count( $out ),
		);
	}

	/**
	 * Values travel as JSON, which only carries valid UTF-8. Anything else
	 * (serialized binary, broken encodings) goes base64-wrapped.
	 *
	 * @param string|null $value Column value.
	 * @return string|array|null
	 */
	private static function encode( $value ) {
		if ( null === $value || preg_match( '//u', $value ) ) {
			return $value;
		}

		return array( 'b64' => base64_encode( $value ) );
	}

	/**
	 * @param string|array|null $value Encoded value.
	 * @return string|null
	 */
	private static function decode( $value ) {
		if ( is_array( $value ) ) {
			return isset( $value['b64'] ) ? (string) base64_decode( $value['b64'] ) : '';
		}

		return null === $value ? null : (string) $value;
	}

	/**
	 * The single-column primary key, or null.
	 *
	 * @param string $table Table name.
	 * @return string|null
	 */
	private static function primary_key( $table ) {
		global $wpdb;

		$keys = $wpdb->get_results( 'SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return ( is_array( $keys ) && 1 === count( $keys ) ) ? $keys[0]['Column_name'] : null;
	}

	/**
	 * Columns left exactly as they are. A post's GUID is an identifier, not a
	 * link, and feed readers rely on it never changing.
	 *
	 * @param string $table Table name.
	 * @return string[]
	 */
	private static function skipped_columns( $table ) {
		global $wpdb;
		return $wpdb->posts === $table ? array( 'guid' ) : array();
	}

	/**
	 * Rows a table never sends. This plugin's own settings describe the
	 * installation, not its content; the receiving site keeps its own.
	 *
	 * @param string $table Table name.
	 * @return string SQL condition or ''.
	 */
	private static function row_filter( $table ) {
		global $wpdb;

		if ( $wpdb->options !== $table ) {
			return '';
		}

		return $wpdb->prepare( 'option_name NOT LIKE %s', $wpdb->esc_like( 'runwp_migrate_' ) . '%' );
	}

	/**
	 * Start receiving: clear temporary tables an earlier, interrupted run
	 * left behind.
	 *
	 * @return true
	 */
	public static function begin_import() {
		global $wpdb;

		self::foreign_checks_off();
		foreach ( self::tables_like( self::TEMP_PREFIX ) as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return true;
	}

	/**
	 * Create the temporary copy of a table from the sender's definition.
	 *
	 * @param string $table         This site's name for the table.
	 * @param string $create        The sender's CREATE TABLE statement.
	 * @param string $source_prefix The sender's table prefix.
	 * @return true|WP_Error
	 */
	public static function create_temp( $table, $create, $source_prefix ) {
		global $wpdb;

		$check = self::check_target( $table );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! preg_match( '/^CREATE TABLE `[^`]+`/', (string) $create ) ) {
			return new WP_Error( 'runwp_migrate_create', __( 'The table definition sent is not a CREATE TABLE statement.', 'runwp-migrate' ) );
		}

		$sql = preg_replace( '/^CREATE TABLE `[^`]+`/', 'CREATE TABLE `' . self::TEMP_PREFIX . $table . '`', $create, 1 );
		$sql = self::rewrite_foreign_keys( $sql, $source_prefix );

		self::foreign_checks_off();
		$wpdb->query( 'DROP TABLE IF EXISTS `' . self::TEMP_PREFIX . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a table definition, checked above.
			// MySQL 8's default collation is unknown to MariaDB and older MySQL.
			$fallback = preg_replace( '/utf8mb4_0900_\w+/', 'utf8mb4_unicode_520_ci', $sql );
			if ( $fallback === $sql || false === $wpdb->query( $fallback ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				return new WP_Error( 'runwp_migrate_db', sprintf( 'CREATE %s: %s', $table, $wpdb->last_error ) );
			}
		}

		return true;
	}

	/**
	 * Foreign keys are pointed at the temporary copy of the table they
	 * reference, so the final swap carries the reference over to the real
	 * name. Constraint names are unique per database, and while a migration
	 * runs the same constraint can exist up to three times: on the table in
	 * use, on its backup, and on the copy being built. A taken name gets the
	 * first free "_rwm", "_rwm2", … suffix.
	 *
	 * @param string $sql           CREATE TABLE statement.
	 * @param string $source_prefix The sender's table prefix.
	 * @return string
	 */
	private static function rewrite_foreign_keys( $sql, $source_prefix ) {
		global $wpdb;

		if ( false === stripos( $sql, 'FOREIGN KEY' ) ) {
			return $sql;
		}

		$taken = $wpdb->get_col( "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'" );

		return preg_replace_callback(
			'/CONSTRAINT `([^`]+)` FOREIGN KEY (\([^)]*\)) REFERENCES `([^`]+)`/',
			static function ( $match ) use ( $source_prefix, &$taken, $wpdb ) {
				$base = substr( preg_replace( '/_rwm\d*$/', '', $match[1] ), 0, 56 );
				$name = $match[1];
				for ( $n = 1; in_array( $name, $taken, true ); $n++ ) {
					$name = $base . '_rwm' . ( $n > 1 ? $n : '' );
				}
				$taken[] = $name;

				$referenced = $match[3];
				if ( 0 === strpos( $referenced, $source_prefix ) ) {
					$referenced = self::TEMP_PREFIX . $wpdb->prefix . substr( $referenced, strlen( $source_prefix ) );
				}

				return 'CONSTRAINT `' . $name . '` FOREIGN KEY ' . $match[2] . ' REFERENCES `' . $referenced . '`';
			},
			$sql
		);
	}

	/**
	 * @param string $table   This site's name for the table.
	 * @param array  $columns Column names.
	 * @param array  $rows    Encoded rows, in column order.
	 * @return true|WP_Error
	 */
	public static function insert_rows( $table, array $columns, array $rows ) {
		global $wpdb;

		$check = self::check_target( $table );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		if ( ! $rows ) {
			return true;
		}

		foreach ( $columns as $column ) {
			if ( ! is_string( $column ) || ! preg_match( '/^[^`\x00]{1,64}$/u', $column ) ) {
				return new WP_Error( 'runwp_migrate_column', __( 'A column name in the data is not valid.', 'runwp-migrate' ) );
			}
		}

		$dbh    = $wpdb->dbh;
		$tuples = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== count( $columns ) ) {
				return new WP_Error( 'runwp_migrate_row', __( 'A row in the data does not match its columns.', 'runwp-migrate' ) );
			}

			$values = array();
			foreach ( $row as $value ) {
				$value    = self::decode( $value );
				$values[] = null === $value ? 'NULL' : "'" . mysqli_real_escape_string( $dbh, $value ) . "'";
			}
			$tuples[] = '(' . implode( ',', $values ) . ')';
		}

		self::foreign_checks_off();

		// wpdb would re-check every value against the column charsets, which
		// is slow on large chunks and refuses some legitimate binary data.
		$wpdb->check_current_query = false;
		$result = $wpdb->query( 'INSERT INTO `' . self::TEMP_PREFIX . $table . '` (`' . implode( '`,`', $columns ) . '`) VALUES ' . implode( ',', $tuples ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value escaped above.

		if ( false === $result ) {
			return new WP_Error( 'runwp_migrate_db', sprintf( 'INSERT %s: %s', $table, $wpdb->last_error ) );
		}

		return true;
	}

	/**
	 * Swap the temporary tables in and keep the old ones as the backup.
	 *
	 * @param string[] $tables        This site's names for the tables received.
	 * @param string   $source_prefix The sender's table prefix.
	 * @param string   $label         Where the data came from, for the backup notice.
	 * @return array{tables:int}|WP_Error
	 */
	public static function finish_import( array $tables, $source_prefix, $label ) {
		global $wpdb;

		$prefix = $wpdb->prefix;

		foreach ( $tables as $table ) {
			$check = self::check_target( $table );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			if ( ! self::table_exists( self::TEMP_PREFIX . $table ) ) {
				/* translators: %s: table name. */
				return new WP_Error( 'runwp_migrate_incomplete', sprintf( __( 'Table %s was not transferred completely. Start the migration again.', 'runwp-migrate' ), $table ) );
			}
		}

		$keep = self::read_own_options();

		if ( $source_prefix !== $prefix ) {
			self::rename_prefixed_keys( $source_prefix, $prefix );
		}

		self::foreign_checks_off();

		// A new backup replaces the old one.
		foreach ( self::tables_like( self::BACKUP_PREFIX ) as $old ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $old . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$renames = array();
		$created = array();
		foreach ( $tables as $table ) {
			if ( self::table_exists( $table ) ) {
				$renames[] = '`' . $table . '` TO `' . self::BACKUP_PREFIX . $table . '`';
			} else {
				$created[] = $table;
			}
			$renames[] = '`' . self::TEMP_PREFIX . $table . '` TO `' . $table . '`';
		}

		// One statement: the site sees the old tables or the new ones, never
		// a mix of both.
		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'runwp_migrate_db', 'RENAME: ' . $wpdb->last_error );
		}

		self::write_own_options( $keep );
		self::keep_plugin_active();

		self::write_option(
			RunWP_Migrate_Settings::OPT_BACKUP,
			array(
				'time'    => time(),
				'label'   => $label,
				'tables'  => count( $tables ) - count( $created ),
				'created' => $created,
			)
		);

		wp_cache_flush();

		return array( 'tables' => count( $tables ) );
	}

	/**
	 * Put the tables the last migration replaced back, and drop the tables
	 * it added.
	 *
	 * @return array{tables:int}|WP_Error
	 */
	public static function restore_backup() {
		global $wpdb;

		$backups = self::tables_like( self::BACKUP_PREFIX );
		if ( ! $backups ) {
			return new WP_Error( 'runwp_migrate_no_backup', __( 'There is no backup to restore.', 'runwp-migrate' ) );
		}

		$info = self::read_option( RunWP_Migrate_Settings::OPT_BACKUP );
		$keep = self::read_own_options();

		self::foreign_checks_off();

		$renames = array();
		$discard = array();
		foreach ( $backups as $backup ) {
			$table = substr( $backup, strlen( self::BACKUP_PREFIX ) );
			if ( self::table_exists( $table ) ) {
				$renames[] = '`' . $table . '` TO `' . self::TEMP_PREFIX . $table . '`';
				$discard[] = self::TEMP_PREFIX . $table;
			}
			$renames[] = '`' . $backup . '` TO `' . $table . '`';
		}

		if ( false === $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return new WP_Error( 'runwp_migrate_db', 'RENAME: ' . $wpdb->last_error );
		}

		$created = ( is_array( $info ) && ! empty( $info['created'] ) ) ? (array) $info['created'] : array();
		foreach ( $created as $table ) {
			if ( is_string( $table ) && 0 === strpos( $table, $wpdb->prefix ) && preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				$discard[] = $table;
			}
		}

		foreach ( $discard as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		self::write_own_options( $keep );
		self::keep_plugin_active();
		$wpdb->delete( $wpdb->options, array( 'option_name' => RunWP_Migrate_Settings::OPT_BACKUP ) );

		wp_cache_flush();

		return array( 'tables' => count( $backups ) );
	}

	/**
	 * What a restore would bring back, or null.
	 *
	 * @return array{time:int, label:string, tables:int}|null
	 */
	public static function backup_info() {
		if ( ! self::tables_like( self::BACKUP_PREFIX ) ) {
			return null;
		}

		$info = get_option( RunWP_Migrate_Settings::OPT_BACKUP );

		return is_array( $info ) ? $info : array(
			'time'   => 0,
			'label'  => '',
			'tables' => count( self::tables_like( self::BACKUP_PREFIX ) ),
		);
	}

	/**
	 * Option and user meta keys that carry the table prefix ("wp_user_roles",
	 * "wp_capabilities") follow it to the new one, or every user would lose
	 * their role.
	 *
	 * @param string $from Sender's prefix.
	 * @param string $to   This site's prefix.
	 */
	private static function rename_prefixed_keys( $from, $to ) {
		global $wpdb;

		$options  = self::TEMP_PREFIX . $wpdb->options;
		$usermeta = self::TEMP_PREFIX . $wpdb->usermeta;

		if ( self::table_exists( $options ) ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE `' . $options . '` SET option_name = %s WHERE option_name = %s', $to . 'user_roles', $from . 'user_roles' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( self::table_exists( $usermeta ) ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE `' . $usermeta . '` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$to,
					strlen( $from ) + 1,
					$wpdb->esc_like( $from ) . '%'
				)
			);
		}
	}

	/**
	 * This plugin's settings as stored, before the options table is swapped.
	 *
	 * @return array<string, array{value:string, autoload:string}>
	 */
	private static function read_own_options() {
		global $wpdb;

		$names = RunWP_Migrate_Settings::own_options();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value, autoload FROM ' . $wpdb->options . ' WHERE option_name IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$names
			),
			ARRAY_A
		);

		$keep = array();
		foreach ( (array) $rows as $row ) {
			$keep[ $row['option_name'] ] = array(
				'value'    => $row['option_value'],
				'autoload' => $row['autoload'],
			);
		}

		return $keep;
	}

	/**
	 * @param array $keep From read_own_options().
	 */
	private static function write_own_options( array $keep ) {
		global $wpdb;

		foreach ( $keep as $name => $row ) {
			$wpdb->replace(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $row['value'],
					'autoload'     => $row['autoload'],
				)
			);
		}
	}

	/**
	 * Raw read, past the object cache, which still holds the old tables.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	private static function read_option( $name ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return null === $value ? null : maybe_unserialize( $value );
	}

	/**
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 */
	private static function write_option( $name, $value ) {
		global $wpdb;

		$wpdb->replace(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => maybe_serialize( $value ),
				'autoload'     => 'off',
			)
		);
	}

	/**
	 * The sender's plugin list replaced ours. This plugin has to stay active,
	 * or the next migration could not reach the site.
	 */
	private static function keep_plugin_active() {
		$plugins = self::read_option( 'active_plugins' );
		$plugins = is_array( $plugins ) ? $plugins : array();
		$self    = plugin_basename( RUNWP_MIGRATE_FILE );

		if ( ! in_array( $self, $plugins, true ) ) {
			$plugins[] = $self;
			self::write_option( 'active_plugins', array_values( $plugins ) );

			global $wpdb;
			$wpdb->update( $wpdb->options, array( 'autoload' => 'on' ), array( 'option_name' => 'active_plugins' ) );
		}
	}

	/**
	 * @param string $table This site's name for a table it is about to receive.
	 * @return true|WP_Error
	 */
	private static function check_target( $table ) {
		global $wpdb;

		if ( ! is_string( $table ) || 0 !== strpos( $table, $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			/* translators: %s: table name. */
			return new WP_Error( 'runwp_migrate_table', sprintf( __( '%s is not a valid table name for this site.', 'runwp-migrate' ), (string) $table ) );
		}

		if ( strlen( self::TEMP_PREFIX . $table ) > 64 ) {
			/* translators: %s: table name. */
			return new WP_Error( 'runwp_migrate_table', sprintf( __( 'Table name %s is too long to migrate.', 'runwp-migrate' ), $table ) );
		}

		return true;
	}

	/**
	 * @param string $table Table name.
	 * @return WP_Error
	 */
	private static function unknown_table( $table ) {
		/* translators: %s: table name. */
		return new WP_Error( 'runwp_migrate_table', sprintf( __( 'Table %s does not exist on this site.', 'runwp-migrate' ), (string) $table ), array( 'status' => 404 ) );
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * @param string $prefix One of the plugin's working prefixes.
	 * @return string[]
	 */
	private static function tables_like( $prefix ) {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
	}

	/**
	 * Rows and tables arrive in an order that ignores foreign keys; the
	 * checks are per connection, so every request switches them off again.
	 */
	private static function foreign_checks_off() {
		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
	}
}
