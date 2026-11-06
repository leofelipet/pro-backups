<?php
/**
 * Restore pipeline: validate -> crc -> password -> confirm -> extract -> database -> finalize.
 * Every step is resumable; PBK_Job::run() calls step() until the request budget is used.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.RestrictedFunctions, PluginCheck.Security.DirectDB.UnescapedDBParameter -- restoring a backup executes the backup's own SQL statements on the raw connection by design.

class PBK_Importer {
	/** Mu-plugins that belong to a specific host and break elsewhere. */
	const HOST_MU_PLUGINS = array( 'endurance-page-cache.php', 'endurance-php-edge.php', 'endurance-browser-cache.php', 'gd-system-plugin.php', 'gd-system-plugin', 'wp-stack-cache.php', 'wpcomsh-loader.php', 'wpcomsh', 'mu-plugin.php', 'wpengine-common', 'wpe-wp-sign-on-plugin', 'wpe-wp-sign-on-plugin.php', 'wpe-elasticpress-autosuggest-logger.php', 'kinsta-mu-plugins', 'kinsta-mu-plugins.php', 'sso.php' );

	/** Plugins that force HTTPS or hide the login page: deactivated after restoring onto plain HTTP / a new URL. */
	const RISKY_PLUGINS = array( 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'wordpress-https/wordpress-https.php', 'wp-force-ssl/wp-force-ssl.php', 'force-https-littlebizzy/force-https.php', 'wps-hide-login/wps-hide-login.php', 'rename-wp-login/rename-wp-login.php', 'lockdown-wp-admin/lockdown-wp-admin.php', 'hide-my-wp/index.php' );

	/**
	 * @param string $archive  Absolute path of the .wpress file.
	 * @param array  $options  password, confirmed (bool).
	 */
	public static function start( $archive, array $options = array() ) {
		return PBK_Job::create(
			'import',
			array(
				'archive'   => $archive,
				'password'  => isset( $options['password'] ) ? (string) $options['password'] : '',
				'confirmed' => ! empty( $options['confirmed'] ),
				'step'      => 'validate',
			)
		);
	}

	/** User input for a paused job. */
	public static function input( PBK_Job $job, array $input ) {
		$state = &$job->state();
		if ( $job->data['status'] !== 'input' ) {
			return;
		}
		if ( $job->data['need'] === 'password' && isset( $input['password'] ) ) {
			$state['password'] = (string) $input['password'];
		} elseif ( $job->data['need'] === 'confirm' && ! empty( $input['confirm'] ) ) {
			$state['confirmed'] = true;
		} else {
			return;
		}
		$job->data['status'] = 'running';
		$job->data['need']   = null;
		$job->save();
	}

	public static function step( PBK_Job $job, $deadline ) {
		$s      = &$job->state();
		$method = 'step_' . $s['step'];
		self::$method( $job, $s, $deadline );
	}

	private static function reader( array $s ) {
		$pkg  = isset( $s['package'] ) ? $s['package'] : array();
		$type = empty( $pkg['Compression']['Enabled'] ) ? null : $pkg['Compression']['Type'];
		return new PBK_Archive_Reader( $s['archive'], empty( $pkg['Encrypted'] ) ? null : $s['password'], $type );
	}

	private static function step_validate( PBK_Job $job, array &$s ) {
		$job->status( __( 'Checking backup file...', 'pro-backups' ), 0 );
		if ( is_multisite() ) {
			throw new PBK_Exception( __( 'Restoring into a multisite network is not supported yet.', 'pro-backups' ) );
		}
		if ( ! is_file( $s['archive'] ) ) {
			throw new PBK_Exception( __( 'Backup file not found.', 'pro-backups' ) );
		}
		$reader = new PBK_Archive_Reader( $s['archive'] );
		if ( $reader->eof() === false ) {
			throw new PBK_Exception( __( 'This is not a valid .wpress file, or the upload is incomplete.', 'pro-backups' ) );
		}
		$pkg = json_decode( (string) $reader->read_file( 'package.json' ), true );
		if ( ! is_array( $pkg ) || empty( $pkg['SiteURL'] ) ) {
			throw new PBK_Exception( __( 'package.json is missing: this file was not created by Pro Backups or All-in-One WP Migration.', 'pro-backups' ) );
		}
		if ( $reader->read_file( 'multisite.json' ) !== null ) {
			throw new PBK_Exception( __( 'This is a multisite network backup. Network backups are not supported yet.', 'pro-backups' ) );
		}
		$type = empty( $pkg['Compression']['Enabled'] ) ? null : $pkg['Compression']['Type'];
		PBK_Archive::check_support( $type, empty( $pkg['Encrypted'] ) ? null : 'x' );
		$s['package'] = $pkg;
		$s['size']    = $reader->size();
		$job->log( sprintf( /* translators: 1: site URL, 2: WordPress version, 3: file size */ __( 'Backup of %1$s (WordPress %2$s), %3$s.', 'pro-backups' ), $pkg['SiteURL'], isset( $pkg['WordPress']['Version'] ) ? $pkg['WordPress']['Version'] : '?', size_format( $s['size'] ) ) );
		$s['step'] = 'crc';
	}

	private static function step_crc( PBK_Job $job, array &$s, $deadline ) {
		$s += array( 'crc' => array() );
		$reader = new PBK_Archive_Reader( $s['archive'] );
		$pos    = isset( $s['crc']['pos'] ) ? $s['crc']['pos'] : 0;
		$job->status( __( 'Verifying backup integrity...', 'pro-backups' ), 2 + 3 * $pos / max( 1, $s['size'] ) );
		if ( $reader->verify_crc( $s['crc'], $deadline ) ) {
			unset( $s['crc'] );
			$s['step'] = 'password';
		}
	}

	private static function step_password( PBK_Job $job, array &$s ) {
		$pkg = $s['package'];
		if ( empty( $pkg['Encrypted'] ) ) {
			$s['step'] = 'confirm';
			return;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			throw new PBK_Exception( __( 'This backup is encrypted but the PHP openssl extension is missing.', 'pro-backups' ) );
		}
		$ok = $s['password'] !== '' && ( empty( $pkg['EncryptedSignature'] ) || PBK_Archive::password_matches( $pkg['EncryptedSignature'], $s['password'] ) );
		if ( $ok ) {
			$s['step'] = 'confirm';
			return;
		}
		$job->ask( 'password', $s['password'] === '' ? __( 'This backup is encrypted. Enter its password.', 'pro-backups' ) : __( 'Wrong password. Try again.', 'pro-backups' ) );
		$s['password'] = '';
	}

	private static function step_confirm( PBK_Job $job, array &$s ) {
		if ( empty( $s['confirmed'] ) ) {
			$free = PBK_Storage::free_space();
			$msg  = sprintf(
				/* translators: 1: source site URL */
				__( 'This will replace this site\'s database, media, plugins and themes with the backup of %1$s. Make sure you have a backup of the current site. Continue?', 'pro-backups' ),
				$s['package']['SiteURL']
			);
			$from = isset( $s['package']['WordPress']['Version'] ) ? $s['package']['WordPress']['Version'] : '';
			if ( $from && version_compare( $from, get_bloginfo( 'version' ), '>' ) ) {
				/* translators: 1: backup WordPress version, 2: this site's version */
				$msg .= ' ' . sprintf( __( 'Warning: the backup comes from WordPress %1$s but this site runs %2$s. Update WordPress first, or newer themes/plugins from the backup may not work.', 'pro-backups' ), $from, get_bloginfo( 'version' ) );
			}
			if ( $free !== null && $free < $s['size'] * 1.2 ) {
				/* translators: %s: free disk space */
				$msg .= ' ' . sprintf( __( 'Warning: only %s of free disk space; the restore may fail.', 'pro-backups' ), size_format( $free ) );
			}
			$job->ask( 'confirm', $msg );
			return;
		}
		// Keep the plugin running alone while files are swapped: old/new plugin files may be half-written.
		$s['previous_plugins'] = get_option( 'active_plugins', array() );
		$s['site']             = array( 'siteurl' => get_option( 'siteurl' ), 'home' => get_option( 'home' ) );
		$s['keep_options']     = self::own_options();
		update_option( 'active_plugins', array( PBK_BASENAME ) );
		$job->log( __( 'Confirmed. Restoring files.', 'pro-backups' ) );
		$s['step'] = 'extract';
	}

	private static function step_extract( PBK_Job $job, array &$s, $deadline ) {
		$s += array( 'x' => array() );
		$reader = self::reader( $s );
		$db     = $job->dir() . '/database.sql';
		$skip   = self::skip_rules();
		$done   = $reader->extract(
			$s['x'],
			function ( $entry ) use ( $db, $skip ) {
				return self::target( $entry['name'], $db, $skip );
			},
			$deadline
		);
		$pos = isset( $s['x']['offset'] ) ? $s['x']['offset'] : 0;
		$job->status( __( 'Restoring files...', 'pro-backups' ), 5 + 60 * $pos / max( 1, $s['size'] ) );
		if ( $done ) {
			unset( $s['x'] );
			$s['step'] = is_file( $db ) && empty( $s['package']['NoDatabase'] ) ? 'database' : 'finalize';
		}
	}

	private static function skip_rules() {
		return array(
			'dropins' => function_exists( '_get_dropins' ) ? array_keys( _get_dropins() ) : array( 'advanced-cache.php', 'db.php', 'object-cache.php' ),
			'plugin'  => 'plugins/' . dirname( PBK_BASENAME ) . '/',
		);
	}

	/** Maps an archive entry to its destination, or null to skip it. Public for tests. */
	public static function target( $name, $db_file, array $skip ) {
		if ( $name === 'database.sql' ) {
			return $db_file;
		}
		$parts = explode( '/', $name );
		$first = $parts[0];
		if (
			in_array( $name, array( 'package.json', 'multisite.json', 'themes/functions.php' ), true ) ||
			( count( $parts ) === 1 && in_array( $name, $skip['dropins'], true ) ) ||
			in_array( $first, array( 'pro-backups', 'ai1wm-backups', 'database', 'cache' ), true ) ||
			strpos( $name . '/', $skip['plugin'] ) === 0 ||
			strpos( $name, 'uploads/elementor/css/' ) === 0 ||
			( $first === 'mu-plugins' && isset( $parts[1] ) && in_array( $parts[1], self::HOST_MU_PLUGINS, true ) ) ||
			preg_match( '/\.(sqlite|sqlite-wal|sqlite-shm|sqlite-journal|less\.cache)$/i', $name )
		) {
			return null;
		}
		if ( $first === 'plugins' ) {
			return WP_PLUGIN_DIR . substr( $name, 7 );
		}
		if ( $first === 'themes' ) {
			return get_theme_root() . substr( $name, 6 );
		}
		return WP_CONTENT_DIR . '/' . $name;
	}

	private static function step_database( PBK_Job $job, array &$s, $deadline ) {
		global $wpdb;
		$s += array( 'db' => array() );
		if ( ! isset( $s['replace'] ) ) {
			$s['replace'] = self::replacements( $s['package'] );
			$job->log( sprintf( /* translators: %d: number of rules */ __( 'Restoring database (%d replacement rules).', 'pro-backups' ), count( $s['replace'] ) ) );
		}
		$file     = $job->dir() . '/database.sql';
		$importer = new PBK_Sql_Importer( $wpdb->prefix, new PBK_Replace( $s['replace'] ), array( __CLASS__, 'exec' ) );
		$done     = $importer->run( $file, $s['db'], $deadline );
		self::protect_running_site( $s );
		$job->status( __( 'Restoring database...', 'pro-backups' ), 65 + 30 * $s['db']['pos'] / max( 1, filesize( $file ) ) );
		if ( $done ) {
			$job->log( sprintf( /* translators: 1: number of queries, 2: number of failed queries */ __( 'Database restored: %1$d queries, %2$d failed.', 'pro-backups' ), $s['db']['queries'], $s['db']['failed'] ) );
			foreach ( $s['db']['errors'] as $error ) {
				$job->log( sprintf( /* translators: %s: database error */ __( 'SQL error: %s', 'pro-backups' ), $error ) );
			}
			$s['step'] = 'finalize';
		}
	}

	/** Runs SQL on the raw connection (wpdb->query() would scan huge INSERTs and may alter binary data). */
	public static function exec( $sql ) {
		global $wpdb;
		if ( $wpdb->dbh instanceof mysqli ) {
			return mysqli_query( $wpdb->dbh, $sql ) ? '' : mysqli_error( $wpdb->dbh );
		}
		$wpdb->suppress_errors( true );
		$wpdb->query( $sql ); // SQLite integration and other drop-ins translate MySQL here
		$wpdb->suppress_errors( false );
		return (string) $wpdb->last_error;
	}

	/**
	 * After every database chunk the next request boots WordPress from the half-restored database:
	 * keep only this plugin active and keep the current URLs, so that request can't break.
	 */
	private static function protect_running_site( array $s ) {
		wp_cache_flush();
		update_option( 'active_plugins', array( PBK_BASENAME ) );
		update_option( 'siteurl', $s['site']['siteurl'] );
		update_option( 'home', $s['site']['home'] );
	}

	private static function step_finalize( PBK_Job $job, array &$s ) {
		wp_cache_flush();
		$pkg = $s['package'];
		self::protect_running_site( $s );
		foreach ( $s['keep_options'] as $name => $value ) {
			update_option( $name, $value );
		}

		$plugins = isset( $pkg['Plugins'] ) ? (array) $pkg['Plugins'] : array();
		$risky   = self::RISKY_PLUGINS;
		$plugins = array_values(
			array_filter(
				array_unique( array_merge( $plugins, array( PBK_BASENAME ) ) ),
				function ( $p ) use ( $risky ) {
					return is_file( WP_PLUGIN_DIR . '/' . $p ) && ( $p === PBK_BASENAME || ! in_array( $p, $risky, true ) );
				}
			)
		);
		update_option( 'active_plugins', $plugins );

		foreach ( array( 'Template' => 'template', 'Stylesheet' => 'stylesheet' ) as $key => $option ) {
			if ( ! empty( $pkg[ $key ] ) && is_dir( get_theme_root() . '/' . $pkg[ $key ] ) ) {
				update_option( $option, $pkg[ $key ] );
			}
		}
		delete_option( 'rewrite_rules' ); // regenerated on next load with the restored permalink settings
		wp_cache_flush();

		@unlink( $job->dir() . '/database.sql' );
		$failed = isset( $s['db']['failed'] ) ? (int) $s['db']['failed'] : 0;
		$msg    = __( 'Restore complete. Log in again with a user from the backup.', 'pro-backups' );
		if ( $failed ) {
			/* translators: %d: number of failed SQL queries */
			$msg .= ' ' . sprintf( _n( '%d database query failed (see the log).', '%d database queries failed (see the log).', $failed, 'pro-backups' ), $failed );
		}
		$job->data['result'] = array( 'login_url' => wp_login_url() );
		$s['password']       = '';
		$s['step']           = 'done';
		$job->finish( $msg );
		do_action( 'pbk_restore_done', $job );
	}

	public static function on_error( PBK_Job $job ) {
		$s = &$job->state();
		$s['password'] = '';
		// Failed before the database was touched: give the old site its plugins back.
		if ( isset( $s['previous_plugins'] ) && in_array( $s['step'], array( 'extract' ), true ) ) {
			update_option( 'active_plugins', $s['previous_plugins'] );
		}
		@unlink( $job->dir() . '/database.sql' );
	}

	/** Our own options survive the restore (schedules, settings). */
	private static function own_options() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'pbk\\_%'" ) as $row ) {
			$out[ $row->option_name ] = maybe_unserialize( $row->option_value );
		}
		return $out;
	}

	/**
	 * old => new values for URLs, paths and e-mail domains, in raw, url-encoded and JSON-escaped forms.
	 * Public for tests.
	 */
	public static function replacements( array $pkg, array $current = array() ) {
		$current += array(
			'site'        => site_url(),
			'home'        => home_url(),
			'abspath'     => ABSPATH,
			'content'     => WP_CONTENT_DIR,
			'uploads'     => wp_upload_dir( null, false )['basedir'],
			'uploads_url' => wp_upload_dir( null, false )['baseurl'],
		);
		$urls = array();
		foreach ( array( 'HomeURL' => 'home', 'InternalHomeURL' => 'home', 'SiteURL' => 'site', 'InternalSiteURL' => 'site' ) as $key => $to ) {
			if ( ! empty( $pkg[ $key ] ) ) {
				$urls[ untrailingslashit( $pkg[ $key ] ) ] = untrailingslashit( $current[ $to ] );
			}
		}
		$map = array();
		foreach ( $urls as $old => $new ) {
			$old_rel = preg_replace( '#^https?:#i', '', $old );
			$new_rel = preg_replace( '#^https?:#i', '', $new );
			$map[ 'http:' . $old_rel ]  = $new;
			$map[ 'https:' . $old_rel ] = $new;
			$map[ $old_rel ]            = $new_rel; // protocol-relative //host/path
		}
		$paths = array(
			'Absolute' => array( $current['abspath'], true ),
			'Content'  => array( $current['content'], false ),
			'Uploads'  => array( $current['uploads'], false ),
		);
		foreach ( $paths as $key => $to ) {
			if ( ! empty( $pkg['WordPress'][ $key ] ) ) {
				$old = $pkg['WordPress'][ $key ];
				$map[ $to[1] ? trailingslashit( $old ) : untrailingslashit( $old ) ] = $to[1] ? trailingslashit( $to[0] ) : untrailingslashit( $to[0] );
			}
		}
		if ( ! empty( $pkg['WordPress']['UploadsURL'] ) ) {
			$old = untrailingslashit( $pkg['WordPress']['UploadsURL'] );
			if ( strtr( $old, $map ) !== untrailingslashit( $current['uploads_url'] ) ) {
				$map[ $old ] = untrailingslashit( $current['uploads_url'] );
			}
		}
		if ( empty( $pkg['NoEmailReplace'] ) && ! empty( $pkg['HomeURL'] ) ) {
			$old_host = preg_replace( '/^www\./i', '', (string) wp_parse_url( $pkg['HomeURL'], PHP_URL_HOST ) );
			$new_host = preg_replace( '/^www\./i', '', (string) wp_parse_url( $current['home'], PHP_URL_HOST ) );
			if ( $old_host && $new_host && strpos( $old_host, '.' ) !== false && strpos( $new_host, '.' ) !== false ) {
				$map[ '@' . $old_host ] = '@' . $new_host;
			}
		}
		if ( ! empty( $pkg['Replace']['OldValues'] ) ) {
			foreach ( $pkg['Replace']['OldValues'] as $i => $old ) {
				if ( $old !== '' && isset( $pkg['Replace']['NewValues'][ $i ] ) && $pkg['Replace']['NewValues'][ $i ] !== '' ) {
					$map[ $old ] = $pkg['Replace']['NewValues'][ $i ];
				}
			}
		}
		$out = array();
		foreach ( $map as $old => $new ) {
			if ( $old === $new || $old === '' ) {
				continue;
			}
			$out[ $old ]                       = $new;
			$out[ urlencode( $old ) ]          = urlencode( $new );
			$out[ rawurlencode( $old ) ]       = rawurlencode( $new );
			$out[ addcslashes( $old, '/' ) ]   = addcslashes( $new, '/' );
		}
		return array_filter(
			$out,
			function ( $new, $old ) {
				return $old !== $new;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}
}
