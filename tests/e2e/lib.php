<?php
/**
 * Assertions for the end-to-end scripts. Each script ends with
 * rwm_e2e_done(), which fails the wp-cli call if anything failed.
 *
 * @package RunWP_Migrate
 */

$GLOBALS['rwm_e2e_failed'] = 0;

/**
 * @param string $label  What is checked.
 * @param bool   $ok     Outcome.
 * @param string $detail Shown on failure.
 */
function rwm_e2e_check( $label, $ok, $detail = '' ) {
	if ( ! $ok ) {
		++$GLOBALS['rwm_e2e_failed'];
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( $ok || '' === $detail ? '' : '  → ' . $detail ) );
}

/**
 * Fail the command if any check failed.
 */
function rwm_e2e_done() {
	if ( $GLOBALS['rwm_e2e_failed'] ) {
		WP_CLI::error( $GLOBALS['rwm_e2e_failed'] . ' check(s) failed.' );
	}
}
