<?php
/**
 * Requests alpha must refuse. Run on beta, which holds alpha's key.
 *
 * @package RunWP_Migrate
 */

require __DIR__ . '/lib.php';

$connection = RunWP_Migrate_Settings::connection( 'alpha' );
$base       = $connection['url'];
$key        = $connection['key'];

/**
 * @return array{0:int, 1:array|null}
 */
function rwm_e2e_send( $base, $route, $payload, $headers ) {
	$response = wp_remote_post(
		$base . '/?rest_route=/runwp-migrate/v1/' . $route,
		array(
			'headers'   => $headers + array( 'Content-Type' => 'application/json' ),
			'body'      => wp_json_encode( $payload ),
			'sslverify' => false,
			'timeout'   => 30,
		)
	);
	return array( (int) wp_remote_retrieve_response_code( $response ), json_decode( wp_remote_retrieve_body( $response ), true ) );
}

/**
 * @return array Signed headers.
 */
function rwm_e2e_sign( $key, $route, $payload, $timestamp = null ) {
	$path      = '/runwp-migrate/v1/' . $route;
	$timestamp = $timestamp ? $timestamp : time();
	$nonce     = wp_generate_password( 32, false, false );
	return array(
		'X-RunWP-Migrate-Timestamp' => (string) $timestamp,
		'X-RunWP-Migrate-Nonce'     => $nonce,
		'X-RunWP-Migrate-Signature' => RunWP_Migrate_Auth::sign( $key, RunWP_Migrate_Auth::canonical( 'POST', $path, $timestamp, $nonce, wp_json_encode( $payload ) ) ),
	);
}

list( $code ) = rwm_e2e_send( $base, 'info', array(), array() );
rwm_e2e_check( 'unsigned → 401', 401 === $code, (string) $code );

list( $code, $body ) = rwm_e2e_send( $base, 'info', array(), rwm_e2e_sign( RunWP_Migrate_Settings::generate_key(), 'info', array() ) );
rwm_e2e_check( 'wrong key → 401', 401 === $code && 'runwp_migrate_signature' === $body['code'], (string) $code );

list( $code ) = rwm_e2e_send( $base, 'info', array(), rwm_e2e_sign( $key, 'info', array(), time() - 1000 ) );
rwm_e2e_check( 'stale timestamp → 401', 401 === $code, (string) $code );

$headers       = rwm_e2e_sign( $key, 'info', array() );
list( $first ) = rwm_e2e_send( $base, 'info', array(), $headers );
list( $again ) = rwm_e2e_send( $base, 'info', array(), $headers );
rwm_e2e_check( 'replayed request → 401', 200 === $first && 401 === $again, $first . '/' . $again );

list( $code ) = rwm_e2e_send( $base, 'db/begin', array(), rwm_e2e_sign( $key, 'info', array() ) );
rwm_e2e_check( 'signature bound to the route', 401 === $code, (string) $code );

$headers = rwm_e2e_sign( $key, 'db/export', array( 'table' => 'al_posts' ) );
list( $code ) = rwm_e2e_send( $base, 'db/export', array( 'table' => 'al_users' ), $headers );
rwm_e2e_check( 'signature bound to the body', 401 === $code, (string) $code );

$payload = array( 'items' => array( array( 'path' => '../../wp-config.php', 'offset' => 0, 'length' => 100 ) ) );
list( $code ) = rwm_e2e_send( $base, 'files/read', $payload, rwm_e2e_sign( $key, 'files/read', $payload ) );
rwm_e2e_check( 'read outside uploads → 403', 403 === $code, (string) $code );

$payload = array( 'items' => array( array( 'path' => 'files/shell.php', 'offset' => 0, 'data' => base64_encode( '<?php' ), 'size' => 5, 'mtime' => time(), 'final' => true ) ) );
list( $code ) = rwm_e2e_send( $base, 'files/write', $payload, rwm_e2e_sign( $key, 'files/write', $payload ) );
rwm_e2e_check( 'php upload → 403', 403 === $code, (string) $code );

$payload = array( 'items' => array( array( 'path' => 'files/../../../../x.txt', 'offset' => 0, 'data' => base64_encode( 'x' ), 'size' => 1, 'mtime' => time(), 'final' => true ) ) );
list( $code ) = rwm_e2e_send( $base, 'files/write', $payload, rwm_e2e_sign( $key, 'files/write', $payload ) );
rwm_e2e_check( 'write outside uploads → 403', 403 === $code, (string) $code );

$payload = array( 'table' => 'al_posts` WHERE 1=1; DROP TABLE al_users; --', 'cursor' => null, 'pairs' => array(), 'max_bytes' => 100000 );
list( $code ) = rwm_e2e_send( $base, 'db/export', $payload, rwm_e2e_sign( $key, 'db/export', $payload ) );
rwm_e2e_check( 'sql in table name → 404', 404 === $code, (string) $code );

$payload = array( 'table' => 'xx_posts', 'create' => 'CREATE TABLE `x` (id INT)', 'source_prefix' => 'be_' );
list( $code ) = rwm_e2e_send( $base, 'db/create', $payload, rwm_e2e_sign( $key, 'db/create', $payload ) );
rwm_e2e_check( 'table outside its prefix refused', $code >= 400, (string) $code );

$payload = array( 'table' => 'al_evil', 'create' => 'DROP TABLE al_users', 'source_prefix' => 'be_' );
list( $code ) = rwm_e2e_send( $base, 'db/create', $payload, rwm_e2e_sign( $key, 'db/create', $payload ) );
rwm_e2e_check( 'statement other than CREATE TABLE refused', $code >= 400, (string) $code );

rwm_e2e_done();
