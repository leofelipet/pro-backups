<?php
/**
 * Hooks and AJAX endpoints.
 *
 * Starting a job needs manage_options + nonce. Running it / answering it needs the job token
 * only (also for logged-out requests), because a restore replaces the users table mid-way.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification -- job endpoints are authorized by a per-job random token (checked in PBK_Job::authorize()), start endpoints by guard().

class PBK_Plugin {
	const CAP   = 'manage_options';
	const NONCE = 'pbk';

	public static function init() {
		foreach ( array( 'run', 'input', 'cancel' ) as $action ) {
			add_action( "wp_ajax_pbk_$action", array( __CLASS__, "ajax_$action" ) );
			add_action( "wp_ajax_nopriv_pbk_$action", array( __CLASS__, "ajax_$action" ) );
		}
		add_action( 'wp_ajax_pbk_restore_start', array( __CLASS__, 'ajax_restore_start' ) );
		add_action( 'wp_ajax_pbk_export_start', array( __CLASS__, 'ajax_export_start' ) );
		add_action( 'pbk_cleanup', array( 'PBK_Job', 'cleanup' ) );
		add_action( 'pbk_cleanup', array( 'PBK_Upload', 'cleanup' ) );
		PBK_Upload::init();
		if ( is_admin() ) {
			PBK_Admin::init();
		}
		if ( ! wp_next_scheduled( 'pbk_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pbk_cleanup' );
		}
		PBK_Schedules::init();
		PBK_Reset::init();
		add_action( 'pbk_restore_done', array( 'PBK_Schedules', 'ensure_events' ) );
		$job = self::is_job_request() ? PBK_Job::load( sanitize_key( wp_unslash( $_REQUEST['id'] ?? '' ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification -- token checked by the handler
		if ( $job && $job->data['type'] === 'import' ) {
			// The active theme may be half-restored: don't load its functions.php while a restore runs.
			// (Only for imports: exports and resets must see the real template/stylesheet options.)
			add_filter( 'pre_option_template', array( __CLASS__, 'no_theme' ) );
			add_filter( 'pre_option_stylesheet', array( __CLASS__, 'no_theme' ) );
		}
	}

	public static function is_job_request() {
		return wp_doing_ajax() && isset( $_REQUEST['action'] ) && in_array( $_REQUEST['action'], array( 'pbk_run', 'pbk_input', 'pbk_cancel' ), true ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function no_theme() {
		return 'pbk-no-theme';
	}

	/** Checks capability + nonce for endpoints that start something. */
	public static function guard() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied. Reload the page and try again.', 'pro-backups' ) ), 403 );
		}
	}

	private static function job_from_request() {
		$id    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$job   = PBK_Job::authorize( $id, $token );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Job not found or expired.', 'pro-backups' ) ), 404 );
		}
		return $job;
	}

	public static function send_job( PBK_Job $job, array $status = null ) {
		$status          = $status ?: $job->public_status();
		$status['token'] = $job->data['token'];
		wp_send_json_success( $status );
	}

	public static function ajax_restore_start() {
		self::guard();
		try {
			$file = PBK_Storage::find( isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : '' );
			self::send_job( PBK_Importer::start( $file ) );
		} catch ( PBK_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	public static function ajax_export_start() {
		self::guard();
		try {
			self::send_job( PBK_Exporter::start( PBK_Exporter::options_from_request( wp_unslash( $_POST ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in options_from_request()
		} catch ( PBK_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	public static function ajax_run() {
		$job = self::job_from_request();
		self::send_job( $job, $job->run( PBK_Job::request_budget() ) );
	}

	public static function ajax_input() {
		$job   = self::job_from_request();
		$input = array();
		if ( isset( $_POST['password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$input['password'] = wp_unslash( $_POST['password'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw password
		}
		if ( ! empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$input['confirm'] = true;
		}
		if ( $job->data['type'] === 'import' ) {
			PBK_Importer::input( $job, $input );
		}
		self::send_job( $job );
	}

	/** Cancel is only allowed before anything was changed (waiting for input or still validating). */
	public static function ajax_cancel() {
		$job  = self::job_from_request();
		$step = isset( $job->data['state']['step'] ) ? $job->data['state']['step'] : '';
		if ( $job->data['type'] === 'import' && ! in_array( $step, array( 'validate', 'crc', 'password', 'confirm' ), true ) && $job->is_active() ) {
			wp_send_json_error( array( 'message' => __( 'The restore is already changing the site and cannot be cancelled safely.', 'pro-backups' ) ), 409 );
		}
		$job->data['status']  = 'cancelled';
		$job->data['message'] = __( 'Cancelled.', 'pro-backups' );
		$job->data['state']['password'] = '';
		if ( $job->data['type'] === 'export' ) {
			PBK_Exporter::on_error( $job );
		}
		$job->save();
		self::send_job( $job );
	}
}
