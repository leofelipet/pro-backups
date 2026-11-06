<?php
/**
 * Scheduled backups (WP-Cron) and retention.
 *
 * A cron tick runs a backup for one request budget; unfinished jobs re-schedule themselves
 * (pbk_job_continue) so big sites are backed up across several cron requests.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPressVIPMinimum.Performance.WPQueryParams, WordPress.Security.NonceVerification -- nonces are verified in check() / guard(); 'exclude' is a backup option, not a WP_Query argument.

class PBK_Schedules {
	const OPTION   = 'pbk_schedules';
	const SETTINGS = 'pbk_settings';
	const HOOK     = 'pbk_schedule_run';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'cron_run' ) );
		add_action( 'pbk_job_continue', array( __CLASS__, 'cron_continue' ) );
		add_action( 'pbk_backup_done', array( __CLASS__, 'apply_retention' ) );
		add_action( 'admin_post_pbk_schedule_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_pbk_schedule_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_pbk_settings_save', array( __CLASS__, 'handle_settings' ) );
		add_action( 'wp_ajax_pbk_schedule_run', array( __CLASS__, 'ajax_run_now' ) );
	}

	public static function cron_schedules( $schedules ) {
		$schedules['weekly']  = isset( $schedules['weekly'] ) ? $schedules['weekly'] : array( 'interval' => WEEK_IN_SECONDS, 'display' => __( 'Once Weekly', 'pro-backups' ) );
		$schedules['monthly'] = array( 'interval' => MONTH_IN_SECONDS, 'display' => __( 'Once Monthly', 'pro-backups' ) );
		return $schedules;
	}

	public static function frequencies() {
		return array(
			'hourly'     => __( 'Every hour', 'pro-backups' ),
			'twicedaily' => __( 'Twice a day', 'pro-backups' ),
			'daily'      => __( 'Every day', 'pro-backups' ),
			'weekly'     => __( 'Every week', 'pro-backups' ),
			'monthly'    => __( 'Every month', 'pro-backups' ),
		);
	}

	public static function all() {
		return (array) get_option( self::OPTION, array() );
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::SETTINGS, array() ), array( 'keep_count' => 0, 'keep_days' => 0, 'max_total_mb' => 0 ) );
	}

	/** Creates or updates a schedule and (re)registers its cron event. */
	public static function save( array $schedule ) {
		$all = self::all();
		$id  = ! empty( $schedule['id'] ) && isset( $all[ $schedule['id'] ] ) ? $schedule['id'] : 's' . strtolower( wp_generate_password( 8, false ) );
		$old = isset( $all[ $id ] ) ? $all[ $id ] : array();
		$schedule = array_merge(
			array( 'last_run' => 0, 'last_status' => '', 'last_message' => '' ),
			$old,
			array(
				'id'        => $id,
				'name'      => sanitize_text_field( $schedule['name'] ?: __( 'Backup', 'pro-backups' ) ),
				'frequency' => array_key_exists( $schedule['frequency'], self::frequencies() ) ? $schedule['frequency'] : 'daily',
				'time'      => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $schedule['time'] ) ? $schedule['time'] : '03:00',
				'enabled'   => ! empty( $schedule['enabled'] ),
				'options'   => $schedule['options'],
			)
		);
		$all[ $id ] = $schedule;
		update_option( self::OPTION, $all, false );
		self::register( $schedule );
		return $id;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
	}

	private static function register( array $schedule ) {
		wp_clear_scheduled_hook( self::HOOK, array( $schedule['id'] ) );
		if ( $schedule['enabled'] ) {
			wp_schedule_event( self::next_time( $schedule['time'] ), $schedule['frequency'], self::HOOK, array( $schedule['id'] ) );
		}
	}

	/** Next occurrence of HH:MM in the site's timezone, as a UTC timestamp. */
	public static function next_time( $hhmm ) {
		$tz   = wp_timezone();
		$next = new DateTime( 'today ' . $hhmm, $tz );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/** Re-registers events (e.g. after a restore replaced the cron option). */
	public static function ensure_events() {
		foreach ( self::all() as $schedule ) {
			if ( $schedule['enabled'] && ! wp_next_scheduled( self::HOOK, array( $schedule['id'] ) ) ) {
				self::register( $schedule );
			}
		}
	}

	public static function start( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			throw new PBK_Exception( __( 'Schedule not found.', 'pro-backups' ) );
		}
		$options          = $all[ $id ]['options'];
		$options['label'] = $all[ $id ]['name'];
		$job              = PBK_Exporter::start( $options );
		$job->data['schedule'] = $id;
		$job->save();
		return $job;
	}

	public static function cron_run( $id ) {
		try {
			$job = self::start( $id );
		} catch ( PBK_Exception $e ) {
			self::record( $id, 'error', $e->getMessage() );
			return;
		}
		self::cron_continue( $job->id );
	}

	public static function cron_continue( $job_id ) {
		$job = PBK_Job::load( $job_id );
		if ( ! $job ) {
			return;
		}
		$status = $job->run( PBK_Job::request_budget() );
		if ( $status['status'] === 'running' ) {
			wp_schedule_single_event( time() + 5, 'pbk_job_continue', array( $job_id ) );
			spawn_cron();
		} elseif ( ! empty( $job->data['schedule'] ) ) {
			self::record( $job->data['schedule'], $status['status'], $status['message'] );
		}
	}

	private static function record( $id, $status, $message ) {
		$all = self::all();
		if ( isset( $all[ $id ] ) ) {
			$all[ $id ]['last_run']     = time();
			$all[ $id ]['last_status']  = $status;
			$all[ $id ]['last_message'] = $message;
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Deletes the oldest backups in our folder beyond the configured limits.
	 * The backup that was just created is never deleted.
	 */
	public static function apply_retention( $job = null ) {
		$set     = self::settings();
		$keep    = isset( $job->data['result']['file'] ) ? $job->data['result']['file'] : '';
		$backups = array_values(
			array_filter(
				PBK_Storage::backups(),
				function ( $b ) {
					return $b['dir'] === 'pro-backups';
				}
			)
		); // newest first
		$total   = 0;
		$deleted = array();
		foreach ( $backups as $i => $b ) {
			$total += $b['size'];
			$too_many = $set['keep_count'] > 0 && $i >= $set['keep_count'];
			$too_old  = $set['keep_days'] > 0 && $b['mtime'] < time() - $set['keep_days'] * DAY_IN_SECONDS;
			$too_big  = $set['max_total_mb'] > 0 && $total > $set['max_total_mb'] * MB_IN_BYTES;
			if ( $b['name'] !== $keep && ( $too_many || $too_old || $too_big ) && self::remove( $b['path'] ) ) {
				$deleted[] = $b['name'];
				$total    -= $b['size'];
			}
		}
		if ( $deleted && $job ) {
			$job->log( sprintf( /* translators: %s: list of file names */ __( 'Retention removed: %s', 'pro-backups' ), implode( ', ', $deleted ) ) );
			$job->save();
		}
		return $deleted;
	}

	private static function remove( $path ) {
		wp_delete_file( $path );
		clearstatcache( true, $path );
		return ! file_exists( $path );
	}

	// ---- admin handlers ---------------------------------------------------------------------

	private static function check( $action ) {
		if ( ! current_user_can( PBK_Plugin::CAP ) || ! check_admin_referer( $action ) ) {
			wp_die( esc_html__( 'Permission denied.', 'pro-backups' ), 403 );
		}
	}

	public static function handle_save() {
		self::check( 'pbk_schedule_save' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in save() / options_from_request()
		$opts = PBK_Exporter::options_from_request( $post );
		if ( $opts['password'] === '' && ! empty( $post['id'] ) ) {
			$all              = self::all();
			$opts['password'] = isset( $all[ $post['id'] ]['options']['password'] ) && empty( $post['clear_password'] ) ? $all[ $post['id'] ]['options']['password'] : '';
		}
		try {
			PBK_Archive_Writer::check( $opts['password'], $opts['compression'] );
			self::save(
				array(
					'id'        => isset( $post['id'] ) ? sanitize_key( $post['id'] ) : '',
					'name'      => isset( $post['name'] ) ? $post['name'] : '',
					'frequency' => isset( $post['frequency'] ) ? sanitize_key( $post['frequency'] ) : 'daily',
					'time'      => isset( $post['time'] ) ? sanitize_text_field( $post['time'] ) : '03:00',
					'enabled'   => ! empty( $post['enabled'] ),
					'options'   => $opts,
				)
			);
		} catch ( PBK_Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
		wp_safe_redirect( PBK_Admin::url( 'schedules', array( 'pbk_notice' => 'saved' ) ) );
		exit;
	}

	public static function handle_delete() {
		self::check( 'pbk_schedule_delete' );
		self::delete( isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '' );
		wp_safe_redirect( PBK_Admin::url( 'schedules', array( 'pbk_notice' => 'saved' ) ) );
		exit;
	}

	public static function handle_settings() {
		self::check( 'pbk_settings_save' );
		update_option(
			self::SETTINGS,
			array(
				'keep_count'   => isset( $_POST['keep_count'] ) ? absint( $_POST['keep_count'] ) : 0,
				'keep_days'    => isset( $_POST['keep_days'] ) ? absint( $_POST['keep_days'] ) : 0,
				'max_total_mb' => isset( $_POST['max_total_mb'] ) ? absint( $_POST['max_total_mb'] ) : 0,
			),
			false
		);
		wp_safe_redirect( PBK_Admin::url( 'settings', array( 'pbk_notice' => 'saved' ) ) );
		exit;
	}

	public static function ajax_run_now() {
		PBK_Plugin::guard();
		try {
			PBK_Plugin::send_job( self::start( isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- guard() checked it
		} catch ( PBK_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}
	}

	// ---- admin tabs -------------------------------------------------------------------------

	public static function tab_schedules() {
		$all  = self::all();
		$edit = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $edit !== '' ) {
			self::form( isset( $all[ $edit ] ) ? $all[ $edit ] : array() );
			return;
		}
		echo '<p><a class="button button-primary" href="' . esc_url( PBK_Admin::url( 'schedules', array( 'edit' => 'new' ) ) ) . '">' . esc_html__( 'Add schedule', 'pro-backups' ) . '</a></p>';
		if ( ! $all ) {
			echo '<p>' . esc_html__( 'No schedules yet.', 'pro-backups' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'pro-backups' ) . '</th><th>' . esc_html__( 'When', 'pro-backups' ) . '</th><th>' . esc_html__( 'Next run', 'pro-backups' ) . '</th><th>' . esc_html__( 'Last run', 'pro-backups' ) . '</th><th></th></tr></thead><tbody>';
			$freq = self::frequencies();
			foreach ( $all as $s ) {
				$next = wp_next_scheduled( self::HOOK, array( $s['id'] ) );
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="pbk-actions"><button type="button" class="button pbk-run-now" data-id="%s">%s</button><a class="button" href="%s">%s</a><form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="pbk_schedule_delete"><input type="hidden" name="id" value="%s"><button class="button pbk-delete">%s</button></form></td></tr>',
					esc_html( $s['name'] ),
					esc_html( $freq[ $s['frequency'] ] . ' ' . $s['time'] . ( $s['enabled'] ? '' : ' (' . __( 'paused', 'pro-backups' ) . ')' ) ),
					esc_html( $next ? wp_date( 'Y-m-d H:i', $next ) : '—' ),
					esc_html( $s['last_run'] ? wp_date( 'Y-m-d H:i', $s['last_run'] ) . ' — ' . $s['last_message'] : '—' ),
					esc_attr( $s['id'] ),
					esc_html__( 'Run now', 'pro-backups' ),
					esc_url( PBK_Admin::url( 'schedules', array( 'edit' => $s['id'] ) ) ),
					esc_html__( 'Edit', 'pro-backups' ),
					esc_url( admin_url( 'admin-post.php' ) ),
					wp_nonce_field( 'pbk_schedule_delete', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput
					esc_attr( $s['id'] ),
					esc_html__( 'Delete', 'pro-backups' )
				);
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">' . esc_html__( 'Schedules use WP-Cron: they run when the site gets visits. For exact timing, call wp-cron.php from a real server cron job.', 'pro-backups' ) . '</p>';
	}

	private static function form( array $s ) {
		$s += array( 'id' => '', 'name' => '', 'frequency' => 'daily', 'time' => '03:00', 'enabled' => true, 'options' => array() );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pbk_schedule_save' ); ?>
			<input type="hidden" name="action" value="pbk_schedule_save">
			<input type="hidden" name="id" value="<?php echo esc_attr( $s['id'] ); ?>">
			<table class="form-table pbk-form" role="presentation">
				<tr><th scope="row"><label for="pbk-name"><?php esc_html_e( 'Name', 'pro-backups' ); ?></label></th><td><input id="pbk-name" name="name" class="regular-text" value="<?php echo esc_attr( $s['name'] ); ?>" required></td></tr>
				<tr><th scope="row"><label for="pbk-frequency"><?php esc_html_e( 'Frequency', 'pro-backups' ); ?></label></th><td>
					<select id="pbk-frequency" name="frequency">
						<?php foreach ( self::frequencies() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['frequency'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label for="pbk-time"><?php esc_html_e( 'starting at', 'pro-backups' ); ?></label> <input id="pbk-time" type="time" name="time" value="<?php echo esc_attr( $s['time'] ); ?>">
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Active', 'pro-backups' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Run this schedule', 'pro-backups' ); ?></label></td></tr>
			</table>
			<?php PBK_Admin::export_fields( $s['options'] ); ?>
			<?php if ( ! empty( $s['options']['password'] ) ) : ?>
				<p><label><input type="checkbox" name="clear_password" value="1"> <?php esc_html_e( 'Remove the saved encryption password', 'pro-backups' ); ?></label></p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'A scheduled encryption password is stored in the database so backups can run unattended. Leave it empty to keep the saved one.', 'pro-backups' ); ?></p>
			<?php submit_button( __( 'Save schedule', 'pro-backups' ) ); ?>
		</form>
		<?php
	}

	public static function tab_settings() {
		$set = self::settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'pbk_settings_save' ); ?>
			<input type="hidden" name="action" value="pbk_settings_save">
			<h2><?php esc_html_e( 'Retention', 'pro-backups' ); ?></h2>
			<p><?php esc_html_e( 'After every backup, the oldest backups in the Pro Backups folder beyond these limits are deleted. 0 = no limit.', 'pro-backups' ); ?></p>
			<table class="form-table pbk-form" role="presentation">
				<tr><th scope="row"><label for="pbk-keep-count"><?php esc_html_e( 'Keep at most', 'pro-backups' ); ?></label></th><td><input id="pbk-keep-count" type="number" min="0" name="keep_count" value="<?php echo esc_attr( $set['keep_count'] ); ?>" class="small-text"> <?php esc_html_e( 'backups', 'pro-backups' ); ?></td></tr>
				<tr><th scope="row"><label for="pbk-keep-days"><?php esc_html_e( 'Delete backups older than', 'pro-backups' ); ?></label></th><td><input id="pbk-keep-days" type="number" min="0" name="keep_days" value="<?php echo esc_attr( $set['keep_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'pro-backups' ); ?></td></tr>
				<tr><th scope="row"><label for="pbk-max-total"><?php esc_html_e( 'Total size limit', 'pro-backups' ); ?></label></th><td><input id="pbk-max-total" type="number" min="0" name="max_total_mb" value="<?php echo esc_attr( $set['max_total_mb'] ); ?>" class="small-text"> MB</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
