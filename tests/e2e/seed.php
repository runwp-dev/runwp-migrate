<?php
/**
 * Content for the source site ("alpha"): every kind of value the
 * replacement and the transfer have to get right.
 *
 * @package RunWP_Migrate
 */

global $wpdb;
$p    = $wpdb->prefix;
$home = home_url();
$esc  = str_replace( '/', '\/', $home );

update_option(
	'rwm_test_serialized',
	array(
		'url'    => $home . '/kontakt/',
		'path'   => ABSPATH . 'wp-content/uploads/x.txt',
		'nested' => serialize( array( 'u' => $home, 'deep' => serialize( array( 'z' => $home . '/deep' ) ) ) ),
		'json'   => wp_json_encode( array( 'link' => $home . '/json/' ) ),
		'object' => (object) array( 'href' => $home . '/obj' ),
	)
);
update_option( 'rwm_test_encoded', 'redirect_to=' . rawurlencode( $home . '/after/' ) );

$id = wp_insert_post(
	array(
		'post_title'   => 'Links',
		'post_status'  => 'publish',
		'post_content' => '<a href="' . $home . '/page/">abs</a> <a href="//' . wp_parse_url( $home, PHP_URL_HOST ) . wp_parse_url( $home, PHP_URL_PATH ) . '/rel">rel</a> <a href="http://' . wp_parse_url( $home, PHP_URL_HOST ) . wp_parse_url( $home, PHP_URL_PATH ) . '/old">http</a>',
	)
);

// Written past WordPress, which would strip the backslashes and refuse
// invalid UTF-8: JSON-escaped URLs and raw bytes as plugins store them.
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}postmeta (post_id, meta_key, meta_value) VALUES (%d, '_rwm_raw_json', %s)", $id, '{"url":"' . $esc . '\/page"}' ) );
$wpdb->query( "CREATE TABLE {$p}blob ( id INT PRIMARY KEY, data LONGBLOB ) ENGINE=InnoDB" );
$bytes = "\xff\xfe\x00bin\x80ary" . random_bytes( 3000 );
file_put_contents( '/var/www/html/e2e-blob.bin', $bytes );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$p}blob VALUES (1, %s)", $bytes ) );

$editor = wp_create_user( 'editor1', wp_generate_password(), 'editor1@alpha.test' );
( new WP_User( $editor ) )->set_role( 'editor' );

// A foreign key, and a table without primary key paged by offset.
$wpdb->query( "CREATE TABLE {$p}parent ( id INT PRIMARY KEY, name VARCHAR(20) ) ENGINE=InnoDB" );
$wpdb->query( "CREATE TABLE {$p}child ( id INT PRIMARY KEY, parent_id INT, CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES {$p}parent (id) ) ENGINE=InnoDB" );
$wpdb->query( "INSERT INTO {$p}parent VALUES (1,'a'),(2,'b')" );
$wpdb->query( "INSERT INTO {$p}child VALUES (10,1),(11,2)" );
$wpdb->query( "CREATE TABLE {$p}nopk ( a INT, b TEXT ) ENGINE=InnoDB" );
for ( $i = 0; $i < 450; $i++ ) {
	$wpdb->insert( $p . 'nopk', array( 'a' => $i, 'b' => $home . '/n/' . $i ) );
}

WP_CLI::success( 'Seeded.' );
