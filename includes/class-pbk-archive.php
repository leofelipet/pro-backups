<?php
/**
 * .wpress archive format (compatible with All-in-One WP Migration).
 *
 * Layout: for every file a 4377-byte header followed by its stored content, then an EOF block.
 *   header = name a255 | size a14 (stored bytes) | mtime a12 | path a4088 | crc32 a8 (of original bytes)
 *   EOF v1 = 4377 NUL bytes; EOF v2 = a255 '' | a14 archive-size | a4100 '' | a8 archive-crc32
 * Content of every file except root package.json / multisite.json is stored in chunks:
 *   - compression: [uint32 BE length][chunk]   chunk = (encrypt?)(gz|bz compress(512000 plain bytes))
 *   - encryption only: encrypt(512000 plain bytes) => 512032 stored bytes (last chunk smaller)
 *   - encrypt(x) = iv(16) . AES-256-CBC(x, key = substr(sha1(password, raw), 0, 16))
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- streaming multi-GB archives needs native file handles (WP_Filesystem cannot stream); exception messages are escaped where they are displayed.

class PBK_Exception extends RuntimeException {}

class PBK_Archive {
	const HEADER_SIZE = 4377;
	const CHUNK_SIZE  = 512000;
	const CIPHER      = 'AES-256-CBC';
	const HEADER_PACK = 'a255a14a12a4088a8';
	const SIGN_TEXT   = '"How long do you want these messages to remain secret? I want them to remain secret for as long as men are capable of evil." - Neal Stephenson';

	/** Files AI1WM never compresses/encrypts. */
	const RAW_FILES = array( 'package.json', 'multisite.json' );

	public static function eof_block( $size = '', $crc = '' ) {
		return pack( 'a255a14a4100a8', '', $size, '', $crc );
	}

	/** @return array|false ['size'=>int,'crc'=>string] for v2, [] for v1, false if not EOF. */
	public static function parse_eof( $block ) {
		if ( strlen( $block ) !== self::HEADER_SIZE ) {
			return false;
		}
		if ( $block === self::eof_block() ) {
			return array();
		}
		$d = unpack( 'a255name/a14size/a4100pad/a8crc', $block );
		if ( $d && preg_match( '/^[0-9a-f]{8}$/i', $d['crc'] ) && self::eof_block( $d['size'], $d['crc'] ) === $block ) {
			return array( 'size' => (int) trim( $d['size'] ), 'crc' => strtolower( $d['crc'] ) );
		}
		return false;
	}

	public static function encrypt( $data, $password ) {
		$iv = openssl_random_pseudo_bytes( 16 );
		$out = openssl_encrypt( $data, self::CIPHER, self::key( $password ), OPENSSL_RAW_DATA, $iv );
		if ( $out === false ) {
			throw new PBK_Exception( 'Could not encrypt data.' );
		}
		return $iv . $out;
	}

	public static function decrypt( $data, $password ) {
		$out = openssl_decrypt( substr( $data, 16 ), self::CIPHER, self::key( $password ), OPENSSL_RAW_DATA, substr( $data, 0, 16 ) );
		if ( $out === false ) {
			throw new PBK_Exception( 'Could not decrypt data. Wrong password or corrupted backup.' );
		}
		return $out;
	}

	private static function key( $password ) {
		return substr( sha1( $password, true ), 0, 16 );
	}

	/** package.json "EncryptedSignature" check. */
	public static function password_matches( $signature, $password ) {
		try {
			return self::decrypt( base64_decode( $signature ), $password ) === self::SIGN_TEXT;
		} catch ( PBK_Exception $e ) {
			return false;
		}
	}

	public static function signature( $password ) {
		return base64_encode( self::encrypt( self::SIGN_TEXT, $password ) );
	}

	/**
	 * Normalizes an archive entry path and rejects traversal / absolute paths.
	 *
	 * @throws PBK_Exception
	 */
	public static function safe_path( $name ) {
		$name = str_replace( '\\', '/', $name );
		if ( $name === '' || strpos( $name, "\0" ) !== false || $name[0] === '/' || preg_match( '#^[a-z]+:#i', $name ) ) {
			throw new PBK_Exception( 'Unsafe path in archive: ' . $name );
		}
		$parts = array();
		foreach ( explode( '/', $name ) as $part ) {
			if ( $part === '..' ) {
				throw new PBK_Exception( 'Unsafe path in archive: ' . $name );
			}
			if ( $part !== '' && $part !== '.' ) {
				$parts[] = $part;
			}
		}
		if ( ! $parts ) {
			throw new PBK_Exception( 'Unsafe path in archive: ' . $name );
		}
		return implode( '/', $parts );
	}

	public static function compress( $data, $type ) {
		return $type === 'bzip2' ? bzcompress( $data, 9 ) : gzcompress( $data, 9 );
	}

	public static function decompress( $data, $type ) {
		$out = $type === 'bzip2' ? bzdecompress( $data ) : gzuncompress( $data );
		if ( ! is_string( $out ) ) {
			throw new PBK_Exception( 'Could not decompress data. The backup is corrupted.' );
		}
		return $out;
	}

	public static function check_support( $compression, $password ) {
		if ( PHP_INT_SIZE < 8 ) {
			throw new PBK_Exception( '64-bit PHP is required.' );
		}
		if ( $compression === 'gzip' && ! function_exists( 'gzuncompress' ) ) {
			throw new PBK_Exception( 'This backup is gzip-compressed but the PHP zlib extension is missing.' );
		}
		if ( $compression === 'bzip2' && ! function_exists( 'bzdecompress' ) ) {
			throw new PBK_Exception( 'This backup is bzip2-compressed but the PHP bz2 extension is missing.' );
		}
		if ( $password !== null && $password !== '' && ! function_exists( 'openssl_decrypt' ) ) {
			throw new PBK_Exception( 'This backup is encrypted but the PHP openssl extension is missing.' );
		}
	}
}

/**
 * Streaming, resumable reader.
 */
class PBK_Archive_Reader {
	private $file;
	private $handle;
	private $password;
	private $compression;
	private $size;

	public function __construct( $file, $password = null, $compression = null ) {
		$this->file        = $file;
		$this->password    = ( $password === '' ) ? null : $password;
		$this->compression = $compression ?: null;
		$this->handle      = @fopen( $file, 'rb' );
		if ( ! $this->handle ) {
			throw new PBK_Exception( 'Could not open backup file: ' . basename( $file ) );
		}
		$this->size = filesize( $file );
		PBK_Archive::check_support( $this->compression, $this->password );
	}

	public function __destruct() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	public function size() {
		return $this->size;
	}

	/** @return array|false see PBK_Archive::parse_eof() */
	public function eof() {
		if ( $this->size < PBK_Archive::HEADER_SIZE ) {
			return false;
		}
		fseek( $this->handle, -PBK_Archive::HEADER_SIZE, SEEK_END );
		return PBK_Archive::parse_eof( fread( $this->handle, PBK_Archive::HEADER_SIZE ) );
	}

	/**
	 * Reads the header at $offset. Returns null at EOF.
	 *
	 * @return array|null ['name','size','mtime','crc','offset','data_offset']
	 */
	public function header_at( $offset ) {
		if ( fseek( $this->handle, $offset ) === -1 ) {
			throw new PBK_Exception( 'Could not seek in backup file.' );
		}
		$block = fread( $this->handle, PBK_Archive::HEADER_SIZE );
		if ( $block === false || strlen( $block ) !== PBK_Archive::HEADER_SIZE ) {
			throw new PBK_Exception( 'Unexpected end of backup file. The upload may be incomplete.' );
		}
		if ( PBK_Archive::parse_eof( $block ) !== false ) {
			return null;
		}
		$d = unpack( 'a255name/a14size/a12mtime/a4088path/a8crc', $block );
		$name = trim( $d['name'] );
		$path = trim( $d['path'] );
		$size = trim( $d['size'] );
		if ( $name === '' || ! ctype_digit( $size ) ) {
			throw new PBK_Exception( 'Invalid file header in backup at offset ' . $offset . '.' );
		}
		$full = ( $path === '' || $path === '.' ) ? $name : $path . '/' . $name;
		return array(
			'name'        => PBK_Archive::safe_path( $full ),
			'size'        => (int) $size,
			'mtime'       => (int) trim( $d['mtime'] ),
			'crc'         => trim( $d['crc'] ),
			'offset'      => $offset,
			'data_offset' => $offset + PBK_Archive::HEADER_SIZE,
		);
	}

	/** Iterates all headers (seeking over content). */
	public function entries() {
		$offset = 0;
		while ( ( $h = $this->header_at( $offset ) ) !== null ) {
			yield $h;
			$offset = $h['data_offset'] + $h['size'];
		}
	}

	/** Reads a whole (small) file by name; null when missing. */
	public function read_file( $name ) {
		foreach ( $this->entries() as $h ) {
			if ( $h['name'] === $name ) {
				$state = array( 'offset' => $h['offset'] );
				$out   = '';
				$this->extract_entry( $h, $state, function ( $data ) use ( &$out ) {
					$out .= $data;
				}, INF );
				return $out;
			}
		}
		return null;
	}

	/**
	 * Extracts files, resumable across requests.
	 *
	 * $state keys: offset (header of current entry), stored (stored bytes consumed of current entry),
	 * written (plain bytes written of current entry).
	 * $target( array $entry ) returns an absolute destination path, or null to skip the entry.
	 *
	 * @return bool true when the archive is fully processed, false when $deadline was hit.
	 */
	public function extract( array &$state, callable $target, $deadline ) {
		$state += array( 'offset' => 0, 'stored' => 0, 'written' => 0 );
		while ( ( $h = $this->header_at( $state['offset'] ) ) !== null ) {
			$dest = $target( $h );
			if ( $dest === null ) {
				$state['offset'] = $h['data_offset'] + $h['size'];
				$state['stored'] = 0;
				continue;
			}
			$dir = dirname( $dest );
			if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				throw new PBK_Exception( 'Could not create directory: ' . $dir );
			}
			$fh = @fopen( $dest, $state['stored'] === 0 ? 'wb' : 'cb' );
			if ( ! $fh ) {
				throw new PBK_Exception( 'Could not write file: ' . $dest );
			}
			if ( $state['stored'] > 0 ) {
				// Drop bytes a crashed request may have written after the last saved state.
				ftruncate( $fh, $state['written'] );
				fseek( $fh, 0, SEEK_END );
			} else {
				$state['written'] = 0;
			}
			$done = $this->extract_entry( $h, $state, function ( $data ) use ( $fh, $dest, &$state ) {
				if ( fwrite( $fh, $data ) !== strlen( $data ) ) {
					throw new PBK_Exception( 'Out of disk space while writing: ' . $dest );
				}
				$state['written'] += strlen( $data );
			}, $deadline );
			fclose( $fh );
			if ( ! $done ) {
				return false;
			}
			@touch( $dest, $h['mtime'] );
			$state['offset'] = $h['data_offset'] + $h['size'];
			$state['stored'] = 0;
			if ( microtime( true ) >= $deadline ) {
				return $this->header_at( $state['offset'] ) === null;
			}
		}
		return true;
	}

	/** Streams one entry's plain bytes to $sink, starting at $state['stored']. */
	private function extract_entry( array $h, array &$state, callable $sink, $deadline ) {
		$state += array( 'stored' => 0 );
		fseek( $this->handle, $h['data_offset'] + $state['stored'] );
		$raw = in_array( $h['name'], PBK_Archive::RAW_FILES, true );
		while ( $state['stored'] < $h['size'] ) {
			$left = $h['size'] - $state['stored'];
			if ( ! $raw && $this->compression ) {
				$len   = unpack( 'N', $this->read( 4 ) )[1];
				$chunk = $this->read( $len );
				if ( $this->password ) {
					$chunk = PBK_Archive::decrypt( $chunk, $this->password );
				}
				$chunk = PBK_Archive::decompress( $chunk, $this->compression );
				$state['stored'] += 4 + $len;
			} elseif ( ! $raw && $this->password ) {
				$len   = $left > PBK_Archive::CHUNK_SIZE ? min( $left, PBK_Archive::CHUNK_SIZE + 32 ) : $left;
				$chunk = PBK_Archive::decrypt( $this->read( $len ), $this->password );
				$state['stored'] += $len;
			} else {
				$len   = min( $left, PBK_Archive::CHUNK_SIZE );
				$chunk = $this->read( $len );
				$state['stored'] += $len;
			}
			if ( $state['stored'] > $h['size'] ) {
				throw new PBK_Exception( 'Corrupted chunk in backup file: ' . $h['name'] );
			}
			$sink( $chunk );
			if ( $state['stored'] < $h['size'] && microtime( true ) >= $deadline ) {
				return false;
			}
		}
		return true;
	}

	private function read( $len ) {
		if ( $len === 0 ) {
			return '';
		}
		$data = fread( $this->handle, $len );
		if ( $data === false || strlen( $data ) !== $len ) {
			throw new PBK_Exception( 'Unexpected end of backup file. The upload may be incomplete.' );
		}
		return $data;
	}

	/**
	 * Verifies the v2 archive CRC, resumable. $state: pos, crc (hex of bytes so far).
	 *
	 * @return bool|null true = ok, null = not finished yet, throws on mismatch. v1 archives return true.
	 */
	public function verify_crc( array &$state, $deadline ) {
		$eof = $this->eof();
		if ( $eof === false ) {
			throw new PBK_Exception( 'This is not a valid .wpress file or the upload is incomplete.' );
		}
		if ( ! $eof ) {
			return true;
		}
		$state += array( 'pos' => 0, 'crc' => null );
		fseek( $this->handle, $state['pos'] );
		while ( $state['pos'] < $eof['size'] ) {
			$len  = min( 8 * 1024 * 1024, $eof['size'] - $state['pos'] );
			$part = hash( 'crc32b', $this->read( $len ) );
			$state['crc'] = $state['crc'] === null ? $part : PBK_Crc::combine( $state['crc'], $part, $len );
			$state['pos'] += $len;
			if ( $state['pos'] < $eof['size'] && microtime( true ) >= $deadline ) {
				return null;
			}
		}
		if ( $state['crc'] !== $eof['crc'] ) {
			throw new PBK_Exception( 'Backup checksum mismatch. The file is corrupted or the upload is incomplete.' );
		}
		return true;
	}
}

/**
 * Resumable writer. Every add_file() call can stop at $deadline and continue in a later request.
 */
class PBK_Archive_Writer {
	private $file;
	private $handle;
	private $password;
	private $compression;

	public function __construct( $file, $password = null, $compression = null ) {
		$this->file        = $file;
		$this->password    = ( $password === '' ) ? null : $password;
		$this->compression = $compression ?: null;
		self::check( $this->password, $this->compression );
		$this->handle = @fopen( $file, 'cb' );
		if ( ! $this->handle ) {
			throw new PBK_Exception( 'Could not create the backup file.' );
		}
	}

	public static function check( $password, $compression ) {
		if ( $compression === 'bzip2' && ! function_exists( 'bzcompress' ) ) {
			throw new PBK_Exception( 'bzip2 compression needs the PHP bz2 extension.' );
		}
		if ( $compression === 'gzip' && ! function_exists( 'gzcompress' ) ) {
			throw new PBK_Exception( 'gzip compression needs the PHP zlib extension.' );
		}
		if ( $password && ! function_exists( 'openssl_encrypt' ) ) {
			throw new PBK_Exception( 'Encryption needs the PHP openssl extension.' );
		}
	}

	public function __destruct() {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	/** Archive names must fit the header (AI1WM limits: name 255, path 4088 bytes). */
	public static function fits( $name ) {
		return strlen( basename( $name ) ) <= 255 && strlen( dirname( $name ) ) <= 4088 && strpos( $name, "\0" ) === false;
	}

	public function add_string( $name, $data ) {
		$tmp = tempnam( dirname( $this->file ), 'pbk' );
		file_put_contents( $tmp, $data );
		$state = array();
		$this->add_file( $tmp, $name, $state, INF );
		unlink( $tmp );
	}

	/**
	 * Appends $src as $name. $state: header (offset), read (source bytes done), stored, crc.
	 *
	 * @return bool true when the file is completely stored.
	 */
	public function add_file( $src, $name, array &$state, $deadline ) {
		$src_handle = @fopen( $src, 'rb' );
		if ( ! $src_handle ) {
			return true; // vanished or unreadable since enumeration: skip, don't fail the backup
		}
		if ( ! isset( $state['header'] ) ) {
			fseek( $this->handle, 0, SEEK_END );
			$state  = array( 'header' => ftell( $this->handle ), 'read' => 0, 'stored' => 0, 'crc' => null );
			$dir    = dirname( $name );
			$header = pack( PBK_Archive::HEADER_PACK, basename( $name ), 0, (int) @filemtime( $src ), $dir === '.' ? '.' : $dir, '' );
			$this->write( $header );
		} else {
			// Drop bytes a crashed request may have written after the last saved state.
			ftruncate( $this->handle, $state['header'] + PBK_Archive::HEADER_SIZE + $state['stored'] );
			fseek( $this->handle, 0, SEEK_END );
		}
		fseek( $src_handle, $state['read'] );
		$raw  = in_array( $name, PBK_Archive::RAW_FILES, true );
		$done = false;
		while ( true ) {
			$chunk = fread( $src_handle, PBK_Archive::CHUNK_SIZE );
			if ( $chunk === false || $chunk === '' ) {
				$done = true;
				break;
			}
			$len          = strlen( $chunk );
			$crc          = hash( 'crc32b', $chunk );
			$state['crc'] = $state['crc'] === null ? $crc : PBK_Crc::combine( $state['crc'], $crc, $len );
			if ( ! $raw && $this->compression ) {
				$chunk = PBK_Archive::compress( $chunk, $this->compression );
				if ( $this->password ) {
					$chunk = PBK_Archive::encrypt( $chunk, $this->password );
				}
				$chunk = pack( 'N', strlen( $chunk ) ) . $chunk;
			} elseif ( ! $raw && $this->password ) {
				$chunk = PBK_Archive::encrypt( $chunk, $this->password );
			}
			$this->write( $chunk );
			$state['read']   += $len;
			$state['stored'] += strlen( $chunk );
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		fclose( $src_handle );
		if ( ! $done ) {
			return false;
		}
		// Patch size and crc32 into the header.
		fseek( $this->handle, $state['header'] + 255 );
		$this->write( pack( 'a14', $state['stored'] ) );
		fseek( $this->handle, $state['header'] + 255 + 14 + 12 + 4088 );
		$this->write( pack( 'a8', $state['crc'] === null ? hash( 'crc32b', '' ) : $state['crc'] ) );
		fseek( $this->handle, 0, SEEK_END );
		return true;
	}

	/** Appends the v2 EOF block (archive size + crc32 of everything before it). */
	public function finish( $archive_crc ) {
		fseek( $this->handle, 0, SEEK_END );
		$size = ftell( $this->handle );
		$this->write( PBK_Archive::eof_block( $size, $archive_crc ) );
		fflush( $this->handle );
	}

	public function size() {
		fseek( $this->handle, 0, SEEK_END );
		return ftell( $this->handle );
	}

	private function write( $data ) {
		if ( fwrite( $this->handle, $data ) !== strlen( $data ) ) {
			throw new PBK_Exception( 'Out of disk space while writing the backup.' );
		}
	}

	/** crc32 of the first $length bytes of a file, resumable. $state: pos, crc. Returns null until done. */
	public static function file_crc( $file, $length, array &$state, $deadline ) {
		$state += array( 'pos' => 0, 'crc' => hash( 'crc32b', '' ) );
		$fh = fopen( $file, 'rb' );
		fseek( $fh, $state['pos'] );
		while ( $state['pos'] < $length ) {
			$data = fread( $fh, (int) min( 8388608, $length - $state['pos'] ) );
			if ( $data === false || $data === '' ) {
				throw new PBK_Exception( 'Could not read the backup file.' );
			}
			$state['crc'] = $state['pos'] === 0 ? hash( 'crc32b', $data ) : PBK_Crc::combine( $state['crc'], hash( 'crc32b', $data ), strlen( $data ) );
			$state['pos'] += strlen( $data );
			if ( $state['pos'] < $length && microtime( true ) >= $deadline ) {
				fclose( $fh );
				return null;
			}
		}
		fclose( $fh );
		return $state['crc'];
	}
}

/**
 * crc32 combine (zlib crc32_combine), so the archive CRC can be computed across requests.
 */
class PBK_Crc {
	public static function combine( $crc1, $crc2, $len2 ) {
		$crc1 = hexdec( $crc1 );
		$crc2 = hexdec( $crc2 );
		if ( $len2 <= 0 ) {
			return sprintf( '%08x', $crc1 );
		}
		$odd    = array( 0xEDB88320 );
		$row    = 1;
		for ( $n = 1; $n < 32; $n++ ) {
			$odd[ $n ] = $row;
			$row     <<= 1;
		}
		$even = self::square( $odd );
		$odd  = self::square( $even );
		do {
			$even = self::square( $odd );
			if ( $len2 & 1 ) {
				$crc1 = self::times( $even, $crc1 );
			}
			$len2 >>= 1;
			if ( $len2 === 0 ) {
				break;
			}
			$odd = self::square( $even );
			if ( $len2 & 1 ) {
				$crc1 = self::times( $odd, $crc1 );
			}
			$len2 >>= 1;
		} while ( $len2 !== 0 );
		return sprintf( '%08x', ( $crc1 ^ $crc2 ) & 0xFFFFFFFF );
	}

	private static function times( array $mat, $vec ) {
		$sum = 0;
		for ( $i = 0; $vec; $i++, $vec >>= 1 ) {
			if ( $vec & 1 ) {
				$sum ^= $mat[ $i ];
			}
		}
		return $sum;
	}

	private static function square( array $mat ) {
		$out = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$out[ $n ] = self::times( $mat, $mat[ $n ] );
		}
		return $out;
	}
}
