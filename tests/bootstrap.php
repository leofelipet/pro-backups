<?php
// Unit tests run without WordPress: the core classes only need ABSPATH and __().
define( 'ABSPATH', sys_get_temp_dir() . '/' );
function __( $text ) {
	return $text;
}
foreach ( glob( dirname( __DIR__ ) . '/includes/class-pbk-*.php' ) as $file ) {
	require_once $file;
}
