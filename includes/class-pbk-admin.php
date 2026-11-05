<?php
/**
 * Admin screen (Pro Backups menu).
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

class PBK_Admin {
	const SLUG = 'pro-backups';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . PBK_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/** @return array slug => [label, callback] */
	public static function tabs() {
		return apply_filters(
			'pbk_admin_tabs',
			array(
				'backups' => array( __( 'Backups', 'pro-backups' ), array( __CLASS__, 'tab_backups' ) ),
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
					'uploading'    => __( 'Uploading %s ...', 'pro-backups' ),
					'uploadFailed' => __( 'Upload failed: %s', 'pro-backups' ),
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
		$html = sprintf( '<button type="button" class="button pbk-restore" data-file="%s">%s</button>', esc_attr( $b['name'] ), esc_html__( 'Restore', 'pro-backups' ) );
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
