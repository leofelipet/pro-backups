<?php
/**
 * Backup storage: wp-content/pro-backups (override with the PBK_STORAGE_DIR constant).
 * Protected against direct web access; downloads go through an authenticated endpoint.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- backup folder management; messages are escaped where displayed.

class PBK_Storage {
	public static function dir() {
		$dir = defined( 'PBK_STORAGE_DIR' ) ? PBK_STORAGE_DIR : WP_CONTENT_DIR . '/pro-backups';
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				throw new PBK_Exception( sprintf( /* translators: %s: folder path */ __( 'Could not create the backups folder: %s', 'pro-backups' ), $dir ) );
			}
		}
		self::protect( $dir );
		return $dir;
	}

	public static function protect( $dir ) {
		$files = array(
			'index.php'  => "<?php // Silence is golden.\n",
			'.htaccess'  => "# Pro Backups: no direct access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! is_file( "$dir/$name" ) ) {
				@file_put_contents( "$dir/$name", $content );
			}
		}
	}

	/** Folders scanned for .wpress files: ours first, then AI1WM's (so its backups can be restored here). */
	public static function dirs() {
		$dirs = array( self::dir() );
		if ( is_dir( WP_CONTENT_DIR . '/ai1wm-backups' ) ) {
			$dirs[] = WP_CONTENT_DIR . '/ai1wm-backups';
		}
		return $dirs;
	}

	/** @return array[] name, path, size, mtime, dir — newest first. */
	public static function backups() {
		$out = array();
		foreach ( self::dirs() as $dir ) {
			foreach ( (array) glob( $dir . '/*.wpress' ) as $path ) {
				if ( is_file( $path ) ) {
					$out[] = array(
						'name'  => basename( $path ),
						'path'  => $path,
						'size'  => filesize( $path ),
						'mtime' => filemtime( $path ),
						'dir'   => $dir === self::dir() ? 'pro-backups' : 'ai1wm-backups',
					);
				}
			}
		}
		usort( $out, function ( $a, $b ) {
			return $b['mtime'] <=> $a['mtime'];
		} );
		return $out;
	}

	/**
	 * Resolves a backup by file name inside the known folders only (no paths accepted).
	 *
	 * @throws PBK_Exception
	 */
	public static function find( $name ) {
		$name = (string) $name;
		if ( $name !== basename( $name ) || ! preg_match( '/^[^\/\\\\]+\.wpress$/i', $name ) ) {
			throw new PBK_Exception( __( 'Invalid backup name.', 'pro-backups' ) );
		}
		foreach ( self::dirs() as $dir ) {
			if ( is_file( "$dir/$name" ) ) {
				return "$dir/$name";
			}
		}
		throw new PBK_Exception( sprintf( /* translators: %s: file name */ __( 'Backup not found: %s', 'pro-backups' ), $name ) );
	}

	public static function sanitize_name( $name ) {
		$name = sanitize_file_name( basename( (string) $name ) );
		if ( ! preg_match( '/\.wpress$/i', $name ) ) {
			throw new PBK_Exception( __( 'Only .wpress files are accepted.', 'pro-backups' ) );
		}
		return $name;
	}

	/** A free file name in our folder (appends -1, -2 ... when taken). */
	public static function unique_path( $name ) {
		$dir  = self::dir();
		$base = substr( $name, 0, -7 );
		$path = "$dir/$name";
		for ( $i = 1; file_exists( $path ) || file_exists( $path . '.part' ); $i++ ) {
			$path = "$dir/$base-$i.wpress";
		}
		return $path;
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return @unlink( $dir );
		}
		foreach ( scandir( $dir ) as $f ) {
			if ( $f !== '.' && $f !== '..' ) {
				self::rrmdir( "$dir/$f" );
			}
		}
		return @rmdir( $dir );
	}

	public static function free_space() {
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false;
		return $free === false ? null : (float) $free;
	}
}
