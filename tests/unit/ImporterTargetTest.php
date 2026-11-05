<?php
use PHPUnit\Framework\TestCase;

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', '/site/wp-content' );
	define( 'WP_PLUGIN_DIR', '/site/wp-content/plugins' );
}
if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root() {
		return '/site/wp-content/themes';
	}
}

class ImporterTargetTest extends TestCase {
	private function t( $name ) {
		return PBK_Importer::target( $name, '/job/database.sql', array( 'dropins' => array( 'object-cache.php', 'db.php' ), 'plugin' => 'plugins/pro-backups/' ) );
	}

	public function test_mapping() {
		$this->assertSame( '/job/database.sql', $this->t( 'database.sql' ) );
		$this->assertSame( '/site/wp-content/plugins/akismet/akismet.php', $this->t( 'plugins/akismet/akismet.php' ) );
		$this->assertSame( '/site/wp-content/themes/twentytwentyfive/style.css', $this->t( 'themes/twentytwentyfive/style.css' ) );
		$this->assertSame( '/site/wp-content/uploads/2024/01/a.jpg', $this->t( 'uploads/2024/01/a.jpg' ) );
		$this->assertSame( '/site/wp-content/mu-plugins/mine.php', $this->t( 'mu-plugins/mine.php' ) );
	}

	public function test_skips() {
		foreach ( array( 'package.json', 'multisite.json', 'object-cache.php', 'db.php', 'plugins/pro-backups/pro-backups.php', 'pro-backups/old.wpress', 'ai1wm-backups/x.wpress', 'mu-plugins/endurance-page-cache.php', 'mu-plugins/kinsta-mu-plugins/x.php', 'uploads/elementor/css/post-1.css', 'database/.ht.sqlite', 'cache/page.html', 'themes/functions.php' ) as $name ) {
			$this->assertNull( $this->t( $name ), $name );
		}
		$this->assertNotNull( $this->t( 'plugins/pro-backups-addon/x.php' ) );
	}
}
