<?php
use PHPUnit\Framework\TestCase;

class ArchiveWriterTest extends TestCase {
	private $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/pbk-w-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp . '/src', 0777, true );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->tmp ) );
	}

	public static function modes() {
		return array(
			'plain'     => array( null, null ),
			'gzip'      => array( null, 'gzip' ),
			'bzip2'     => array( null, 'bzip2' ),
			'enc'       => array( 'pw', null ),
			'enc+gzip'  => array( 'pw', 'gzip' ),
			'enc+bzip2' => array( 'pw', 'bzip2' ),
		);
	}

	/** @dataProvider modes */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'modes' )]
	public function test_round_trip_with_forced_resume( $password, $compression ) {
		if ( $compression === 'bzip2' && ! function_exists( 'bzcompress' ) ) {
			$this->markTestSkipped( 'bz2 extension missing' );
		}
		$files = array(
			'uploads/big.bin'   => random_bytes( 1300000 ), // > 2 chunks
			'uploads/empty.txt' => '',
			'plugins/x/x.php'   => '<?php // x',
			'database.sql'      => str_repeat( "INSERT INTO t VALUES ('a');\n", 30000 ),
		);
		$archive = $this->tmp . '/out.wpress';
		$calls   = 0;
		foreach ( $files as $name => $data ) {
			$src = $this->tmp . '/src/' . md5( $name );
			file_put_contents( $src, $data );
			$state = array();
			do {
				$w = new PBK_Archive_Writer( $archive, $password, $compression ); // new "request" every time
				$calls++;
			} while ( ! $w->add_file( $src, $name, $state, 0 ) );
		}
		$w = new PBK_Archive_Writer( $archive, $password, $compression );
		$w->add_string( 'package.json', '{"SiteURL":"x"}' );
		$state = array();
		$crc   = PBK_Archive_Writer::file_crc( $archive, filesize( $archive ), $state, INF );
		$w->finish( $crc );
		unset( $w );
		$this->assertGreaterThan( 6, $calls );

		$r = new PBK_Archive_Reader( $archive, $password, $compression );
		$crc_state = array();
		$this->assertTrue( $r->verify_crc( $crc_state, INF ) );
		$this->assertSame( '{"SiteURL":"x"}', $r->read_file( 'package.json' ) );
		foreach ( $files as $name => $data ) {
			$this->assertSame( $data, $r->read_file( $name ), $name );
		}
		foreach ( $r->entries() as $e ) {
			if ( isset( $files[ $e['name'] ] ) ) {
				$this->assertSame( hash( 'crc32b', $files[ $e['name'] ] ), $e['crc'], 'header crc ' . $e['name'] );
			}
		}
	}

	public function test_crash_mid_file_is_truncated_on_resume() {
		$archive = $this->tmp . '/out.wpress';
		$src     = $this->tmp . '/src/f';
		$data    = random_bytes( 1100000 );
		file_put_contents( $src, $data );
		$state = array();
		( new PBK_Archive_Writer( $archive ) )->add_file( $src, 'f.bin', $state, 0 );
		file_put_contents( $archive, 'GARBAGE FROM A CRASHED REQUEST', FILE_APPEND );
		while ( ! ( new PBK_Archive_Writer( $archive ) )->add_file( $src, 'f.bin', $state, 0 ) ) {
		}
		( new PBK_Archive_Writer( $archive ) )->finish( '' );
		$this->assertSame( $data, ( new PBK_Archive_Reader( $archive ) )->read_file( 'f.bin' ) );
	}
}
