<?php
/**
 * Search/replace that keeps PHP-serialized data valid (string lengths are recomputed),
 * and its application to the quoted values of a MySQL statement.
 *
 * @package ProBackups
 */

defined( 'ABSPATH' ) || exit;

class PBK_Replace {
	/** @var array old => new */
	private $map;
	private $min;

	public function __construct( array $map ) {
		$map = array_filter(
			$map,
			function ( $new, $old ) {
				return $old !== '' && $old !== $new;
			},
			ARRAY_FILTER_USE_BOTH
		);
		$this->map = $map;
		$this->min = $map ? min( array_map( 'strlen', array_keys( $map ) ) ) : PHP_INT_MAX;
	}

	public function is_empty() {
		return ! $this->map;
	}

	/** Replace in a plain or serialized value. */
	public function value( $data ) {
		if ( ! $this->map || strlen( $data ) < $this->min ) {
			return $data;
		}
		$pos = 0;
		$out = $this->parse( $data, $pos );
		return ( $out !== null && $pos === strlen( $data ) ) ? $out : strtr( $data, $this->map );
	}

	/** Replace inside every '...' literal of a SQL statement (MySQL escaping). */
	public function sql( $query ) {
		if ( ! $this->map ) {
			return $query;
		}
		$hit = false;
		foreach ( $this->map as $old => $new ) {
			if ( strpos( $query, self::escape( $old ) ) !== false ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return $query;
		}
		return preg_replace_callback(
			"/'((?:[^'\\\\]++|\\\\.)*+)'/sS",
			function ( $m ) {
				return "'" . self::escape( $this->value( self::unescape( $m[1] ) ) ) . "'";
			},
			$query
		);
	}

	public static function escape( $s ) {
		return strtr( $s, array( "\x00" => '\\0', "\n" => '\\n', "\r" => '\\r', '\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\x1a" => '\\Z' ) );
	}

	public static function unescape( $s ) {
		return strtr( $s, array( '\\0' => "\x00", '\\n' => "\n", '\\r' => "\r", '\\\\' => '\\', "\\'" => "'", '\\"' => '"', '\\Z' => "\x1a" ) );
	}

	/** Parses one serialized value at $pos and returns it with replacements, or null if not serialized. */
	private function parse( $d, &$pos ) {
		$len = strlen( $d );
		if ( $pos >= $len ) {
			return null;
		}
		$type = $d[ $pos ];
		switch ( $type ) {
			case 'N':
				if ( substr( $d, $pos, 2 ) !== 'N;' ) {
					return null;
				}
				$pos += 2;
				return 'N;';
			case 'i':
			case 'd':
			case 'b':
			case 'r':
			case 'R':
				if ( ! isset( $d[ $pos + 1 ] ) || $d[ $pos + 1 ] !== ':' || ( $end = strpos( $d, ';', $pos ) ) === false ) {
					return null;
				}
				$out = substr( $d, $pos, $end - $pos + 1 );
				$pos = $end + 1;
				return $out;
			case 's':
				if ( ! preg_match( '/\Gs:(\d+):"/', $d, $m, 0, $pos ) ) {
					return null;
				}
				$start = $pos + strlen( $m[0] );
				$str   = substr( $d, $start, (int) $m[1] );
				if ( strlen( $str ) !== (int) $m[1] || substr( $d, $start + (int) $m[1], 2 ) !== '";' ) {
					return null;
				}
				$pos = $start + (int) $m[1] + 2;
				$new = $this->value( $str ); // nested serialized strings are handled recursively
				return 's:' . strlen( $new ) . ':"' . $new . '";';
			case 'a':
			case 'O':
			case 'C':
				$re = $type === 'a' ? '/\Ga:(\d+):\{/' : '/\G' . $type . ':(\d+):"([^"]*)":(\d+):\{/';
				if ( ! preg_match( $re, $d, $m, 0, $pos ) ) {
					return null;
				}
				$pos += strlen( $m[0] );
				$out  = $m[0];
				if ( $type === 'C' ) { // custom serialization: opaque payload of given length
					$out .= substr( $d, $pos, (int) $m[3] );
					$pos += (int) $m[3];
				} else {
					$count = (int) ( $type === 'a' ? $m[1] : $m[3] ) * 2;
					for ( $i = 0; $i < $count; $i++ ) {
						$el = $this->parse( $d, $pos );
						if ( $el === null ) {
							return null;
						}
						$out .= $el;
					}
				}
				if ( ! isset( $d[ $pos ] ) || $d[ $pos ] !== '}' ) {
					return null;
				}
				$pos++;
				return $out . '}';
		}
		return null;
	}
}
