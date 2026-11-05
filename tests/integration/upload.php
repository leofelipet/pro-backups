<?php
/**
 * Upload resume semantics (wp eval-file): duplicate chunk overwrite, gap -> resync, finish -> rename.
 */
function pbk_assert( $cond, $msg ) {
	if ( ! $cond ) {
		WP_CLI::error( 'FAIL: ' . $msg );
	}
}
$data  = random_bytes( 3 * 1000000 + 12345 );
$size  = strlen( $data );
$u     = PBK_Upload::start( 'resume-test.wpress', $size );
$chunk = 1000000;
$tmp   = tempnam( sys_get_temp_dir(), 'c' );
$send  = function ( $offset ) use ( $data, $chunk, $u, $tmp ) {
	file_put_contents( $tmp, substr( $data, $offset, $chunk ) );
	return PBK_Upload::chunk( $u['upload_id'], $offset, $tmp );
};
$r = $send( 0 );
pbk_assert( $r['offset'] === $chunk && ! $r['done'], 'first chunk' );
$r = $send( 0 ); // retry of the same chunk (response was lost)
pbk_assert( $r['offset'] === $chunk, 'duplicate chunk overwrites' );
$r = $send( 3 * $chunk ); // gap
pbk_assert( ! empty( $r['resync'] ) && $r['offset'] === $chunk, 'gap refused with resync' );
pbk_assert( PBK_Upload::status( $u['upload_id'] )['offset'] === $chunk, 'status reports offset' );
for ( $o = $chunk; ! $r['done'] || $o === $chunk; $o = $r['offset'] ) {
	$r = $send( $o );
}
$path = PBK_Storage::find( $r['file'] );
pbk_assert( md5_file( $path ) === md5( $data ), 'content intact' );
pbk_assert( ! file_exists( $path . '.part' ), 'part renamed' );
try {
	PBK_Upload::start( '../evil.php', 99999 );
	pbk_assert( false, 'non-wpress accepted' );
} catch ( PBK_Exception $e ) {
	pbk_assert( strpos( $e->getMessage(), '.wpress' ) !== false, 'non-wpress refused' );
}
unlink( $path );
WP_CLI::success( 'upload resume OK' );
