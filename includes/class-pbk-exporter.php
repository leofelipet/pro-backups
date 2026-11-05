<?php
/**
 * Backup pipeline: enumerate -> files -> database -> database_add -> package -> crc -> finish.
 * Produces a .wpress archive that Pro Backups and All-in-One WP Migration can restore.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPressVIPMinimum.Performance.WPQueryParams -- streams files into the archive; 'exclude' is a backup option, not a WP_Query argument.

class PBK_Exporter {
	const EXCLUDES = array( 'media', 'plugins', 'themes', 'inactive-plugins', 'inactive-themes', 'cache', 'database', 'spam', 'revisions' );

	/** wp-content folders that are never backed up. */
	const NEVER = array( 'pro-backups', 'ai1wm-backups', 'upgrade', 'upgrade-temp-backup' );

	/** Cache folders skipped with the "cache" exclusion. */
	const CACHE_DIRS = array( 'cache', 'et-cache', 'wpo-cache', 'litespeed', 'w3tc-config', 'endurance-page-cache' );

	/**
	 * @param array $o name, password, compression (gzip|bzip2|''), exclude (list of self::EXCLUDES),
	 *                 exclude_files (paths relative to wp-content), exclude_tables (full table names),
	 *                 after (callable name run when done, e.g. retention / reset), label.
	 */
	public static function start( array $o ) {
		$o += array( 'name' => '', 'password' => '', 'compression' => '', 'exclude' => array(), 'exclude_files' => array(), 'exclude_tables' => array(), 'after' => array(), 'label' => '' );
		if ( ! in_array( $o['compression'], array( '', 'gzip', 'bzip2' ), true ) ) {
			throw new PBK_Exception( __( 'Unknown compression type.', 'pro-backups' ) );
		}
		$o['exclude'] = array_values( array_intersect( (array) $o['exclude'], self::EXCLUDES ) );
		$o['name']    = $o['name'] !== '' ? PBK_Storage::sanitize_name( $o['name'] ) : self::default_name();
		PBK_Archive_Writer::check( $o['password'], $o['compression'] ); // fail early on missing extensions
		return PBK_Job::create( 'export', array( 'options' => $o, 'step' => 'enumerate' ) );
	}

	public static function default_name() {
		$host = preg_replace( '/[^a-z0-9.-]+/i', '-', (string) wp_parse_url( home_url(), PHP_URL_HOST ) . (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		return trim( $host, '-' ) . '-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false ) ) . '.wpress';
	}

	/** Options from an admin form / AJAX request. */
	public static function options_from_request( array $r ) {
		$lines = function ( $v ) {
			return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $v ) ) ) );
		};
		return array(
			'password'       => isset( $r['password'] ) ? (string) $r['password'] : '',
			'compression'    => isset( $r['compression'] ) ? sanitize_key( $r['compression'] ) : '',
			'exclude'        => isset( $r['exclude'] ) ? array_map( 'sanitize_key', (array) $r['exclude'] ) : array(),
			'exclude_files'  => isset( $r['exclude_files'] ) ? $lines( $r['exclude_files'] ) : array(),
			'exclude_tables' => isset( $r['exclude_tables'] ) ? array_map( 'sanitize_text_field', (array) $r['exclude_tables'] ) : array(),
		);
	}

	public static function options_from_cli( array $a ) {
		return array(
			'name'           => isset( $a['name'] ) ? $a['name'] : '',
			'password'       => isset( $a['password'] ) ? $a['password'] : '',
			'compression'    => isset( $a['compression'] ) ? $a['compression'] : '',
			'exclude'        => isset( $a['exclude'] ) ? array_map( 'trim', explode( ',', $a['exclude'] ) ) : array(),
			'exclude_files'  => isset( $a['exclude-files'] ) ? array_map( 'trim', explode( ',', $a['exclude-files'] ) ) : array(),
			'exclude_tables' => isset( $a['exclude-tables'] ) ? array_map( 'trim', explode( ',', $a['exclude-tables'] ) ) : array(),
		);
	}

	public static function step( PBK_Job $job, $deadline ) {
		$s      = &$job->state();
		$method = 'step_' . $s['step'];
		self::$method( $job, $s, $deadline );
	}

	private static function writer( PBK_Job $job, array $s ) {
		return new PBK_Archive_Writer( $job->dir() . '/backup.wpress', $s['options']['password'], $s['options']['compression'] );
	}

	private static function has( array $s, $exclude ) {
		return in_array( $exclude, $s['options']['exclude'], true );
	}

	/** Lists files to back up into files.list ("absolute path TAB archive name"). */
	private static function step_enumerate( PBK_Job $job, array &$s ) {
		$job->status( __( 'Listing files...', 'pro-backups' ), 1 );
		$list  = fopen( $job->dir() . '/files.list', 'wb' );
		$count = 0;
		$bytes = 0;
		foreach ( self::roots( $s ) as $root ) {
			list( $prefix, $dir, $skip ) = $root;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			// shortcut: enumeration runs in one request (fast: no file reads). Make it resumable if huge sites time out here.
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					function ( $f, $key, $iterator ) use ( $dir, $prefix, $skip ) {
						$rel  = $prefix . str_replace( '\\', '/', substr( $f->getPathname(), strlen( $dir ) + 1 ) );
						if ( $f->isDir() && $f->isLink() ) {
							return false; // avoid loops and backing up things outside the site
						}
						foreach ( $skip as $s ) {
							if ( $rel === $s || strpos( $rel, $s . '/' ) === 0 ) {
								return false;
							}
						}
						return $f->isDir() || $f->isFile();
					}
				),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD // unreadable folders are skipped, not fatal
			);
			foreach ( $it as $f ) {
				if ( ! $f->isFile() ) {
					continue;
				}
				$rel = $prefix . str_replace( '\\', '/', substr( $f->getPathname(), strlen( $dir ) + 1 ) );
				if ( ! PBK_Archive_Writer::fits( $rel ) || strpbrk( $rel, "\t\n\r" ) !== false || preg_match( '/\.wpress(\.part)?$/i', $rel ) ) {
					continue;
				}
				fwrite( $list, $f->getPathname() . "\t" . $rel . "\n" );
				$count++;
				$bytes += $f->getSize();
			}
		}
		fclose( $list );
		$s['files'] = array( 'count' => $count, 'bytes' => $bytes, 'pos' => 0, 'done_bytes' => 0, 'cur' => array() );
		$job->log( sprintf( '%d files (%s) to back up.', $count, size_format( $bytes ) ) );
		$s['step'] = 'files';
	}

	/** [archive prefix, absolute dir, skip list (archive names)] */
	private static function roots( array $s ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$content = untrailingslashit( WP_CONTENT_DIR );
		$plugins = untrailingslashit( WP_PLUGIN_DIR );
		$themes  = untrailingslashit( get_theme_root() );
		$uploads = untrailingslashit( wp_upload_dir( null, false )['basedir'] );

		$skip = array_merge( self::NEVER, array( 'plugins', 'themes' ) );
		if ( function_exists( '_get_dropins' ) ) {
			$skip = array_merge( $skip, array_keys( _get_dropins() ) );
		}
		if ( self::has( $s, 'cache' ) ) {
			$skip = array_merge( $skip, self::CACHE_DIRS );
		}
		if ( self::has( $s, 'media' ) && strpos( $uploads . '/', $content . '/' ) === 0 ) {
			$skip[] = substr( $uploads, strlen( $content ) + 1 );
		}
		if ( defined( 'PBK_STORAGE_DIR' ) && strpos( PBK_STORAGE_DIR . '/', $content . '/' ) === 0 ) {
			$skip[] = substr( untrailingslashit( PBK_STORAGE_DIR ), strlen( $content ) + 1 );
		}
		foreach ( $s['options']['exclude_files'] as $path ) {
			$path = trim( str_replace( '\\', '/', $path ), '/' );
			if ( $path !== '' ) {
				$skip[] = $path;
			}
		}
		$roots = array( array( '', $content, $skip ) );

		if ( ! self::has( $s, 'plugins' ) ) {
			$pskip = array( 'plugins/' . dirname( PBK_BASENAME ) );
			if ( self::has( $s, 'inactive-plugins' ) ) {
				$folder = function ( $p ) {
					return dirname( $p ) === '.' ? $p : dirname( $p );
				};
				$active = array_map( $folder, (array) get_option( 'active_plugins', array() ) );
				foreach ( array_keys( get_plugins() ) as $plugin ) {
					if ( ! in_array( $folder( $plugin ), $active, true ) ) {
						$pskip[] = 'plugins/' . $folder( $plugin );
					}
				}
			}
			$roots[] = array( 'plugins/', $plugins, array_merge( $pskip, self::prefixed( $skip, 'plugins/' ) ) );
		}
		if ( ! self::has( $s, 'themes' ) ) {
			$tskip = array();
			if ( self::has( $s, 'inactive-themes' ) ) {
				$keep = array( get_option( 'template' ), get_option( 'stylesheet' ) );
				foreach ( array_keys( wp_get_themes() ) as $theme ) {
					if ( ! in_array( $theme, $keep, true ) ) {
						$tskip[] = 'themes/' . $theme;
					}
				}
			}
			$roots[] = array( 'themes/', $themes, array_merge( $tskip, self::prefixed( $skip, 'themes/' ) ) );
		}
		return $roots;
	}

	/** User exclusions inside plugins/ or themes/ keep their full archive path. */
	private static function prefixed( array $skip, $prefix ) {
		return array_values( array_filter( $skip, function ( $p ) use ( $prefix ) {
			return strpos( $p, $prefix ) === 0;
		} ) );
	}

	private static function step_files( PBK_Job $job, array &$s, $deadline ) {
		$f      = &$s['files'];
		$writer = self::writer( $job, $s );
		$list   = fopen( $job->dir() . '/files.list', 'rb' );
		fseek( $list, $f['pos'] );
		$all = false;
		while ( true ) {
			$line = fgets( $list );
			if ( $line === false ) {
				$all = true;
				break;
			}
			list( $abs, $rel ) = explode( "\t", rtrim( $line, "\n" ), 2 );
			if ( ! $writer->add_file( $abs, $rel, $f['cur'], $deadline ) ) {
				break; // resume this file next request (pos still points at its line)
			}
			$f['done_bytes'] += isset( $f['cur']['read'] ) ? $f['cur']['read'] : 0;
			$f['cur']         = array();
			$f['pos']         = ftell( $list );
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		fclose( $list );
		$job->status( __( 'Backing up files...', 'pro-backups' ), 2 + 70 * $f['done_bytes'] / max( 1, $f['bytes'] ) );
		if ( $all ) {
			$job->log( 'Files done.' );
			$s['step'] = self::has( $s, 'database' ) ? 'package' : 'database';
		}
	}

	private static function step_database( PBK_Job $job, array &$s, $deadline ) {
		global $wpdb;
		$exporter = new PBK_Sql_Exporter( $wpdb->prefix, self::row_filters( $s ) );
		if ( ! isset( $s['db'] ) ) {
			$s['db'] = array( 'table' => 0, 'tables' => $exporter->tables( $s['options']['exclude_tables'] ) );
			$job->log( sprintf( 'Exporting %d tables.', count( $s['db']['tables'] ) ) );
		}
		$done = $exporter->run( $job->dir() . '/database.sql', $s['db'], $deadline );
		$job->status( __( 'Backing up database...', 'pro-backups' ), 72 + 13 * $s['db']['table'] / max( 1, count( $s['db']['tables'] ) ) );
		if ( $done ) {
			unset( $s['db'] );
			$s['step'] = 'database_add';
		}
	}

	private static function row_filters( array $s ) {
		$where = array( 'options' => "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'" );
		if ( self::has( $s, 'spam' ) ) {
			$where['comments'] = "comment_approved <> 'spam'";
		}
		if ( self::has( $s, 'revisions' ) ) {
			$where['posts'] = "post_type <> 'revision'";
		}
		return $where;
	}

	private static function step_database_add( PBK_Job $job, array &$s, $deadline ) {
		$s += array( 'dbfile' => array() );
		$job->status( __( 'Adding database to the backup...', 'pro-backups' ), 86 );
		if ( self::writer( $job, $s )->add_file( $job->dir() . '/database.sql', 'database.sql', $s['dbfile'], $deadline ) ) {
			unset( $s['dbfile'] );
			@unlink( $job->dir() . '/database.sql' );
			$s['step'] = 'package';
		}
	}

	private static function step_package( PBK_Job $job, array &$s ) {
		self::writer( $job, $s )->add_string( 'package.json', wp_json_encode( self::package( $s ) ) );
		$s['size'] = filesize( $job->dir() . '/backup.wpress' );
		$s['step'] = 'crc';
	}

	/** package.json in the AI1WM format. */
	public static function package( array $s ) {
		global $wpdb, $wp_version;
		$o       = $s['options'];
		$uploads = wp_upload_dir( null, false );
		$pkg     = array(
			'SiteURL'         => site_url(),
			'HomeURL'         => home_url(),
			'InternalSiteURL' => get_option( 'siteurl' ),
			'InternalHomeURL' => get_option( 'home' ),
			'Plugin'          => array( 'Version' => PBK_VERSION, 'Name' => 'pro-backups' ),
			'WordPress'       => array(
				'Version'    => $wp_version,
				'Absolute'   => ABSPATH,
				'Content'    => WP_CONTENT_DIR,
				'Plugins'    => WP_PLUGIN_DIR,
				'Themes'     => array( get_theme_root() ),
				'Uploads'    => $uploads['basedir'],
				'UploadsURL' => $uploads['baseurl'],
			),
			'Database'        => array(
				'Version' => $wpdb->db_server_info(),
				'Charset' => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'undefined',
				'Collate' => defined( 'DB_COLLATE' ) ? DB_COLLATE : 'undefined',
				'Prefix'  => $wpdb->prefix,
			),
			'PHP'             => array( 'Version' => PHP_VERSION, 'System' => PHP_OS, 'Integer' => PHP_INT_SIZE ),
			'Plugins'         => array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( PBK_BASENAME ) ) ),
			'Template'        => get_option( 'template' ),
			'Stylesheet'      => get_option( 'stylesheet' ),
			'Uploads'         => get_option( 'upload_path' ),
			'UploadsURL'      => get_option( 'upload_url_path' ),
			'Server'          => array(
				'.htaccess'  => base64_encode( is_file( ABSPATH . '.htaccess' ) ? (string) file_get_contents( ABSPATH . '.htaccess' ) : '' ),
				'web.config' => base64_encode( is_file( ABSPATH . 'web.config' ) ? (string) file_get_contents( ABSPATH . 'web.config' ) : '' ),
			),
		);
		$flags = array( 'media' => 'NoMedia', 'plugins' => 'NoPlugins', 'themes' => 'NoThemes', 'inactive-plugins' => 'NoInactivePlugins', 'inactive-themes' => 'NoInactiveThemes', 'cache' => 'NoCache', 'database' => 'NoDatabase', 'spam' => 'NoSpamComments', 'revisions' => 'NoPostRevisions' );
		foreach ( $o['exclude'] as $ex ) {
			$pkg[ $flags[ $ex ] ] = true;
		}
		if ( $o['exclude_tables'] ) {
			$pkg['Database']['ExcludedTables'] = $o['exclude_tables'];
		}
		if ( $o['password'] !== '' ) {
			$pkg['Encrypted']          = true;
			$pkg['EncryptedSignature'] = PBK_Archive::signature( $o['password'] );
		}
		if ( $o['compression'] ) {
			$pkg['Compression'] = array( 'Enabled' => true, 'Type' => $o['compression'] );
		}
		return $pkg;
	}

	private static function step_crc( PBK_Job $job, array &$s, $deadline ) {
		$s += array( 'crc' => array() );
		$job->status( __( 'Finishing backup...', 'pro-backups' ), 90 + 9 * ( isset( $s['crc']['pos'] ) ? $s['crc']['pos'] : 0 ) / max( 1, $s['size'] ) );
		$crc = PBK_Archive_Writer::file_crc( $job->dir() . '/backup.wpress', $s['size'], $s['crc'], $deadline );
		if ( $crc !== null ) {
			self::writer( $job, $s )->finish( $crc );
			unset( $s['crc'] );
			$s['step'] = 'finish';
		}
	}

	private static function step_finish( PBK_Job $job, array &$s ) {
		$dest = PBK_Storage::unique_path( $s['options']['name'] );
		if ( ! @rename( $job->dir() . '/backup.wpress', $dest ) ) {
			throw new PBK_Exception( __( 'Could not move the backup into the backups folder.', 'pro-backups' ) );
		}
		@unlink( $job->dir() . '/files.list' );
		$s['options']['password'] = '';
		$job->data['result']      = array(
			'file'         => basename( $dest ),
			'path'         => $dest,
			'size'         => filesize( $dest ),
			'download_url' => PBK_Admin::action_url( 'pbk_download', basename( $dest ) ),
		);
		$s['step'] = 'done';
		/* translators: 1: file name, 2: size */
		$job->finish( sprintf( __( 'Backup created: %1$s (%2$s).', 'pro-backups' ), basename( $dest ), size_format( filesize( $dest ), 1 ) ) );
		foreach ( (array) $s['options']['after'] as $callback ) {
			if ( is_callable( $callback ) ) {
				call_user_func( $callback, $job );
			}
		}
		do_action( 'pbk_backup_done', $job );
	}

	public static function on_error( PBK_Job $job ) {
		$s = &$job->state();
		$s['options']['password'] = '';
		@unlink( $job->dir() . '/backup.wpress' );
		@unlink( $job->dir() . '/database.sql' );
		@unlink( $job->dir() . '/files.list' );
	}
}
