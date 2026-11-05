<?php
/**
 * Admin screen (Pro Backups menu).
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, Squiz.PHP.DiscouragedFunctions, WordPressVIPMinimum.Performance.WPQueryParams, WordPress.DB.DirectDatabaseQuery -- download streams a large file; SHOW TABLES for the exclusion list.

class PBK_Admin {
	const SLUG = 'pro-backups';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . PBK_BASENAME, array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_pbk_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_pbk_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	private static function file_action_guard( $action ) {
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( ! current_user_can( PBK_Plugin::CAP ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_key( $_GET['_wpnonce'] ) : '', $action . $file ) ) {
			wp_die( esc_html__( 'Permission denied.', 'pro-backups' ), 403 );
		}
		try {
			return PBK_Storage::find( $file );
		} catch ( PBK_Exception $e ) {
			wp_die( esc_html( $e->getMessage() ), 404 );
		}
	}

	public static function action_url( $action, $file ) {
		// Raw URL (no &amp;): also used in JSON responses. Escape with esc_url() when printing.
		return add_query_arg( array( 'action' => $action, 'file' => rawurlencode( $file ), '_wpnonce' => wp_create_nonce( $action . $file ) ), admin_url( 'admin-post.php' ) );
	}

	/** Streams a backup (storage is not web-accessible). */
	public static function download() {
		$path = self::file_action_guard( 'pbk_download' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		$fh = fopen( $path, 'rb' );
		while ( ! feof( $fh ) && ! connection_aborted() ) {
			echo fread( $fh, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput -- binary file
			flush();
		}
		fclose( $fh );
		exit;
	}

	public static function delete() {
		$path = self::file_action_guard( 'pbk_delete' );
		$ok   = @unlink( $path );
		wp_safe_redirect( self::url( 'backups', array( 'pbk_notice' => $ok ? 'deleted' : 'delete_failed' ) ) );
		exit;
	}

	public static function notices() {
		$notice = isset( $_GET['pbk_notice'] ) ? sanitize_key( $_GET['pbk_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$map    = array(
			'deleted'       => array( 'success', __( 'Backup deleted.', 'pro-backups' ) ),
			'delete_failed' => array( 'error', __( 'Could not delete the backup (check file permissions).', 'pro-backups' ) ),
			'saved'         => array( 'success', __( 'Settings saved.', 'pro-backups' ) ),
		);
		if ( isset( $map[ $notice ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $notice ][0] ), esc_html( $map[ $notice ][1] ) );
		}
	}

	/** @return array slug => [label, callback] */
	public static function tabs() {
		return apply_filters(
			'pbk_admin_tabs',
			array(
				'backups' => array( __( 'Backups', 'pro-backups' ), array( __CLASS__, 'tab_backups' ) ),
				'create'    => array( __( 'Create backup', 'pro-backups' ), array( __CLASS__, 'tab_create' ) ),
				'schedules' => array( __( 'Schedules', 'pro-backups' ), array( 'PBK_Schedules', 'tab_schedules' ) ),
				'reset'     => array( __( 'Reset', 'pro-backups' ), array( 'PBK_Reset', 'tab_reset' ) ),
				'settings'  => array( __( 'Settings', 'pro-backups' ), array( 'PBK_Schedules', 'tab_settings' ) ),
			)
		);
	}

	public static function menu() {
		add_menu_page( __( 'Pro Backups', 'pro-backups' ), __( 'Pro Backups', 'pro-backups' ), PBK_Plugin::CAP, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-backup', 76 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Backups', 'pro-backups' ) . '</a>' );
		return $links;
	}

	public static function url( $tab = 'backups', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'backups'; // phpcs:ignore WordPress.Security.NonceVerification
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'backups';
	}

	public static function assets( $hook ) {
		if ( $hook !== 'toplevel_page_' . self::SLUG ) {
			return;
		}
		wp_enqueue_style( 'pbk-admin', plugins_url( 'assets/admin.css', PBK_FILE ), array(), PBK_VERSION );
		wp_enqueue_script( 'pbk-admin', plugins_url( 'assets/admin.js', PBK_FILE ), array(), PBK_VERSION, true );
		wp_localize_script(
			'pbk-admin',
			'PBK',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( PBK_Plugin::NONCE ),
				'chunkSize' => PBK_Upload::chunk_size(),
				'free'      => PBK_Storage::free_space(),
				'i18n'      => array(
					/* translators: %s: file name */
					'uploading'    => __( 'Uploading %s ...', 'pro-backups' ),
					/* translators: %s: error message */
					'uploadFailed' => __( 'Upload failed: %s', 'pro-backups' ),
					/* translators: %d: attempt number */
					'retrying'     => __( 'Connection problem, retrying (%d)...', 'pro-backups' ),
					'notWpress'    => __( 'Only .wpress files can be restored.', 'pro-backups' ),
					'noSpace'      => __( 'Not enough disk space for this file.', 'pro-backups' ),
					'confirm'      => __( 'Restore', 'pro-backups' ),
					'cancel'       => __( 'Cancel', 'pro-backups' ),
					'close'        => __( 'Close', 'pro-backups' ),
					'unlock'       => __( 'Unlock', 'pro-backups' ),
					'password'     => __( 'Backup password', 'pro-backups' ),
					'login'        => __( 'Log in', 'pro-backups' ),
					'leave'        => __( 'A backup job is running. Leaving this page pauses it.', 'pro-backups' ),
					'deleteAsk'    => __( 'Delete this backup permanently?', 'pro-backups' ),
					'error'        => __( 'Error', 'pro-backups' ),
					'done'         => __( 'Done', 'pro-backups' ),
					'download'     => __( 'Download', 'pro-backups' ),
					'creating'     => __( 'Creating backup', 'pro-backups' ),
					'resetting'    => __( 'Backing up, then resetting', 'pro-backups' ),
				),
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( PBK_Plugin::CAP ) ) {
			return;
		}
		$current = self::current_tab();
		echo '<div class="wrap pbk"><h1>' . esc_html__( 'Pro Backups', 'pro-backups' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $slug => $tab ) {
			printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( self::url( $slug ) ), $slug === $current ? 'nav-tab-active' : '', esc_html( $tab[0] ) );
		}
		echo '</nav><div class="pbk-tab">';
		call_user_func( self::tabs()[ $current ][1] );
		echo '</div></div>';
		self::modal();
	}

	public static function tab_backups() {
		$dir = PBK_Storage::dir();
		?>
		<div class="pbk-drop" id="pbk-drop">
			<p><strong><?php esc_html_e( 'Drop a .wpress file here to upload and restore it', 'pro-backups' ); ?></strong></p>
			<p><?php esc_html_e( 'No size limit: the file is sent in small parts and the upload resumes after connection drops.', 'pro-backups' ); ?></p>
			<label class="button button-primary"><?php esc_html_e( 'Choose file', 'pro-backups' ); ?>
				<input type="file" id="pbk-file" accept=".wpress" hidden>
			</label>
		</div>
		<p class="description">
			<?php
			/* translators: %s: backups folder path */
			printf( esc_html__( 'Very large file or slow connection? Upload it by FTP/SFTP to %s and it appears in the list below.', 'pro-backups' ), '<code>' . esc_html( $dir ) . '</code>' );
			?>
		</p>
		<?php self::backups_table(); ?>
		<?php
	}

	/** Export options form, shared by "Create backup" and schedules. */
	public static function export_fields( array $v = array() ) {
		global $wpdb;
		$v      += array( 'exclude' => array( 'cache' ), 'compression' => '', 'exclude_files' => array(), 'exclude_tables' => array() );
		$labels  = array(
			'media'            => __( 'Media library (uploads)', 'pro-backups' ),
			'plugins'          => __( 'Plugins', 'pro-backups' ),
			'inactive-plugins' => __( 'Inactive plugins', 'pro-backups' ),
			'themes'           => __( 'Themes', 'pro-backups' ),
			'inactive-themes'  => __( 'Inactive themes', 'pro-backups' ),
			'cache'            => __( 'Cache folders', 'pro-backups' ),
			'database'         => __( 'Database', 'pro-backups' ),
			'spam'             => __( 'Spam comments', 'pro-backups' ),
			'revisions'        => __( 'Post revisions', 'pro-backups' ),
		);
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		?>
		<table class="form-table pbk-form" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Do not back up', 'pro-backups' ); ?></th><td><fieldset>
				<?php foreach ( $labels as $key => $label ) : ?>
					<label><input type="checkbox" name="exclude[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $v['exclude'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><br>
				<?php endforeach; ?>
			</fieldset></td></tr>
			<tr><th scope="row"><label for="pbk-exclude-files"><?php esc_html_e( 'Exclude files / folders', 'pro-backups' ); ?></label></th><td>
				<textarea id="pbk-exclude-files" name="exclude_files" rows="3" class="large-text code" placeholder="uploads/big-videos&#10;plugins/some-plugin"><?php echo esc_textarea( implode( "\n", $v['exclude_files'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One path per line, relative to wp-content.', 'pro-backups' ); ?></p>
			</td></tr>
			<tr><th scope="row"><label for="pbk-exclude-tables"><?php esc_html_e( 'Exclude tables', 'pro-backups' ); ?></label></th><td>
				<select id="pbk-exclude-tables" name="exclude_tables[]" multiple size="6" class="pbk-tables">
					<?php foreach ( $tables as $table ) : ?>
						<option value="<?php echo esc_attr( $table ); ?>" <?php selected( in_array( $table, $v['exclude_tables'], true ) ); ?>><?php echo esc_html( $table ); ?></option>
					<?php endforeach; ?>
				</select>
			</td></tr>
			<tr><th scope="row"><label for="pbk-compression"><?php esc_html_e( 'Compression', 'pro-backups' ); ?></label></th><td>
				<select id="pbk-compression" name="compression">
					<option value=""><?php esc_html_e( 'None (fastest)', 'pro-backups' ); ?></option>
					<option value="gzip" <?php selected( $v['compression'], 'gzip' ); ?> <?php disabled( ! function_exists( 'gzcompress' ) ); ?>>gzip</option>
					<option value="bzip2" <?php selected( $v['compression'], 'bzip2' ); ?> <?php disabled( ! function_exists( 'bzcompress' ) ); ?>>bzip2</option>
				</select>
			</td></tr>
			<tr><th scope="row"><label for="pbk-password"><?php esc_html_e( 'Encrypt with password', 'pro-backups' ); ?></label></th><td>
				<input type="password" id="pbk-password" name="password" class="regular-text" autocomplete="new-password" <?php disabled( ! function_exists( 'openssl_encrypt' ) ); ?>>
				<p class="description"><?php esc_html_e( 'Optional. AES-256. Without the password the backup cannot be restored.', 'pro-backups' ); ?></p>
			</td></tr>
		</table>
		<?php
	}

	public static function tab_create() {
		echo '<form id="pbk-export-form">';
		self::export_fields();
		submit_button( __( 'Create backup', 'pro-backups' ) );
		echo '</form>';
	}

	public static function backups_table() {
		$backups = PBK_Storage::backups();
		echo '<h2>' . esc_html__( 'Backups on this server', 'pro-backups' ) . '</h2>';
		if ( ! $backups ) {
			echo '<p>' . esc_html__( 'No backups yet.', 'pro-backups' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped pbk-backups"><thead><tr><th>' . esc_html__( 'File', 'pro-backups' ) . '</th><th>' . esc_html__( 'Date', 'pro-backups' ) . '</th><th>' . esc_html__( 'Size', 'pro-backups' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $backups as $b ) {
			printf(
				'<tr><td>%s%s</td><td>%s</td><td>%s</td><td class="pbk-actions">%s</td></tr>',
				esc_html( $b['name'] ),
				$b['dir'] === 'ai1wm-backups' ? ' <span class="pbk-tag">ai1wm-backups</span>' : '',
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b['mtime'] ) ),
				esc_html( size_format( $b['size'], 1 ) ),
				self::row_actions( $b ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in row_actions()
			);
		}
		echo '</tbody></table>';
	}

	public static function row_actions( array $b ) {
		$html  = sprintf( '<button type="button" class="button button-primary pbk-restore" data-file="%s">%s</button>', esc_attr( $b['name'] ), esc_html__( 'Restore', 'pro-backups' ) );
		$html .= sprintf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'pbk_download', $b['name'] ) ), esc_html__( 'Download', 'pro-backups' ) );
		$html .= sprintf( '<a class="button pbk-delete" href="%s">%s</a>', esc_url( self::action_url( 'pbk_delete', $b['name'] ) ), esc_html__( 'Delete', 'pro-backups' ) );
		return apply_filters( 'pbk_row_actions', $html, $b );
	}

	private static function modal() {
		?>
		<div class="pbk-modal" id="pbk-modal" hidden>
			<div class="pbk-modal-box" role="dialog" aria-modal="true" aria-labelledby="pbk-modal-title">
				<h2 id="pbk-modal-title"></h2>
				<div class="pbk-progress" hidden><div class="pbk-progress-bar"></div><span class="pbk-progress-text"></span></div>
				<p class="pbk-modal-message" aria-live="polite"></p>
				<form class="pbk-modal-form" hidden></form>
				<details class="pbk-log-wrap" hidden><summary><?php esc_html_e( 'Log', 'pro-backups' ); ?></summary><pre class="pbk-log"></pre></details>
				<div class="pbk-modal-actions"></div>
			</div>
		</div>
		<?php
	}
}
