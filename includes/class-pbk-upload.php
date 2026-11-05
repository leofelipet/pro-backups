<?php
/**
 * Chunked, resumable upload: no PHP upload_max_filesize / post_max_size limit on the backup size.
 * Each chunk is a normal small POST; chunks are appended to "<name>.wpress.part" and renamed when complete.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- chunked upload appends to a file with native handles + flock(); messages are returned as JSON.

class PBK_Upload {
	const MAX_CHUNK = 5242880; // 5 MB

	public static function init() {
		foreach ( array( 'start', 'chunk', 'status' ) as $a ) {
			add_action( "wp_ajax_pbk_upload_$a", array( __CLASS__, "ajax_$a" ) );
		}
	}

	/** Largest chunk the server accepts in one POST, with room for the multipart overhead. */
	public static function chunk_size() {
		$max = (int) wp_max_upload_size();
		return (int) max( 262144, min( self::MAX_CHUNK, $max > 0 ? $max - 131072 : self::MAX_CHUNK ) );
	}

	private static function manifest_path( $id ) {
		return PBK_Storage::dir() . "/uploads/$id.php";
	}

	private static function manifest( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{16}$/', $id ) || ! is_file( self::manifest_path( $id ) ) ) {
			throw new PBK_Exception( __( 'Upload not found or expired. Start again.', 'pro-backups' ) );
		}
		return json_decode( substr( file_get_contents( self::manifest_path( $id ) ), strlen( PBK_Job::GUARD ) ), true );
	}

	public static function start( $name, $size ) {
		$name = PBK_Storage::sanitize_name( $name );
		$size = (int) $size;
		if ( $size < PBK_Archive::HEADER_SIZE ) {
			throw new PBK_Exception( __( 'The file is empty or too small to be a backup.', 'pro-backups' ) );
		}
		$free = PBK_Storage::free_space();
		if ( $free !== null && $free < $size ) {
			/* translators: 1: needed, 2: free */
			throw new PBK_Exception( sprintf( __( 'Not enough disk space: the file needs %1$s, only %2$s free.', 'pro-backups' ), size_format( $size ), size_format( $free ) ) );
		}
		wp_mkdir_p( PBK_Storage::dir() . '/uploads' );
		$id   = bin2hex( random_bytes( 8 ) );
		$path = PBK_Storage::unique_path( $name );
		if ( file_put_contents( $path . '.part', '' ) === false ) {
			throw new PBK_Exception( __( 'Could not write to the backups folder.', 'pro-backups' ) );
		}
		file_put_contents( self::manifest_path( $id ), PBK_Job::GUARD . wp_json_encode( array( 'path' => $path, 'size' => $size ) ) );
		return array( 'upload_id' => $id, 'offset' => 0, 'chunk_size' => self::chunk_size(), 'file' => basename( $path ) );
	}

	/**
	 * Writes one chunk at $offset. A repeated chunk (client retry) overwrites; a gap is refused.
	 *
	 * @return array offset (bytes stored), done, file
	 */
	public static function chunk( $id, $offset, $tmp_file ) {
		$m    = self::manifest( $id );
		$part = $m['path'] . '.part';
		clearstatcache( true, $part );
		$have = is_file( $part ) ? filesize( $part ) : 0;
		$offset = (int) $offset;
		if ( $offset > $have || $offset < 0 ) {
			return array( 'offset' => $have, 'done' => false, 'file' => basename( $m['path'] ), 'resync' => true );
		}
		$data = file_get_contents( $tmp_file );
		if ( $data === false || $data === '' || $offset + strlen( $data ) > $m['size'] ) {
			throw new PBK_Exception( __( 'Invalid upload chunk.', 'pro-backups' ) );
		}
		$fh = fopen( $part, 'cb' );
		if ( ! $fh || ! flock( $fh, LOCK_EX ) ) {
			throw new PBK_Exception( __( 'Could not write to the backups folder.', 'pro-backups' ) );
		}
		ftruncate( $fh, $offset );
		fseek( $fh, $offset );
		$written = fwrite( $fh, $data );
		fflush( $fh );
		flock( $fh, LOCK_UN );
		fclose( $fh );
		if ( $written !== strlen( $data ) ) {
			throw new PBK_Exception( __( 'Out of disk space while uploading.', 'pro-backups' ) );
		}
		$offset += $written;
		$done    = $offset === (int) $m['size'];
		if ( $done ) {
			if ( ! rename( $part, $m['path'] ) ) {
				throw new PBK_Exception( __( 'Could not finish the upload.', 'pro-backups' ) );
			}
			@unlink( self::manifest_path( $id ) );
		}
		return array( 'offset' => $offset, 'done' => $done, 'file' => basename( $m['path'] ) );
	}

	public static function status( $id ) {
		$m    = self::manifest( $id );
		$part = $m['path'] . '.part';
		clearstatcache( true, $part );
		return array( 'offset' => is_file( $part ) ? filesize( $part ) : 0, 'done' => false, 'file' => basename( $m['path'] ) );
	}

	/** Removes .part files and manifests of uploads abandoned for a day. */
	public static function cleanup() {
		foreach ( (array) glob( PBK_Storage::dir() . '/uploads/*.php' ) as $file ) {
			if ( time() - filemtime( $file ) > DAY_IN_SECONDS ) {
				$m = json_decode( substr( file_get_contents( $file ), strlen( PBK_Job::GUARD ) ), true );
				if ( ! empty( $m['path'] ) && is_file( $m['path'] . '.part' ) && time() - filemtime( $m['path'] . '.part' ) > DAY_IN_SECONDS ) {
					@unlink( $m['path'] . '.part' );
				}
				@unlink( $file );
			}
		}
	}

	private static function respond( callable $fn ) {
		PBK_Plugin::guard();
		try {
			wp_send_json_success( $fn() );
		} catch ( PBK_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), $e->getCode() ?: 400 );
		}
	}

	public static function ajax_start() {
		self::respond( function () {
			return self::start( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', isset( $_POST['size'] ) ? (int) $_POST['size'] : 0 ); // phpcs:ignore WordPress.Security
		} );
	}

	public static function ajax_chunk() {
		self::respond( function () {
			if ( empty( $_FILES['chunk']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK ) { // phpcs:ignore WordPress.Security
				throw new PBK_Exception( __( 'The chunk did not arrive. Retrying...', 'pro-backups' ), 503 );
			}
			return self::chunk( sanitize_key( wp_unslash( $_POST['upload_id'] ?? '' ) ), (int) ( $_POST['offset'] ?? 0 ), $_FILES['chunk']['tmp_name'] ); // phpcs:ignore WordPress.Security
		} );
	}

	public static function ajax_status() {
		self::respond( function () {
			return self::status( sanitize_key( wp_unslash( $_POST['upload_id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security
		} );
	}
}
