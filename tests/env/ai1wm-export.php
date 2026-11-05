<?php
/**
 * Drives the real All-in-One WP Migration export pipeline in-process (wp eval-file).
 * Usage: wp eval-file ai1wm-export.php <archive-name> [gzip|bzip2|none] [password|-] [small]
 * "small" skips themes and plugins (database + uploads only), for committed unit fixtures.
 *
 * Used only to build compatibility fixtures; AI1WM must be active.
 */
$name        = isset( $args[0] ) ? $args[0] : 'fixture.wpress';
$compression = isset( $args[1] ) && $args[1] !== 'none' ? $args[1] : '';
$password    = isset( $args[2] ) && $args[2] !== '-' ? $args[2] : '';
$small       = isset( $args[3] ) && $args[3] === 'small';

$options = array();
if ( $compression ) {
	$options['compression_type'] = $compression;
}
if ( $password ) {
	$options['encrypt_backups']  = 1;
	$options['encrypt_password'] = $password;
}

if ( $small ) {
	$options['no_themes']  = 1;
	$options['no_plugins'] = 1;
}

$params = array(
	'secret_key' => get_option( AI1WM_SECRET_KEY ),
	'archive'    => $name,
	'storage'    => 'fixture-' . wp_generate_password( 8, false ),
	'options'    => $options,
	'priority'   => 5,
);

Ai1wm_Export_Controller::export( $params );

$file = AI1WM_BACKUPS_PATH . '/' . $name;
WP_CLI::success( $file . ' ' . ( is_file( $file ) ? filesize( $file ) : 'MISSING' ) );
