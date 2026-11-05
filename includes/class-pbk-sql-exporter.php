<?php
/**
 * Resumable dump of this site's tables in the AI1WM database.sql format:
 * table prefix replaced by SERVMASK_PREFIX_, one statement per line ending with ";".
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- dumping tables needs direct, uncached queries; identifiers come from SHOW TABLES and are quoted with ident().

class PBK_Sql_Exporter {
	const ROWS = 500;

	private $prefix;
	private $where;

	/**
	 * @param string $prefix Current table prefix.
	 * @param array  $where  table (unprefixed) => extra SQL condition rows must satisfy.
	 */
	public function __construct( $prefix, array $where = array() ) {
		$this->prefix = $prefix;
		$this->where  = $where;
	}

	/** Tables and views of this site, minus $exclude (full names). */
	public function tables( array $exclude = array() ) {
		global $wpdb;
		$like = $wpdb->esc_like( $this->prefix ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( ! in_array( $row[0], $exclude, true ) && strpos( $row[0], $this->prefix ) === 0 ) {
				$out[] = array( 'name' => $row[0], 'view' => isset( $row[1] ) && stripos( $row[1], 'VIEW' ) !== false );
			}
		}
		// Views last: they may depend on tables.
		usort( $out, function ( $a, $b ) {
			return (int) $a['view'] - (int) $b['view'];
		} );
		return $out;
	}

	/** Quotes a table/column identifier (names come from SHOW TABLES / SHOW KEYS, quoted anyway). */
	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private function placeholder( $name ) {
		return PBK_Sql_Importer::PREFIX_PLACEHOLDER . substr( $name, strlen( $this->prefix ) );
	}

	/** "`wp_x`" => "`SERVMASK_PREFIX_x`" inside DDL. */
	private function ddl( $sql ) {
		return str_replace( '`' . $this->prefix, '`' . PBK_Sql_Importer::PREFIX_PLACEHOLDER, $sql );
	}

	/**
	 * $state: table (index), key (last primary key value) or offset, rows (count), tables (list).
	 *
	 * @return bool true when every table is dumped.
	 */
	public function run( $file, array &$state, $deadline ) {
		global $wpdb;
		$fh = fopen( $file, 'ab' );
		if ( ! $fh ) {
			throw new PBK_Exception( 'Could not write the database dump.' );
		}
		while ( $state['table'] < count( $state['tables'] ) ) {
			$table = $state['tables'][ $state['table'] ];
			$name  = $table['name'];
			$ph    = $this->placeholder( $name );
			if ( ! isset( $state['started'] ) ) {
				$state['started'] = true;
				$state['offset']  = 0;
				$state['key']     = null;
				if ( $table['view'] ) {
					$create = $wpdb->get_row( 'SHOW CREATE VIEW ' . self::ident( $name ), ARRAY_N );
					$sql    = preg_replace( '/\s+DEFINER\s*=\s*\S+/i', '', $this->ddl( $create[1] ) );
					$this->put( $fh, "DROP VIEW IF EXISTS `$ph`;\n$sql;\n\n" );
					$this->next( $state );
					continue;
				}
				$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::ident( $name ), ARRAY_N );
				if ( ! $create ) {
					$this->next( $state );
					continue;
				}
				$this->put( $fh, "DROP TABLE IF EXISTS `$ph`;\n" . $this->ddl( $create[1] ) . ";\n\n" );
				$state['pk'] = $this->single_primary_key( $name );
			}
			if ( $this->rows( $fh, $table, $state ) ) {
				$this->next( $state );
			}
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}
		fclose( $fh );
		return $state['table'] >= count( $state['tables'] );
	}

	private function next( array &$state ) {
		unset( $state['started'], $state['pk'], $state['key'], $state['offset'] );
		$state['table']++;
	}

	private function single_primary_key( $table ) {
		global $wpdb;
		$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . self::ident( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A );
		return ( is_array( $keys ) && count( $keys ) === 1 ) ? $keys[0]['Column_name'] : null;
	}

	/** Dumps one batch; returns true when the table is finished. */
	private function rows( $fh, array $table, array &$state ) {
		global $wpdb;
		$name  = $table['name'];
		$short = substr( $name, strlen( $this->prefix ) );
		$where = array();
		if ( isset( $this->where[ $short ] ) ) {
			$where[] = '(' . $this->where[ $short ] . ')';
		}
		$pk = $state['pk'];
		if ( $pk && $state['key'] !== null ) {
			$where[] = $wpdb->prepare( self::ident( $pk ) . ' > %s', $state['key'] );
		}
		$sql  = 'SELECT * FROM ' . self::ident( $name ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );
		$sql .= $pk ? ' ORDER BY ' . self::ident( $pk ) . ' LIMIT ' . self::ROWS : ' LIMIT ' . (int) $state['offset'] . ', ' . self::ROWS;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( $wpdb->last_error ) {
			throw new PBK_Exception( 'Database error while exporting ' . $name . ': ' . $wpdb->last_error );
		}
		if ( ! $rows ) {
			return true;
		}
		$ph     = $this->placeholder( $name );
		$values = array();
		$bytes  = 0;
		foreach ( $rows as $row ) {
			$row      = $this->prefixed_keys( $short, $row );
			$cells    = array_map( function ( $v ) {
				return $v === null ? 'NULL' : "'" . PBK_Replace::escape( $v ) . "'";
			}, array_values( $row ) );
			$values[] = '(' . implode( ',', $cells ) . ')';
			$bytes   += strlen( end( $values ) );
			if ( $bytes > 1048576 ) { // keep statements well under max_allowed_packet
				$this->put( $fh, "INSERT INTO `$ph` VALUES " . implode( ',', $values ) . ";\n" );
				$values = array();
				$bytes  = 0;
			}
		}
		if ( $values ) {
			$this->put( $fh, "INSERT INTO `$ph` VALUES " . implode( ',', $values ) . ";\n" );
		}
		$state['offset'] += count( $rows );
		if ( $pk ) {
			$last         = end( $rows );
			$state['key'] = $last[ $pk ];
		}
		return count( $rows ) < self::ROWS;
	}

	/** wp_user_roles, wp_capabilities ... carry the prefix inside values: portable placeholder. */
	private function prefixed_keys( $short, array $row ) {
		$col = $short === 'options' ? 'option_name' : ( $short === 'usermeta' ? 'meta_key' : null );
		// Only {prefix}user_roles in options: core also has options like wp_page_for_privacy_policy.
		if ( $col === 'option_name' && isset( $row[ $col ] ) && $row[ $col ] !== $this->prefix . 'user_roles' ) {
			return $row;
		}
		if ( $col && isset( $row[ $col ] ) && strpos( $row[ $col ], $this->prefix ) === 0 ) {
			$row[ $col ] = PBK_Sql_Importer::PREFIX_PLACEHOLDER . substr( $row[ $col ], strlen( $this->prefix ) );
		}
		return $row;
	}

	private function put( $fh, $data ) {
		if ( fwrite( $fh, $data ) !== strlen( $data ) ) {
			throw new PBK_Exception( 'Out of disk space while exporting the database.' );
		}
	}
}
