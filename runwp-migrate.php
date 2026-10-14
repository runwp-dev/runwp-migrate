<?php
/**
 * Plugin Name:       RunWP Migrate
 * Plugin URI:        https://github.com/runwp-dev/runwp-migrate
 * Description:       Pull and push the database and media library between two WordPress sites, with URL and path replacement that keeps serialized data intact.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            RunWP
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       runwp-migrate
 * Domain Path:       /languages
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

define( 'RUNWP_MIGRATE_VERSION', '1.0.0' );
define( 'RUNWP_MIGRATE_FILE', __FILE__ );
define( 'RUNWP_MIGRATE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Wire format between two installations. Both sides must speak the same one;
 * bump it whenever a request or response changes shape.
 */
define( 'RUNWP_MIGRATE_PROTOCOL', 1 );

require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-settings.php';
require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-auth.php';
require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-replacer.php';
require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-database.php';
require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-files.php';
require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-endpoint.php';
require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-migration.php';
require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-rest.php';
require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-admin.php';

/**
 * Boot the plugin.
 */
function runwp_migrate_init() {
	load_plugin_textdomain( 'runwp-migrate', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	RunWP_Migrate_Rest::instance()->register();

	if ( is_admin() ) {
		RunWP_Migrate_Admin::instance()->register();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once RUNWP_MIGRATE_DIR . 'includes/class-runwp-migrate-cli.php';
		WP_CLI::add_command( 'runwp-migrate', 'RunWP_Migrate_CLI' );
	}
}
add_action( 'plugins_loaded', 'runwp_migrate_init' );

/**
 * A multisite network shares tables between sites in ways a table-by-table
 * copy cannot respect, so refuse it outright rather than half-migrate one.
 */
function runwp_migrate_activate() {
	if ( is_multisite() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'RunWP Migrate does not support multisite networks.', 'runwp-migrate' ),
			esc_html__( 'Plugin activation failed', 'runwp-migrate' ),
			array( 'back_link' => true )
		);
	}

	// Create the key now, so the connection info is ready on first visit.
	RunWP_Migrate_Settings::key();
}
register_activation_hook( __FILE__, 'runwp_migrate_activate' );

/**
 * A deactivated plugin cannot answer requests anyway; switch both directions
 * off so reactivating it does not silently reopen the site.
 */
function runwp_migrate_deactivate() {
	RunWP_Migrate_Settings::set_allowed( 'pull', false );
	RunWP_Migrate_Settings::set_allowed( 'push', false );
	delete_option( RunWP_Migrate_Settings::OPT_RUN );
}
register_deactivation_hook( __FILE__, 'runwp_migrate_deactivate' );
