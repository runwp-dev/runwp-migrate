<?php
/**
 * Alpha after beta undid its push.
 *
 * @package RunWP_Migrate
 */

require __DIR__ . '/lib.php';

rwm_e2e_check( 'pushed post gone again', ! get_page_by_path( 'pushed-from-beta', OBJECT, 'post' ) );
rwm_e2e_check( 'seeded post still there', (bool) get_page_by_path( 'links', OBJECT, 'post' ) );
rwm_e2e_check( 'backup consumed', null === RunWP_Migrate_Database::backup_info() );
rwm_e2e_check( 'still reachable: key kept', getenv( 'RWM_ALPHA_KEY' ) === RunWP_Migrate_Settings::key() );

rwm_e2e_done();
