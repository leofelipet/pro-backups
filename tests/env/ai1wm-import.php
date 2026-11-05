<?php
/**
 * Restores a .wpress with the real All-in-One WP Migration pipeline (wp eval-file), to prove
 * Pro Backups archives are compatible. Usage: wp eval-file ai1wm-import.php <name-in-ai1wm-backups>
 * Encrypted archives: AI1WM reads the password from stdin (readline).
 */
$params = array(
	'secret_key' => get_option( AI1WM_SECRET_KEY ),
	'archive'    => $args[0],
	'storage'    => 'compat-' . wp_generate_password( 8, false ),
	'cli_args'   => array( 'yes' => true ),
	'ai1wm_manual_restore' => 1, // read from ai1wm-backups instead of the upload storage
	'priority'   => 10,
);
Ai1wm_Import_Controller::import( $params );
WP_CLI::success( 'ai1wm import finished' );
