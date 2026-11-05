<?php
use PHPUnit\Framework\TestCase;

class ReplaceAndSqlTest extends TestCase {
	private function r() {
		return new PBK_Replace( array( 'http://old.test' => 'https://new.example.com', '/var/old' => '/srv/new' ) );
	}

	public function test_plain_value() {
		$this->assertSame( 'go https://new.example.com/x', $this->r()->value( 'go http://old.test/x' ) );
	}

	public function test_serialized_lengths_are_fixed() {
		$data = serialize( array( 'u' => 'http://old.test/a', 'n' => array( 'p' => '/var/old/wp', 'i' => 5, 'f' => 1.5, 'b' => true, 'z' => null ) ) );
		$out  = $this->r()->value( $data );
		$this->assertSame(
			array( 'u' => 'https://new.example.com/a', 'n' => array( 'p' => '/srv/new/wp', 'i' => 5, 'f' => 1.5, 'b' => true, 'z' => null ) ),
			unserialize( $out )
		);
	}

	public function test_nested_serialized_string_and_object() {
		$inner = serialize( array( 'http://old.test' ) );
		$obj   = new stdClass();
		$obj->url = 'http://old.test/o';
		$data  = serialize( array( $inner, $obj ) );
		$out   = unserialize( $this->r()->value( $data ) );
		$this->assertSame( array( 'https://new.example.com' ), unserialize( $out[0] ) );
		$this->assertSame( 'https://new.example.com/o', $out[1]->url );
	}

	public function test_broken_serialized_falls_back_to_plain_replace() {
		$this->assertSame( 's:99:"https://new.example.com";', $this->r()->value( 's:99:"http://old.test";' ) );
	}

	public function test_sql_literals() {
		$value = serialize( array( 'k' => "it's http://old.test\nline" ) );
		$q     = "INSERT INTO `t` VALUES (1,'" . PBK_Replace::escape( $value ) . "','plain http://old.test');";
		$out   = $this->r()->sql( $q );
		preg_match( "/VALUES \\(1,'((?:[^'\\\\]|\\\\.)*)','(.*)'\\);/s", $out, $m );
		$this->assertSame( array( 'k' => "it's https://new.example.com\nline" ), unserialize( PBK_Replace::unescape( $m[1] ) ) );
		$this->assertSame( 'plain https://new.example.com', $m[2] );
	}

	public function test_sql_importer_resumes_and_replaces_prefix() {
		$file = tempnam( sys_get_temp_dir(), 'pbk' );
		file_put_contents(
			$file,
			"DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;\nCREATE TABLE `SERVMASK_PREFIX_options` (\n `v` text\n) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;\n\nSTART TRANSACTION;\n" .
			"INSERT INTO `SERVMASK_PREFIX_options` VALUES ('a;\\nb http://old.test');\nINSERT INTO `SERVMASK_PREFIX_options` VALUES ('SERVMASK_PREFIX_user_roles');\nCOMMIT;\n"
		);
		$ran  = array();
		$exec = function ( $sql ) use ( &$ran ) {
			if ( stripos( $sql, 'utf8mb4_0900_ai_ci' ) !== false ) {
				return "Unknown collation: 'utf8mb4_0900_ai_ci'";
			}
			$ran[] = $sql;
			return '';
		};
		$imp   = new PBK_Sql_Importer( 'wp_', $this->r(), $exec );
		$state = array();
		$loops = 0;
		while ( ! $imp->run( $file, $state, 0 ) ) {
			$loops++;
		}
		$queries = array_values( array_filter( $ran, function ( $q ) {
			return preg_match( '/^(DROP|CREATE|INSERT)/', $q );
		} ) );
		$this->assertGreaterThanOrEqual( 3, $loops );
		$this->assertSame( 'DROP TABLE IF EXISTS `wp_options`;', $queries[0] );
		$this->assertStringContainsString( 'utf8mb4_unicode_520_ci', $queries[1] );
		$this->assertSame( "INSERT INTO `wp_options` VALUES ('a;\\nb https://new.example.com');", $queries[2] );
		$this->assertSame( "INSERT INTO `wp_options` VALUES ('wp_user_roles');", $queries[3] );
		$this->assertCount( 4, $queries );
		$this->assertSame( 0, $state['failed'] );
		unlink( $file );
	}
}
