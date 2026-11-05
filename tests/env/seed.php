<?php
/**
 * Seeds the src site with content that exercises the restore: URLs in post content,
 * serialized options/meta containing URLs and paths, a media file, a custom table.
 * Usage: wp eval-file seed.php
 */
global $wpdb;

$url = home_url();

$post_id = wp_insert_post(
	array(
		'post_title'   => 'PBK Fixture Post',
		'post_content' => '<a href="' . $url . '/hello/">link</a> <img src="' . $url . '/wp-content/uploads/pbk-fixture.txt"> json:' . wp_json_encode( array( 'u' => $url ) ),
		'post_status'  => 'publish',
	)
);
update_post_meta( $post_id, 'fx_serialized', array( 'url' => $url, 'nested' => array( 'path' => WP_CONTENT_DIR ) ) );
update_option( 'fx_option', array( 'home' => $url, 'list' => array( $url . '/a', $url . '/b' ) ) );
update_option( 'fx_marker', 'restored-from-src' );

$upload = wp_upload_dir();
file_put_contents( $upload['basedir'] . '/pbk-fixture.txt', 'pbk fixture media' );

$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fx_custom (id INT AUTO_INCREMENT PRIMARY KEY, v LONGTEXT) DEFAULT CHARSET=utf8mb4" );
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}fx_custom (v) VALUES (%s), (%s)", "line1\nline2 ; with semicolon;\n", $url ) );

WP_CLI::success( "seeded post $post_id" );
