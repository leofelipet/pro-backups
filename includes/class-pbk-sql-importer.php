<?php
/**
 * Resumable import of an AI1WM-style database.sql (one statement ends with ";" at end of line).
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- streams database.sql from disk.

class PBK_Sql_Importer {
	const PREFIX_PLACEHOLDER = 'SERVMASK_PREFIX_';

	private $prefix;
	private $replace;
	private $exec;

	/**
	 * @param string       $prefix  New table prefix.
	 * @param PBK_Replace  $replace Value replacements.
	 * @param callable     $exec    function( string $sql ): string  — returns '' on success, else the error text.
	 */
	public function __construct( $prefix, PBK_Replace $replace, callable $exec ) {
		$this->prefix  = $prefix;
		$this->replace = $replace;
		$this->exec    = $exec;
	}

	/**
	 * Runs statements from $state['pos'] until EOF or $deadline.
	 * $state: pos (byte offset), queries, errors (list of first error messages), failed (count).
	 *
	 * @return bool true when the whole file has been imported.
	 */
	public function run( $file, array &$state, $deadline ) {
		$state += array( 'pos' => 0, 'queries' => 0, 'failed' => 0, 'errors' => array() );
		$fh = @fopen( $file, 'rb' );
		if ( ! $fh ) {
			throw new PBK_Exception( __( 'Could not open the database file.', 'pro-backups' ) );
		}
		fseek( $fh, $state['pos'] );
		$this->exec_quiet( "SET SESSION sql_mode = ''" );
		$this->exec_quiet( 'SET FOREIGN_KEY_CHECKS = 0' );
		$this->exec_quiet( 'START TRANSACTION' );
		$query = '';
		$done  = true;
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$query .= $line;
			if ( ! preg_match( '/;\s*$/S', $query ) ) {
				continue;
			}
			$atomic       = $this->statement( trim( $query ), $state );
			$query        = '';
			$state['pos'] = ftell( $fh );
			// Never stop inside the options table: the next request could not boot WordPress without it.
			if ( ! $atomic && microtime( true ) >= $deadline ) {
				$done = fgets( $fh ) === false;
				break;
			}
		}
		if ( $done && trim( $query ) !== '' ) {
			$this->statement( trim( $query ), $state ); // last statement without trailing ";"
			$state['pos'] = ftell( $fh );
		}
		$this->exec_quiet( 'COMMIT' );
		$this->exec_quiet( 'SET FOREIGN_KEY_CHECKS = 1' );
		fclose( $fh );
		return $done;
	}

	/** @return bool true while inside the options table (from its DROP until its last INSERT). */
	private function statement( $query, array &$state ) {
		$state += array( 'atomic' => false );
		// Transactions are managed per request (a request may stop between START and COMMIT).
		if ( preg_match( '/^(START TRANSACTION|COMMIT|BEGIN)\s*;?$/i', $query ) || $query === '' ) {
			return $state['atomic'];
		}
		$query = str_ireplace( self::PREFIX_PLACEHOLDER, $this->prefix, $query );
		if ( preg_match( '/^\s*(?:DROP TABLE(?: IF EXISTS)?|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO|DROP VIEW(?: IF EXISTS)?|CREATE(?:\s+\w+(?:=\S+)?)*\s+VIEW)\s+`?([^`\s(]+)`?/i', $query, $m ) ) {
			$state['atomic'] = strcasecmp( $m[1], $this->prefix . 'options' ) === 0;
		}
		$ddl   = (bool) preg_match( '/^\s*CREATE\s+(TABLE|(ALGORITHM|DEFINER|SQL|VIEW|OR REPLACE)\b)/i', $query );
		if ( ! $ddl ) {
			$query = $this->replace->sql( $query );
		} else {
			$query = preg_replace( '/\s+DEFINER\s*=\s*\S+/i', '', $query ); // views from another server's user
		}
		$state['queries']++;
		$error = call_user_func( $this->exec, $query );
		// Collation/charset unknown on this server (e.g. MySQL 8 <-> MariaDB 11): fall back and retry.
		for ( $i = 0; $error !== '' && $i < 5 && preg_match( "/Unknown (collation|character set): '([^']+)'/i", $error, $m ); $i++ ) {
			$fallback = stripos( $m[2], 'utf8mb4' ) === 0 ? 'utf8mb4_unicode_520_ci' : ( $m[1] === 'collation' ? 'utf8_general_ci' : 'utf8mb4' );
			$query    = str_ireplace( $m[2], $fallback, $query );
			$error    = call_user_func( $this->exec, $query );
		}
		if ( $error !== '' ) {
			$state['failed']++;
			if ( count( $state['errors'] ) < 20 ) {
				$state['errors'][] = $error . ' — ' . substr( $query, 0, 200 );
			}
		}
		return $state['atomic'];
	}

	private function exec_quiet( $sql ) {
		call_user_func( $this->exec, $sql );
	}
}
