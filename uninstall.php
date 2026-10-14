<?php
/**
 * Removing the plugin removes what it kept: its key, its connections (which
 * hold other sites' keys), and the working and backup tables of the last
 * migration into this site.
 *
 * @package RunWP_Migrate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'runwp_migrate_key', 'runwp_migrate_allow_pull', 'runwp_migrate_allow_push', 'runwp_migrate_connections', 'runwp_migrate_backup', 'runwp_migrate_run', 'runwp_migrate_nonces' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
foreach ( array( '_rwt_', '_rwb_' ) as $prefix ) {
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
