<?php
/**
 * The target site ("beta") after pulling alpha.
 *
 * @package RunWP_Migrate
 */

require __DIR__ . '/lib.php';

global $wpdb;
$home  = home_url();
$alpha = str_replace( '/beta', '/alpha', $home );
$esc   = str_replace( '/', '\/', $home );

rwm_e2e_check( 'home', 'beta' === basename( get_option( 'home' ) ), get_option( 'home' ) );
rwm_e2e_check( 'siteurl', get_option( 'siteurl' ) === $home, get_option( 'siteurl' ) );
rwm_e2e_check( 'content came from alpha', 'Alpha' === get_option( 'blogname' ), get_option( 'blogname' ) );

$s = get_option( 'rwm_test_serialized' );
rwm_e2e_check( 'serialized option readable', is_array( $s ), var_export( $s, true ) );
rwm_e2e_check( 'serialized url', $home . '/kontakt/' === $s['url'], $s['url'] );
rwm_e2e_check( 'serialized path', ABSPATH . 'wp-content/uploads/x.txt' === $s['path'], $s['path'] );
$n = unserialize( $s['nested'] );
rwm_e2e_check( 'nested serialized', is_array( $n ) && $home === $n['u'], $s['nested'] );
$d = unserialize( $n['deep'] );
rwm_e2e_check( 'doubly nested serialized', is_array( $d ) && $home . '/deep' === $d['z'], $n['deep'] );
rwm_e2e_check( 'json inside serialized', $home . '/json/' === json_decode( $s['json'], true )['link'], $s['json'] );
rwm_e2e_check( 'object property', is_object( $s['object'] ) && $home . '/obj' === $s['object']->href );
rwm_e2e_check( 'url-encoded', 'redirect_to=' . rawurlencode( $home . '/after/' ) === get_option( 'rwm_test_encoded' ), get_option( 'rwm_test_encoded' ) );

$post = get_page_by_path( 'links', OBJECT, 'post' );
rwm_e2e_check( 'post found', (bool) $post );
rwm_e2e_check( 'absolute link', $post && false !== strpos( $post->post_content, $home . '/page/' ), $post ? $post->post_content : '' );
rwm_e2e_check( 'protocol-relative link', $post && false !== strpos( $post->post_content, preg_replace( '#^https?:#', '', $home ) . '/rel' ) );
rwm_e2e_check( 'http link', $post && false !== strpos( $post->post_content, 'http:' . preg_replace( '#^https?:#', '', $home ) . '/old' ) );
rwm_e2e_check( 'no alpha address left', $post && false === strpos( $post->post_content, '/alpha' ) );
rwm_e2e_check( 'guid left alone', $post && false !== strpos( $post->guid, '/alpha' ), $post ? $post->guid : '' );
rwm_e2e_check( 'json-escaped url', '{"url":"' . $esc . '\/page"}' === get_post_meta( $post->ID, '_rwm_raw_json', true ), (string) get_post_meta( $post->ID, '_rwm_raw_json', true ) );
rwm_e2e_check( 'binary blob byte for byte', file_get_contents( '/var/www/html/e2e-blob.bin' ) === $wpdb->get_var( "SELECT data FROM {$wpdb->prefix}blob WHERE id = 1" ) );
rwm_e2e_check( 'beta-only post replaced', ! get_page_by_path( 'beta-only', OBJECT, 'post' ) );
rwm_e2e_check( 'paged by key: 600 generated posts', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post'" ) >= 601 );

$editor = get_user_by( 'login', 'editor1' );
rwm_e2e_check( 'roles follow the table prefix', $editor && in_array( 'editor', $editor->roles, true ) );
rwm_e2e_check( 'admin keeps manage_options', user_can( 1, 'manage_options' ) );

rwm_e2e_check( 'paged by offset: 450 rows', 450 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nopk" ) );
rwm_e2e_check( 'offset rows replaced', $home . '/n/449' === $wpdb->get_var( "SELECT b FROM {$wpdb->prefix}nopk WHERE a = 449" ) );
$fk = $wpdb->get_var( "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$wpdb->prefix}child' AND REFERENCED_TABLE_NAME IS NOT NULL" );
rwm_e2e_check( 'foreign key points at the real table', $wpdb->prefix . 'parent' === $fk, (string) $fk );

rwm_e2e_check( 'plugin still active', is_plugin_active( 'runwp-migrate/runwp-migrate.php' ) );
rwm_e2e_check( 'own connections kept', null !== RunWP_Migrate_Settings::connection( 'alpha' ) );
rwm_e2e_check( 'no run state left', false === get_option( RunWP_Migrate_Settings::OPT_RUN ) );
rwm_e2e_check( 'backup recorded', is_array( RunWP_Migrate_Database::backup_info() ) );
rwm_e2e_check( 'no temporary tables left', ! $wpdb->get_col( "SHOW TABLES LIKE '\\_rwt\\_%'" ) );

$up = wp_get_upload_dir()['basedir'];
rwm_e2e_check( 'large file in several parts', md5_file( '/var/www/html/e2e-big.pdf' ) === md5_file( $up . '/files/big.pdf' ) );
rwm_e2e_check( 'php file refused', ! file_exists( $up . '/files/evil.php' ) );
rwm_e2e_check( 'dotfile refused', ! file_exists( $up . '/files/.hidden' ) );
foreach ( get_posts( array( 'post_type' => 'attachment', 'numberposts' => -1 ) ) as $attachment ) {
	rwm_e2e_check( 'attachment ' . basename( get_attached_file( $attachment->ID ) ), file_exists( get_attached_file( $attachment->ID ) ) && 0 === strpos( wp_get_attachment_url( $attachment->ID ), $home ) );
}
rwm_e2e_check( 'no part files left', ! glob( $up . '/*/*.rwm-part' ) );

rwm_e2e_done();
