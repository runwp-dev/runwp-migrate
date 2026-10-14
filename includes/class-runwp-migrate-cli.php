<?php
/**
 * WP-CLI commands.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pull, push and manage connections from the command line.
 *
 * ## EXAMPLES
 *
 *     # On the live site: show what the local site needs, and allow pulling.
 *     wp runwp-migrate info
 *     wp runwp-migrate allow pull
 *
 *     # Locally: connect, then copy live here.
 *     wp runwp-migrate connect live https://example.com <key>
 *     wp runwp-migrate pull live
 */
class RunWP_Migrate_CLI {

	/**
	 * Copy a connected site's database and media into this site.
	 *
	 * ## OPTIONS
	 *
	 * <connection>
	 * : Name of the connection.
	 *
	 * [--db]
	 * : Transfer the database. Without --db or --media, both are transferred.
	 *
	 * [--media]
	 * : Transfer the uploads folder.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated source tables to include. Default: all.
	 *
	 * [--exclude=<tables>]
	 * : Comma-separated source tables to leave out.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp runwp-migrate pull live
	 *     wp runwp-migrate pull live --db --exclude=wp_actionscheduler_logs
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function pull( $args, $assoc_args ) {
		$this->run( 'pull', $args[0], $assoc_args );
	}

	/**
	 * Copy this site's database and media into a connected site.
	 *
	 * ## OPTIONS
	 *
	 * <connection>
	 * : Name of the connection.
	 *
	 * [--db]
	 * : Transfer the database. Without --db or --media, both are transferred.
	 *
	 * [--media]
	 * : Transfer the uploads folder.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated source tables to include. Default: all.
	 *
	 * [--exclude=<tables>]
	 * : Comma-separated source tables to leave out.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp runwp-migrate push staging
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function push( $args, $assoc_args ) {
		$this->run( 'push', $args[0], $assoc_args );
	}

	/**
	 * @param string $direction  'pull' or 'push'.
	 * @param string $connection Connection name.
	 * @param array  $assoc_args Options.
	 */
	private function run( $direction, $connection, $assoc_args ) {
		$db    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'db', false );
		$media = \WP_CLI\Utils\get_flag_value( $assoc_args, 'media', false );
		if ( ! $db && ! $media ) {
			$db    = true;
			$media = true;
		}

		$plan = RunWP_Migrate_Migration::plan( $direction, $connection );
		if ( is_wp_error( $plan ) ) {
			WP_CLI::error( $plan->get_error_message() );
		}

		$tables = wp_list_pluck( $plan['tables'], 'name' );
		if ( ! empty( $assoc_args['tables'] ) ) {
			$tables = array_intersect( $tables, array_map( 'trim', explode( ',', $assoc_args['tables'] ) ) );
		}
		if ( ! empty( $assoc_args['exclude'] ) ) {
			$tables = array_diff( $tables, array_map( 'trim', explode( ',', $assoc_args['exclude'] ) ) );
		}

		WP_CLI::log( sprintf( 'From: %s', $plan['from']['home'] ) );
		WP_CLI::log( sprintf( 'To:   %s', $plan['to']['home'] ) );
		WP_CLI::log( sprintf( 'What: %s', implode( ' + ', array_filter( array( $db ? 'database (' . count( $tables ) . ' tables)' : '', $media ? 'media' : '' ) ) ) ) );
		foreach ( $plan['pairs'] as $find => $replace ) {
			WP_CLI::log( sprintf( '      %s → %s', $find, $replace ) );
		}

		$target = 'pull' === $direction ? 'this site' : $plan['to']['home'];
		WP_CLI::confirm( sprintf( 'Overwrite %s?', $target ), $assoc_args );

		$state = RunWP_Migrate_Migration::start( $direction, $connection, $db, $media, array_values( $tables ) );
		if ( is_wp_error( $state ) ) {
			WP_CLI::error( $state->get_error_message() );
		}

		$bar     = \WP_CLI\Utils\make_progress_bar( 'Migrating', 100 );
		$shown   = 0;
		$retries = 0;

		while ( 'done' !== $state['phase'] ) {
			$next = RunWP_Migrate_Migration::step();

			if ( is_wp_error( $next ) ) {
				if ( $retries++ < 3 ) {
					WP_CLI::warning( $next->get_error_message() . ' Retrying …' );
					sleep( 3 );
					continue;
				}
				$bar->finish();
				WP_CLI::error( $next->get_error_message() . ' Run the command again to start over, or `wp runwp-migrate cancel` to clean up.' );
			}

			$retries = 0;
			$state   = $next;
			$percent = RunWP_Migrate_Migration::percent( $state );
			$bar->tick( $percent - $shown );
			$shown = $percent;
		}

		$bar->tick( 100 - $shown );
		$bar->finish();

		if ( $db && 'pull' === $direction ) {
			WP_CLI::success( 'Done. Users come from the other site now; log in with an account from there.' );
		} else {
			WP_CLI::success( 'Done.' );
		}
	}

	/**
	 * Abandon a migration that stopped half way, and remove its temporary tables.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cancel( $args, $assoc_args ) {
		RunWP_Migrate_Migration::cancel();
		WP_CLI::success( 'Cancelled.' );
	}

	/**
	 * Put back the tables the last migration replaced.
	 *
	 * ## OPTIONS
	 *
	 * [<connection>]
	 * : Restore a connected site instead of this one (undo a push).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function restore( $args, $assoc_args ) {
		$connection = isset( $args[0] ) ? $args[0] : null;
		WP_CLI::confirm( null === $connection ? 'Restore this site to before the last migration?' : sprintf( 'Restore %s to before the last push?', $connection ), $assoc_args );

		$result = RunWP_Migrate_Migration::restore( $connection );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Restored %d tables.', (int) $result['tables'] ) );
	}

	/**
	 * Show this site's connection info and what it allows.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function info( $args, $assoc_args ) {
		$lines = explode( "\n", RunWP_Migrate_Settings::connection_info() );
		WP_CLI::log( 'URL:   ' . $lines[0] );
		WP_CLI::log( 'Key:   ' . $lines[1] );
		WP_CLI::log( 'Pull:  ' . ( RunWP_Migrate_Settings::is_allowed( 'pull' ) ? 'allowed' : 'off' ) );
		WP_CLI::log( 'Push:  ' . ( RunWP_Migrate_Settings::is_allowed( 'push' ) ? 'allowed' : 'off' ) );
	}

	/**
	 * Allow other sites to pull from or push to this one.
	 *
	 * ## OPTIONS
	 *
	 * <direction>
	 * : pull or push.
	 * ---
	 * options:
	 *   - pull
	 *   - push
	 * ---
	 *
	 * [--off]
	 * : Switch it off instead.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function allow( $args, $assoc_args ) {
		$on = ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'off', false );
		RunWP_Migrate_Settings::set_allowed( $args[0], $on );
		WP_CLI::success( sprintf( '%s %s.', ucfirst( $args[0] ), $on ? 'allowed' : 'switched off' ) );
	}

	/**
	 * Replace this site's key. Connected sites lose access.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @subcommand regenerate-key
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function regenerate_key( $args, $assoc_args ) {
		WP_CLI::confirm( 'Create a new key? Connected sites lose access.', $assoc_args );
		WP_CLI::log( 'Key:   ' . RunWP_Migrate_Settings::regenerate_key() );
		WP_CLI::success( 'New key created.' );
	}

	/**
	 * Connect another site, after checking that it answers.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name for the connection, e.g. live or staging.
	 *
	 * <url>
	 * : The other site's address.
	 *
	 * <key>
	 * : The other site's key (wp runwp-migrate info, on that site).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function connect( $args, $assoc_args ) {
		$name = RunWP_Migrate_Settings::sanitize_name( $args[0] );
		if ( '' === $name ) {
			WP_CLI::error( 'Give the connection a name of letters, digits and dashes.' );
		}

		$info = RunWP_Migrate_Settings::validate_connection( $args[1], $args[2] );
		if ( is_wp_error( $info ) ) {
			WP_CLI::error( $info->get_error_message() );
		}

		$remote = ( new RunWP_Migrate_Remote( $info['url'], $info['key'] ) )->info();
		if ( is_wp_error( $remote ) ) {
			WP_CLI::error( $remote->get_error_message() );
		}

		RunWP_Migrate_Settings::add_connection( $name, $info['url'], $info['key'] );
		WP_CLI::success(
			sprintf(
				'Connected "%s" (%s). Pull %s, push %s.',
				$name,
				$remote['name'],
				$remote['allow_pull'] ? 'allowed' : 'off',
				$remote['allow_push'] ? 'allowed' : 'off'
			)
		);
	}

	/**
	 * Remove a connection.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Connection name.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function disconnect( $args, $assoc_args ) {
		if ( ! RunWP_Migrate_Settings::connection( $args[0] ) ) {
			WP_CLI::error( sprintf( 'There is no connection called "%s".', $args[0] ) );
		}

		RunWP_Migrate_Settings::remove_connection( $args[0] );
		WP_CLI::success( 'Removed.' );
	}

	/**
	 * List connections.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function connections( $args, $assoc_args ) {
		$items = array();
		foreach ( RunWP_Migrate_Settings::connections() as $name => $connection ) {
			$items[] = array(
				'name'  => $name,
				'url'   => $connection['url'],
				'added' => gmdate( 'Y-m-d', (int) $connection['added'] ),
			);
		}

		\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $items, array( 'name', 'url', 'added' ) );
	}
}
