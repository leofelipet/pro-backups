<?php
/**
 * Plugin Name:       Pro Backups
 * Plugin URI:        https://github.com/leofelipet/pro-backups
 * Description:       Free, unlimited backup and restore of .wpress archives. No upload size limit, no paywall.
 * Version:           0.1.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Leonardo Felipe
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pro-backups
 */

defined( 'ABSPATH' ) || exit;

define( 'PBK_VERSION', '0.1.0' );
define( 'PBK_FILE', __FILE__ );
define( 'PBK_DIR', __DIR__ );
define( 'PBK_BASENAME', plugin_basename( __FILE__ ) );

foreach ( array( 'archive', 'replace', 'sql-importer', 'storage', 'job', 'importer', 'upload', 'admin', 'plugin' ) as $pbk_file ) {
	require_once PBK_DIR . "/includes/class-pbk-$pbk_file.php";
}
unset( $pbk_file );

add_action( 'plugins_loaded', array( 'PBK_Plugin', 'init' ) );
