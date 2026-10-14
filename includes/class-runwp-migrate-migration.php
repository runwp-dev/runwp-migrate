<?php
/**
 * A pull or push, run in short steps.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plans a migration and moves it forward one step at a time, so neither a
 * browser request nor a PHP time limit has to hold out for the whole run.
 * The admin screen calls step() until it reports done; WP-CLI does the same
 * in a loop. State lives in an option between steps.
 *
 * Order of work: tables into temporary copies, then media, then the swap.
 * The swap comes last because on a pull it replaces this site's own tables,
 * login sessions included, and nothing should need them afterwards.
 */
class RunWP_Migrate_Migration {

	/** Work done per step before handing back, in seconds. */
	const STEP_SECONDS = 8;

	/** Upper bound for one chunk of rows or file data, in bytes. */
	const MAX_CHUNK = 2097152;

	/** Files read and written per request. */
	const FILES_PER_REQUEST = 50;

	/**
	 * Both ends of a migration, source first.
	 *
	 * @param string $direction  'pull' or 'push'.
	 * @param string $connection Connection name.
	 * @return RunWP_Migrate_Endpoint[]|WP_Error
	 */
	private static function ends( $direction, $connection ) {
		$saved = RunWP_Migrate_Settings::connection( $connection );

		if ( ! $saved ) {
			/* translators: %s: connection name. */
			return new WP_Error( 'runwp_migrate_connection', sprintf( __( 'There is no connection called "%s".', 'runwp-migrate' ), $connection ) );
		}

		$remote = new RunWP_Migrate_Remote( $saved['url'], $saved['key'] );
		$local  = new RunWP_Migrate_Local();

		return 'pull' === $direction ? array( $remote, $local ) : array( $local, $remote );
	}

	/**
	 * What a migration would do: both sites, the tables, the replacements.
	 * Talks to the other site, so it doubles as a connection test.
	 *
	 * @param string $direction  'pull' or 'push'.
	 * @param string $connection Connection name.
	 * @return array|WP_Error
	 */
	public static function plan( $direction, $connection ) {
		$ends = self::ends( $direction, $connection );
		if ( is_wp_error( $ends ) ) {
			return $ends;
		}

		list( $source, $dest ) = $ends;

		$from = $source->info();
		if ( is_wp_error( $from ) ) {
			return $from;
		}

		$to = $dest->info();
		if ( is_wp_error( $to ) ) {
			return $to;
		}

		foreach ( array( $from, $to ) as $side ) {
			if ( (int) $side['protocol'] !== RUNWP_MIGRATE_PROTOCOL ) {
				return new WP_Error( 'runwp_migrate_protocol', __( 'The two sites run versions of RunWP Migrate that cannot talk to each other. Update it on both.', 'runwp-migrate' ) );
			}
			if ( ! empty( $side['multisite'] ) ) {
				return new WP_Error( 'runwp_migrate_multisite', __( 'RunWP Migrate does not support multisite networks.', 'runwp-migrate' ) );
			}
		}

		if ( untrailingslashit( $from['home'] ) === untrailingslashit( $to['home'] ) && $from['abspath'] === $to['abspath'] && $from['prefix'] === $to['prefix'] ) {
			return new WP_Error( 'runwp_migrate_self', __( 'This connection leads back to this very site.', 'runwp-migrate' ) );
		}

		$remote = 'pull' === $direction ? $from : $to;
		if ( 'pull' === $direction && empty( $remote['allow_pull'] ) ) {
			return new WP_Error( 'runwp_migrate_pull_off', __( 'Pulling is switched off on the other site. Allow it there under Tools → RunWP Migrate.', 'runwp-migrate' ) );
		}
		if ( 'push' === $direction && empty( $remote['allow_push'] ) ) {
			return new WP_Error( 'runwp_migrate_push_off', __( 'Pushing is switched off on the other site. Allow it there under Tools → RunWP Migrate.', 'runwp-migrate' ) );
		}

		$tables = array();
		foreach ( (array) $from['tables'] as $table ) {
			$table['dest'] = $to['prefix'] . substr( $table['name'], strlen( $from['prefix'] ) );
			$tables[]      = $table;
		}

		return array(
			'direction'  => $direction,
			'connection' => $connection,
			'from'       => $from,
			'to'         => $to,
			'pairs'      => RunWP_Migrate_Replacer::pairs_for( $from, $to ),
			'tables'     => $tables,
		);
	}

	/**
	 * Begin a migration. Replaces any unfinished one.
	 *
	 * @param string        $direction  'pull' or 'push'.
	 * @param string        $connection Connection name.
	 * @param bool          $db         Transfer the database.
	 * @param bool          $media      Transfer the uploads folder.
	 * @param string[]|null $tables     Source table names, or null for all.
	 * @return array|WP_Error State.
	 */
	public static function start( $direction, $connection, $db, $media, $tables = null ) {
		if ( ! $db && ! $media ) {
			return new WP_Error( 'runwp_migrate_nothing', __( 'Choose the database, the media, or both.', 'runwp-migrate' ) );
		}

		$plan = self::plan( $direction, $connection );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$selected = $plan['tables'];
		if ( is_array( $tables ) ) {
			$selected = array_values(
				array_filter(
					$selected,
					static function ( $table ) use ( $tables ) {
						return in_array( $table['name'], $tables, true );
					}
				)
			);
		}

		if ( $db && ! $selected ) {
			return new WP_Error( 'runwp_migrate_no_tables', __( 'No tables selected.', 'runwp-migrate' ) );
		}

		foreach ( $selected as $table ) {
			if ( strlen( RunWP_Migrate_Database::TEMP_PREFIX . $table['dest'] ) > 64 ) {
				/* translators: %s: table name. */
				return new WP_Error( 'runwp_migrate_table', sprintf( __( 'Table name %s is too long to migrate. Leave the table out.', 'runwp-migrate' ), $table['dest'] ) );
			}
		}

		// Chunks have to fit the smaller of the two sites' request limits,
		// with room for JSON and base64 overhead.
		$limits = array_filter( array( (int) $plan['from']['max_body'], (int) $plan['to']['max_body'] ) );
		$chunk  = $limits ? min( self::MAX_CHUNK, max( 262144, (int) ( min( $limits ) / 3 ) ) ) : self::MAX_CHUNK;

		$state = array(
			'id'         => wp_generate_uuid4(),
			'direction'  => $direction,
			'connection' => $connection,
			'db'         => (bool) $db,
			'media'      => (bool) $media,
			'from'       => array(
				'home'   => $plan['from']['home'],
				'prefix' => $plan['from']['prefix'],
			),
			'to'         => array(
				'home'   => $plan['to']['home'],
				'prefix' => $plan['to']['prefix'],
			),
			'pairs'      => $plan['pairs'],
			'chunk'      => $chunk,
			'tables'     => array(),
			'table'      => 0,
			'phase'      => $db ? 'db' : 'files_scan',
			'files'      => array(
				'queue'  => array(),
				'index'  => 0,
				'offset' => 0,
				'total'  => 0,
				'sent'   => 0,
			),
			'started'    => time(),
			'message'    => '',
		);

		if ( $db ) {
			foreach ( $selected as $table ) {
				$state['tables'][] = array(
					'source'  => $table['name'],
					'dest'    => $table['dest'],
					'rows'    => (int) $table['rows'],
					'sent'    => 0,
					'created' => false,
					'cursor'  => null,
					'done'    => false,
				);
			}

			list( , $dest ) = self::ends( $direction, $connection );
			$begun          = $dest->import_begin();
			if ( is_wp_error( $begun ) ) {
				return $begun;
			}
		}

		update_option( RunWP_Migrate_Settings::OPT_RUN, $state, false );

		return $state;
	}

	/**
	 * The migration in progress, or null.
	 *
	 * @return array|null
	 */
	public static function current() {
		$state = get_option( RunWP_Migrate_Settings::OPT_RUN );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Work for up to STEP_SECONDS, then report.
	 *
	 * @return array|WP_Error State, or the error that stopped this step. A
	 *                        failed step can simply be tried again.
	 */
	public static function step() {
		$state = self::current();
		if ( ! $state ) {
			return new WP_Error( 'runwp_migrate_idle', __( 'No migration is running.', 'runwp-migrate' ) );
		}

		$ends = self::ends( $state['direction'], $state['connection'] );
		if ( is_wp_error( $ends ) ) {
			return $ends;
		}

		list( $source, $dest ) = $ends;
		$deadline              = microtime( true ) + self::STEP_SECONDS;

		while ( 'done' !== $state['phase'] ) {
			$advanced = self::advance( $state, $source, $dest );

			if ( is_wp_error( $advanced ) ) {
				update_option( RunWP_Migrate_Settings::OPT_RUN, $state, false );
				return $advanced;
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( 'done' === $state['phase'] ) {
			// After a pull this deletes nothing: the option went with the
			// tables that were just swapped out.
			delete_option( RunWP_Migrate_Settings::OPT_RUN );
		} else {
			update_option( RunWP_Migrate_Settings::OPT_RUN, $state, false );
		}

		return $state;
	}

	/**
	 * One unit of work: a chunk of rows, a batch of files, or the swap.
	 *
	 * @param array                  $state  State, updated in place.
	 * @param RunWP_Migrate_Endpoint $source Where the data comes from.
	 * @param RunWP_Migrate_Endpoint $dest   Where it goes.
	 * @return true|WP_Error
	 */
	private static function advance( array &$state, RunWP_Migrate_Endpoint $source, RunWP_Migrate_Endpoint $dest ) {
		switch ( $state['phase'] ) {
			case 'db':
				$table = &$state['tables'][ $state['table'] ];

				if ( ! $table['created'] ) {
					$create = $source->export_create( $table['source'] );
					if ( is_wp_error( $create ) ) {
						return $create;
					}

					$created = $dest->import_create( $table['dest'], $create, $state['from']['prefix'] );
					if ( is_wp_error( $created ) ) {
						return $created;
					}

					$table['created'] = true;
				}

				$chunk = $source->export_rows( $table['source'], $table['cursor'], $state['pairs'], $state['chunk'] );
				if ( is_wp_error( $chunk ) ) {
					return $chunk;
				}

				if ( ! empty( $chunk['rows'] ) ) {
					$imported = $dest->import_rows( $table['dest'], (array) $chunk['columns'], (array) $chunk['rows'] );
					if ( is_wp_error( $imported ) ) {
						return $imported;
					}
				}

				$table['cursor'] = $chunk['cursor'];
				$table['sent']  += (int) $chunk['count'];

				if ( ! empty( $chunk['done'] ) ) {
					$table['done'] = true;
					++$state['table'];
				}

				unset( $table );

				if ( $state['table'] >= count( $state['tables'] ) ) {
					$state['phase'] = $state['media'] ? 'files_scan' : 'finish';
				}
				break;

			case 'files_scan':
				$have = $source->files_manifest();
				if ( is_wp_error( $have ) ) {
					return $have;
				}

				$want = $dest->files_manifest();
				if ( is_wp_error( $want ) ) {
					return $want;
				}

				$queue = array();
				$total = 0;
				foreach ( $have as $path => $meta ) {
					$path = (string) $path;
					$size = (int) $meta[0];
					$time = (int) $meta[1];

					if ( isset( $want[ $path ] ) && (int) $want[ $path ][0] === $size && (int) $want[ $path ][1] === $time ) {
						continue;
					}

					$queue[] = array( $path, $size, $time );
					$total  += $size;
				}

				$state['files'] = array(
					'queue'  => $queue,
					'index'  => 0,
					'offset' => 0,
					'total'  => $total,
					'sent'   => 0,
				);

				$state['phase'] = $queue ? 'files' : ( $state['db'] ? 'finish' : 'done' );
				break;

			case 'files':
				$files  = &$state['files'];
				$count  = count( $files['queue'] );
				$index  = $files['index'];
				$offset = $files['offset'];
				$budget = $state['chunk'];
				$batch  = array();

				while ( $index < $count && count( $batch ) < self::FILES_PER_REQUEST && $budget > 0 ) {
					list( $path, $size, $time ) = $files['queue'][ $index ];

					$length  = min( $size - $offset, $budget );
					$batch[] = array(
						'path'   => $path,
						'offset' => $offset,
						'length' => $length,
						'size'   => $size,
						'mtime'  => $time,
						'final'  => $offset + $length >= $size,
					);
					$budget -= max( 1, $length );

					if ( $offset + $length >= $size ) {
						++$index;
						$offset = 0;
					} else {
						// The rest of a large file is the next batch.
						$offset += $length;
						break;
					}
				}

				$read = $source->files_read(
					array_map(
						static function ( $item ) {
							return array(
								'path'   => $item['path'],
								'offset' => $item['offset'],
								'length' => $item['length'],
							);
						},
						$batch
					)
				);
				if ( is_wp_error( $read ) ) {
					return $read;
				}

				$write = array();
				foreach ( array_values( $read ) as $i => $part ) {
					if ( ! empty( $part['missing'] ) ) {
						continue;
					}
					$write[] = array(
						'path'   => $batch[ $i ]['path'],
						'offset' => $batch[ $i ]['offset'],
						'data'   => $part['data'],
						'size'   => $batch[ $i ]['size'],
						'mtime'  => $batch[ $i ]['mtime'],
						'final'  => $batch[ $i ]['final'],
					);
				}

				if ( $write ) {
					$written = $dest->files_write( $write );
					if ( is_wp_error( $written ) ) {
						return $written;
					}
				}

				$files['sent']  += array_sum( wp_list_pluck( $batch, 'length' ) );
				$files['index']  = $index;
				$files['offset'] = $offset;

				unset( $files );

				if ( $state['files']['index'] >= $count ) {
					$state['phase'] = $state['db'] ? 'finish' : 'done';
				}
				break;

			case 'finish':
				$finished = $dest->import_finish( wp_list_pluck( $state['tables'], 'dest' ), $state['from']['prefix'], $state['from']['home'] );
				if ( is_wp_error( $finished ) ) {
					return $finished;
				}

				$state['phase'] = 'done';
				break;
		}

		return true;
	}

	/**
	 * Stop and clean up. Temporary tables on the receiving side go; files
	 * already copied stay.
	 *
	 * @return true
	 */
	public static function cancel() {
		$state = self::current();

		if ( $state && $state['db'] && 'done' !== $state['phase'] ) {
			$ends = self::ends( $state['direction'], $state['connection'] );
			if ( ! is_wp_error( $ends ) ) {
				$ends[1]->import_begin();
			}
		}

		delete_option( RunWP_Migrate_Settings::OPT_RUN );

		return true;
	}

	/**
	 * Undo the last migration into a site: this one, or a connected one.
	 *
	 * @param string|null $connection Connection name, or null for this site.
	 * @return array|WP_Error
	 */
	public static function restore( $connection = null ) {
		if ( null === $connection ) {
			return ( new RunWP_Migrate_Local() )->restore();
		}

		$saved = RunWP_Migrate_Settings::connection( $connection );
		if ( ! $saved ) {
			/* translators: %s: connection name. */
			return new WP_Error( 'runwp_migrate_connection', sprintf( __( 'There is no connection called "%s".', 'runwp-migrate' ), $connection ) );
		}

		return ( new RunWP_Migrate_Remote( $saved['url'], $saved['key'] ) )->restore();
	}

	/**
	 * Percent done. Rows and file bytes each count for their share of the
	 * run; row counts are the server's estimates, so a table can run over.
	 *
	 * @param array $state State.
	 * @return int
	 */
	public static function percent( array $state ) {
		if ( 'done' === $state['phase'] ) {
			return 100;
		}

		$parts = array();

		if ( $state['db'] ) {
			$total = 0;
			$sent  = 0;
			foreach ( $state['tables'] as $table ) {
				$rows   = max( 1, (int) $table['rows'] );
				$total += $rows;
				$sent  += $table['done'] ? $rows : min( $rows, (int) $table['sent'] );
			}
			$parts[] = $total ? $sent / $total : 1;
		}

		if ( $state['media'] ) {
			if ( in_array( $state['phase'], array( 'db', 'files_scan' ), true ) ) {
				$parts[] = 0;
			} else {
				$parts[] = $state['files']['total'] ? min( 1, $state['files']['sent'] / $state['files']['total'] ) : 1;
			}
		}

		// The swap is quick, but leave the bar short of full until it ran.
		return (int) min( 99, floor( array_sum( $parts ) / max( 1, count( $parts ) ) * 100 ) );
	}

	/**
	 * What the run is doing right now, for the progress line.
	 *
	 * @param array $state State.
	 * @return string
	 */
	public static function describe( array $state ) {
		switch ( $state['phase'] ) {
			case 'db':
				$table = $state['tables'][ $state['table'] ];
				/* translators: 1: table name, 2: rows sent, 3: estimated rows. */
				return sprintf( __( 'Table %1$s: %2$s of about %3$s rows', 'runwp-migrate' ), $table['source'], number_format_i18n( $table['sent'] ), number_format_i18n( max( $table['rows'], $table['sent'] ) ) );

			case 'files_scan':
				return __( 'Comparing media files …', 'runwp-migrate' );

			case 'files':
				$files = $state['files'];
				return sprintf(
					/* translators: 1: files done, 2: files total, 3: size done, 4: size total. */
					__( 'Media: %1$s of %2$s files (%3$s of %4$s)', 'runwp-migrate' ),
					number_format_i18n( $files['index'] ),
					number_format_i18n( count( $files['queue'] ) ),
					size_format( $files['sent'] ),
					size_format( $files['total'] )
				);

			case 'finish':
				return __( 'Swapping in the new tables …', 'runwp-migrate' );

			default:
				return __( 'Done.', 'runwp-migrate' );
		}
	}
}
