<?php
/**
 * Media comparison and cancelling, run on beta against alpha.
 *
 * @package RunWP_Migrate
 */

require __DIR__ . '/lib.php';

global $wpdb;

$state = RunWP_Migrate_Migration::start( 'pull', 'alpha', false, true );
$state = RunWP_Migrate_Migration::step();
rwm_e2e_check( 'media in sync: nothing to copy', is_array( $state ) && 'done' === $state['phase'] );

// One unit of work at a time, past the time budget step() uses.
$advance = new ReflectionMethod( 'RunWP_Migrate_Migration', 'advance' );
$advance->setAccessible( true );
$saved  = RunWP_Migrate_Settings::connection( 'alpha' );
$source = new RunWP_Migrate_Remote( $saved['url'], $saved['key'] );
$dest   = new RunWP_Migrate_Local();

touch( str_replace( '/beta/', '/alpha/', wp_get_upload_dir()['basedir'] ) . '/files/big.pdf', time() - 60 );
$state          = RunWP_Migrate_Migration::start( 'pull', 'alpha', false, true );
$state['phase'] = 'files_scan';
$advance->invokeArgs( null, array( &$state, $source, $dest ) );
rwm_e2e_check( 'changed file is the only one queued', 1 === count( $state['files']['queue'] ) && 'files/big.pdf' === $state['files']['queue'][0][0], wp_json_encode( array_column( $state['files']['queue'], 0 ) ) );
RunWP_Migrate_Migration::cancel();

$state = RunWP_Migrate_Migration::start( 'pull', 'alpha', true, false );
$advance->invokeArgs( null, array( &$state, $source, $dest ) );
rwm_e2e_check( 'temporary tables while running', (bool) $wpdb->get_col( "SHOW TABLES LIKE '\\_rwt\\_%'" ) );
rwm_e2e_check( 'site untouched while running', 'beta' === basename( get_option( 'home' ) ) );
RunWP_Migrate_Migration::cancel();
rwm_e2e_check( 'cancel drops temporary tables', ! $wpdb->get_col( "SHOW TABLES LIKE '\\_rwt\\_%'" ) );
rwm_e2e_check( 'cancel clears the state', false === get_option( RunWP_Migrate_Settings::OPT_RUN ) );

rwm_e2e_done();
