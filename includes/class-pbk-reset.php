<?php
/**
 * Reset tools. A full backup is always created first; the reset runs only when it succeeded.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the database reset drops this site's tables (names from SHOW TABLES, quoted).

class PBK_Reset {
	const WHAT = array( 'media', 'plugins', 'themes', 'database' );

	public static function init() {
		add_action( 'wp_ajax_pbk_reset_start', array( __CLASS__, 'ajax_start' ) );
	}

	public static function labels() {
		return array(
			'media'    => __( 'Delete all media files (uploads folder)', 'pro-backups' ),
			'plugins'  => __( 'Deactivate and delete all plugins (except Pro Backups)', 'pro-backups' ),
			'themes'   => __( 'Delete all themes except the active one', 'pro-backups' ),
			'database' => __( 'Reset the database to a fresh WordPress install (keeps your admin login, site URL and title)', 'pro-backups' ),
		);
	}

	/** Starts "backup, then reset". */
	public static function start( array $what ) {
		$what = array_values( array_intersect( $what, self::WHAT ) );
		if ( ! $what ) {
			throw new PBK_Exception( __( 'Choose what to reset.', 'pro-backups' ) );
		}
		$job = PBK_Exporter::start(
			array(
				'name'  => 'before-reset-' . gmdate( 'Ymd-His' ) . '.wpress',
				'after' => array( array( __CLASS__, 'after_backup' ) ),
			)
		);
		$job->data['reset']   = $what;
		$job->data['user_id'] = get_current_user_id();
		$job->save();
		return $job;
	}

	/** Runs after the safety backup is stored (PBK_Exporter "after" callback). */
	public static function after_backup( PBK_Job $job ) {
		if ( empty( $job->data['reset'] ) ) {
			return;
		}
		$backup = $job->data['result']['file'];
		foreach ( $job->data['reset'] as $what ) {
			call_user_func( array( __CLASS__, 'reset_' . $what ), $job );
			$job->log( sprintf( /* translators: %s: what is being reset */ __( 'Reset: %s', 'pro-backups' ), $what ) );
		}
		if ( in_array( 'database', $job->data['reset'], true ) ) {
			$job->data['result']['login_url'] = wp_login_url();
		}
		/* translators: %s: backup file name */
		$job->finish( sprintf( __( 'Reset complete. The site was backed up first to %s (restore it from the Backups tab to undo).', 'pro-backups' ), $backup ) );
		$job->save();
	}

	private static function reset_media() {
		$dir = wp_upload_dir( null, false )['basedir'];
		foreach ( (array) @scandir( $dir ) as $name ) {
			if ( $name !== '.' && $name !== '..' && $name !== '' ) {
				PBK_Storage::rrmdir( "$dir/$name" );
			}
		}
	}

	private static function reset_plugins() {
		update_option( 'active_plugins', array( PBK_BASENAME ) );
		$mine = dirname( PBK_BASENAME );
		foreach ( (array) glob( WP_PLUGIN_DIR . '/*' ) as $path ) {
			$name = basename( $path );
			if ( $name !== $mine && $name !== 'index.php' ) {
				PBK_Storage::rrmdir( $path );
			}
		}
	}

	private static function reset_themes() {
		$keep = array( get_option( 'template' ), get_option( 'stylesheet' ) );
		foreach ( (array) glob( get_theme_root() . '/*', GLOB_ONLYDIR ) as $path ) {
			if ( ! in_array( basename( $path ), $keep, true ) ) {
				PBK_Storage::rrmdir( $path );
			}
		}
	}

	/** Drops this site's tables and reinstalls WordPress, keeping the admin's login and password. */
	private static function reset_database( PBK_Job $job ) {
		global $wpdb;
		$user = get_userdata( $job->data['user_id'] ?: 1 );
		if ( ! $user ) {
			throw new PBK_Exception( __( 'Could not find the current admin user.', 'pro-backups' ) );
		}
		$keep = array(
			'blogname'   => get_option( 'blogname' ),
			'siteurl'    => get_option( 'siteurl' ),
			'home'       => get_option( 'home' ),
			'blog_public'=> get_option( 'blog_public' ),
			'template'   => get_option( 'template' ),
			'stylesheet' => get_option( 'stylesheet' ),
			'WPLANG'     => get_option( 'WPLANG' ),
		);
		$own   = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'pbk\\_%'", ARRAY_A );
		$login = $user->user_login;
		$email = $user->user_email;
		$hash  = $user->user_pass;

		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . PBK_Sql_Exporter::ident( $table ) );
		}
		wp_cache_flush();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$no_mail = function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $no_mail );
		$result = wp_install( $keep['blogname'], $login, $email, (bool) $keep['blog_public'], '', wp_generate_password( 32 ) );
		remove_filter( 'pre_wp_mail', $no_mail );

		$wpdb->update( $wpdb->users, array( 'user_pass' => $hash ), array( 'ID' => $result['user_id'] ) );
		foreach ( $keep as $name => $value ) {
			if ( $value !== false ) {
				update_option( $name, $value );
			}
		}
		foreach ( (array) $own as $row ) {
			$wpdb->replace( $wpdb->options, array( 'option_name' => $row['option_name'], 'option_value' => $row['option_value'], 'autoload' => 'off' ) );
		}
		update_option( 'active_plugins', array( PBK_BASENAME ) );
		wp_cache_flush();
		PBK_Schedules::ensure_events();
	}

	public static function ajax_start() {
		PBK_Plugin::guard();
		$what    = isset( $_POST['reset'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['reset'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$confirm = isset( $_POST['confirm_text'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_text'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $confirm !== 'RESET' ) {
			wp_send_json_error( array( 'message' => __( 'Type RESET to confirm.', 'pro-backups' ) ), 400 );
		}
		try {
			PBK_Plugin::send_job( self::start( $what ) );
		} catch ( PBK_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	public static function tab_reset() {
		?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Reset tools delete data. A full backup of the site is always created first, so you can undo a reset by restoring it.', 'pro-backups' ); ?></p></div>
		<form id="pbk-reset-form">
			<table class="form-table pbk-form" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Reset', 'pro-backups' ); ?></th><td><fieldset>
					<?php foreach ( self::labels() as $key => $label ) : ?>
						<label><input type="checkbox" name="reset[]" value="<?php echo esc_attr( $key ); ?>"> <?php echo esc_html( $label ); ?></label><br>
					<?php endforeach; ?>
				</fieldset></td></tr>
				<tr><th scope="row"><label for="pbk-confirm-text"><?php esc_html_e( 'Type RESET to confirm', 'pro-backups' ); ?></label></th><td><input id="pbk-confirm-text" name="confirm_text" class="regular-text" autocomplete="off" required pattern="RESET"></td></tr>
			</table>
			<?php submit_button( __( 'Back up and reset', 'pro-backups' ), 'delete' ); ?>
		</form>
		<?php
	}
}
