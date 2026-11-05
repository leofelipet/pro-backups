<?php
/**
 * Uninstall: removes settings, schedules and cron events. Backup files in wp-content/pro-backups
 * are kept on purpose (they are the user's data); delete that folder manually if not needed.
 *
 * @package ProBackups
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pbk_settings' );
delete_option( 'pbk_schedules' );
wp_unschedule_hook( 'pbk_schedule_run' );
wp_unschedule_hook( 'pbk_job_continue' );
wp_unschedule_hook( 'pbk_cleanup' );
