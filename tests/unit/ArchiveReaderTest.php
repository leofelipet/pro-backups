<?php
use PHPUnit\Framework\TestCase;

/**
 * Reads real archives produced by All-in-One WP Migration (tests/fixtures, see tests/env/make-fixtures.sh).
 */
class ArchiveReaderTest extends TestCase {
	const PASSWORD = 'secret123';

	private $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/pbk-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp );
	}

	protected function tearDown(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->tmp ) );
	}

	public static function fixtures() {
		return array(
			'plain'          => array( 'ai1wm-plain.wpress' ),
			'gzip'           => array( 'ai1wm-gzip.wpress' ),
			'bzip2'          => array( 'ai1wm-bzip2.wpress' ),
			'encrypted'      => array( 'ai1wm-encrypted.wpress' ),
			'encrypted-gzip' => array( 'ai1wm-encrypted-gzip.wpress' ),
		);
	}

	private function open( $fixture ) {
		$path = dirname( __DIR__ ) . '/fixtures/' . $fixture;
		$pkg  = json_decode( ( new PBK_Archive_Reader( $path ) )->read_file( 'package.json' ), true );
		$type = empty( $pkg['Compression']['Enabled'] ) ? null : $pkg['Compression']['Type'];
		if ( $type === 'bzip2' && ! function_exists( 'bzdecompress' ) ) {
			$this->markTestSkipped( 'bz2 extension missing' );
		}
		$password = empty( $pkg['Encrypted'] ) ? null : self::PASSWORD;
		return array( new PBK_Archive_Reader( $path, $password, $type ), $pkg );
	}

	private function extract_all( PBK_Archive_Reader $reader, $deadline_fn ) {
		$state = array();
		$calls = 0;
		do {
			$done = $reader->extract( $state, function ( $entry ) {
				return $this->tmp . '/' . $entry['name'];
			}, $deadline_fn() );
			$calls++;
		} while ( ! $done && $calls < 100000 );
		return $calls;
	}

	/** @dataProvider fixtures */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'fixtures' )]
	public function test_reads_ai1wm_archive( $fixture ) {
		list( $reader, $pkg ) = $this->open( $fixture );

		$this->assertNotFalse( $reader->eof(), 'EOF block' );
		$crc = array();
		$this->assertTrue( $reader->verify_crc( $crc, INF ) );

		$names = array_column( iterator_to_array( $reader->entries(), false ), 'name' );
		$this->assertContains( 'package.json', $names );
		$this->assertContains( 'database.sql', $names );
		$this->assertContains( 'uploads/pbk-fixture.txt', $names );
		$this->assertSame( 'http://localhost:8091', $pkg['SiteURL'] );

		$this->extract_all( $reader, function () {
			return INF;
		} );
		$this->assertSame( 'pbk fixture media', file_get_contents( $this->tmp . '/uploads/pbk-fixture.txt' ) );
		$sql = file_get_contents( $this->tmp . '/database.sql' );
		$this->assertStringContainsString( 'SERVMASK_PREFIX_options', $sql );
		$this->assertStringContainsString( 'restored-from-src', $sql );
	}

	public function test_resumes_with_expired_deadline_every_chunk() {
		list( $reader ) = $this->open( 'ai1wm-encrypted-gzip.wpress' );
		$calls = $this->extract_all( $reader, function () {
			return 0; // always expired: forces a "new request" after every chunk
		} );
		$this->assertGreaterThan( 3, $calls );
		$this->assertSame( 'pbk fixture media', file_get_contents( $this->tmp . '/uploads/pbk-fixture.txt' ) );

		$this->assertSame( $reader->read_file( 'database.sql' ), file_get_contents( $this->tmp . '/database.sql' ) );
	}

	public function test_password_signature() {
		list( , $pkg ) = $this->open( 'ai1wm-encrypted.wpress' );
		$this->assertTrue( PBK_Archive::password_matches( $pkg['EncryptedSignature'], self::PASSWORD ) );
		$this->assertFalse( PBK_Archive::password_matches( $pkg['EncryptedSignature'], 'wrong' ) );
	}

	public function test_wrong_password_fails_loudly() {
		$path   = dirname( __DIR__ ) . '/fixtures/ai1wm-encrypted.wpress';
		$reader = new PBK_Archive_Reader( $path, 'wrong' );
		$this->expectException( PBK_Exception::class );
		$reader->read_file( 'database.sql' );
	}

	public function test_crc_detects_corruption() {
		$copy = $this->tmp . '/bad.wpress';
		copy( dirname( __DIR__ ) . '/fixtures/ai1wm-plain.wpress', $copy );
		$fh = fopen( $copy, 'r+b' );
		fseek( $fh, 6000 );
		fwrite( $fh, 'X' );
		fclose( $fh );
		$state = array();
		$this->expectException( PBK_Exception::class );
		( new PBK_Archive_Reader( $copy ) )->verify_crc( $state, INF );
	}

	public function test_crc_resumable() {
		$state  = array();
		$reader = new PBK_Archive_Reader( dirname( __DIR__ ) . '/fixtures/ai1wm-plain.wpress' );
		$this->assertTrue( $reader->verify_crc( $state, INF ) );
		$this->assertSame( 8, strlen( $state['crc'] ) );
	}

	public function test_truncated_archive_is_rejected() {
		$copy = $this->tmp . '/cut.wpress';
		file_put_contents( $copy, substr( file_get_contents( dirname( __DIR__ ) . '/fixtures/ai1wm-plain.wpress' ), 0, 50000 ) );
		$reader = new PBK_Archive_Reader( $copy );
		$this->assertFalse( $reader->eof() );
		$this->expectException( PBK_Exception::class );
		iterator_to_array( $reader->entries() );
	}

	public function test_crc_combine_matches_full_hash() {
		$a = random_bytes( 1000 );
		$b = random_bytes( 777 );
		$this->assertSame( hash( 'crc32b', $a . $b ), PBK_Crc::combine( hash( 'crc32b', $a ), hash( 'crc32b', $b ), 777 ) );
	}

	public function test_safe_path() {
		$this->assertSame( 'uploads/a/b.txt', PBK_Archive::safe_path( 'uploads\\a/./b.txt' ) );
		foreach ( array( '../wp-config.php', 'uploads/../../x', '/etc/passwd', 'C:/x', "a\0b", '' ) as $bad ) {
			try {
				PBK_Archive::safe_path( $bad );
				$this->fail( 'accepted ' . $bad );
			} catch ( PBK_Exception $e ) {
				$this->assertStringContainsString( 'Unsafe path', $e->getMessage() );
			}
		}
	}

	public function test_traversal_entry_is_rejected_when_reading() {
		$evil = $this->tmp . '/evil.wpress';
		$data = 'pwned';
		file_put_contents( $evil, pack( PBK_Archive::HEADER_PACK, 'x.php', strlen( $data ), time(), '../../..', '' ) . $data . PBK_Archive::eof_block() );
		$this->expectException( PBK_Exception::class );
		iterator_to_array( ( new PBK_Archive_Reader( $evil ) )->entries() );
	}

	/** Full-site archives (themes + plugins), built by make-fixtures.sh; skipped when absent. */
	public function test_large_fixtures_if_present() {
		$files = glob( dirname( __DIR__ ) . '/fixtures/large/*.wpress' );
		if ( ! $files ) {
			$this->markTestSkipped( 'run tests/env/make-fixtures.sh' );
		}
		foreach ( $files as $path ) {
			$pkg  = json_decode( ( new PBK_Archive_Reader( $path ) )->read_file( 'package.json' ), true );
			$type = empty( $pkg['Compression']['Enabled'] ) ? null : $pkg['Compression']['Type'];
			if ( $type === 'bzip2' && ! function_exists( 'bzdecompress' ) ) {
				continue;
			}
			$reader = new PBK_Archive_Reader( $path, empty( $pkg['Encrypted'] ) ? null : self::PASSWORD, $type );
			$crc    = array();
			$this->assertTrue( $reader->verify_crc( $crc, INF ), basename( $path ) );
			$count = 0;
			$this->extract_all( $reader, function () {
				return microtime( true ) + 0.05;
			} );
			foreach ( $reader->entries() as $e ) {
				$count++;
				if ( $e['name'] !== 'package.json' && $e['name'] !== 'database.sql' ) {
					$this->assertFileExists( $this->tmp . '/' . $e['name'] );
				}
			}
			$this->assertGreaterThan( 100, $count, basename( $path ) );
			exec( 'rm -rf ' . escapeshellarg( $this->tmp ) . '/*' );
		}
	}
}
