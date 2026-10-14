<?php
/**
 * The source site ("alpha") after beta pushed to it.
 *
 * @package RunWP_Migrate
 */

require __DIR__ . '/lib.php';

$post = get_page_by_path( 'pushed-from-beta', OBJECT, 'post' );
rwm_e2e_check( 'pushed post arrived', (bool) $post );
rwm_e2e_check( 'its link rewritten to alpha', $post && false !== strpos( $post->post_content, home_url( '/from-beta' ) ), $post ? $post->post_content : '' );
rwm_e2e_check( 'alpha keeps its address', 'alpha' === basename( get_option( 'home' ) ) );
rwm_e2e_check( 'alpha keeps its key', getenv( 'RWM_ALPHA_KEY' ) === RunWP_Migrate_Settings::key() );
rwm_e2e_check( 'alpha keeps its access switches', RunWP_Migrate_Settings::is_allowed( 'pull' ) && RunWP_Migrate_Settings::is_allowed( 'push' ) );
rwm_e2e_check( 'plugin still active', is_plugin_active( 'runwp-migrate/runwp-migrate.php' ) );
rwm_e2e_check( 'new file arrived', file_exists( wp_get_upload_dir()['basedir'] . '/files/beta-note.txt' ) );
rwm_e2e_check( 'roles under alpha prefix', (bool) get_option( $GLOBALS['wpdb']->prefix . 'user_roles' ) );

rwm_e2e_done();
