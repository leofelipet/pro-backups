<?php
/**
 * WP-CLI: wp pro-backups list|restore|backup|delete
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- deleting a backup file.

class PBK_CLI {
	/**
	 * Lists backups on this server.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml. Default: table.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array_map(
			function ( $b ) {
				return array( 'name' => $b['name'], 'size' => size_format( $b['size'], 1 ), 'date' => wp_date( 'Y-m-d H:i', $b['mtime'] ), 'folder' => $b['dir'] );
			},
			PBK_Storage::backups()
		);
		WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'name', 'size', 'date', 'folder' ) );
	}

	/**
	 * Restores a .wpress backup (no size limit).
	 *
	 * <file>
	 * : Backup name in wp-content/pro-backups (or ai1wm-backups), or a path to a .wpress file.
	 *
	 * [--password=<password>]
	 * : Password of an encrypted backup.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 */
	public function restore( $args, $assoc ) {
		$file = is_file( $args[0] ) ? realpath( $args[0] ) : null;
		try {
			$file = $file ?: PBK_Storage::find( basename( $args[0] ) );
		} catch ( PBK_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$job = PBK_Importer::start(
			$file,
			array(
				'password'  => isset( $assoc['password'] ) ? $assoc['password'] : '',
				'confirmed' => false,
			)
		);
		$this->drive( $job, ! empty( $assoc['yes'] ) );
	}

	/**
	 * Creates a backup.
	 *
	 * [--name=<name>]
	 * : File name (.wpress). Default: site-date.
	 *
	 * [--password=<password>]
	 * : Encrypt the backup (AES-256).
	 *
	 * [--compression=<type>]
	 * : gzip or bzip2.
	 *
	 * [--exclude=<what>]
	 * : Comma list: media,plugins,themes,inactive-plugins,inactive-themes,cache,database,spam,revisions.
	 *
	 * [--exclude-files=<paths>]
	 * : Comma list of paths relative to wp-content.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma list of table names.
	 */
	public function backup( $args, $assoc ) {
		try {
			$job = PBK_Exporter::start( PBK_Exporter::options_from_cli( $assoc ) );
		} catch ( PBK_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$status = $this->drive( $job, true );
		WP_CLI::log( $status['result']['path'] );
	}

	/**
	 * Deletes a backup.
	 *
	 * <file>
	 * : Backup name.
	 */
	public function delete( $args ) {
		try {
			$path = PBK_Storage::find( $args[0] );
		} catch ( PBK_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		unlink( $path ) ? WP_CLI::success( sprintf( /* translators: %s: file name */ __( 'Deleted %s', 'pro-backups' ), $args[0] ) ) : WP_CLI::error( sprintf( /* translators: %s: file name */ __( 'Could not delete %s', 'pro-backups' ), $args[0] ) );
	}

	private function drive( PBK_Job $job, $yes ) {
		$last = '';
		while ( true ) {
			$status = $job->run( 5 ); // short slices so progress is printed
			if ( $status['message'] !== $last ) {
				WP_CLI::log( sprintf( '[%3d%%] %s', $status['progress'], $status['message'] ) );
				$last = $status['message'];
			}
			if ( $status['status'] === 'running' ) {
				continue;
			}
			if ( $status['status'] === 'input' ) {
				if ( $status['need'] === 'password' ) {
					if ( ! WP_CLI\Utils\isPiped() && function_exists( 'readline' ) && ! $yes ) {
						PBK_Importer::input( $job, array( 'password' => readline( 'Password: ' ) ) );
						continue;
					}
					$job->delete();
					WP_CLI::error( __( 'Encrypted backup: pass --password=<password> (or it is wrong).', 'pro-backups' ) );
				}
				if ( ! $yes ) {
					WP_CLI::confirm( $status['message'] );
				}
				PBK_Importer::input( $job, array( 'confirm' => true ) );
				continue;
			}
			foreach ( array_slice( $job->data['log'], -10 ) as $line ) {
				WP_CLI::debug( $line, 'pro-backups' );
			}
			if ( $status['status'] === 'done' ) {
				WP_CLI::success( $status['message'] );
				return $status;
			}
			WP_CLI::error( $status['message'] );
		}
	}
}
