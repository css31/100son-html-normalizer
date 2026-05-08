<?php
/**
 * Tests Logger.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Logs;

use Cent_Son\Html_Normalizer\Core\Logs\Logger;
use Cent_Son\Html_Normalizer\Core\Logs\LogRepository;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase {

	private LogRepository $repo;
	private Logger        $logger;

	protected function setUp(): void {
		$GLOBALS['son100_htmln_options'] = [];
		$this->repo                       = new LogRepository();
		$this->logger                     = new Logger( $this->repo );
	}

	public function test_log_normalize_creates_entry(): void {
		$this->logger->log_normalize( 42, 'Mon article', 'modified', '', 100 );
		$entries = $this->repo->all();
		$this->assertCount( 1, $entries );
		$entry = $entries[0];
		$this->assertSame( 'normalize', $entry['event'] );
		$this->assertSame( 'modified', $entry['status'] );
		$this->assertSame( 42, $entry['post_id'] );
		$this->assertSame( 'Mon article', $entry['post_title'] );
		$this->assertSame( 100, $entry['revision_id'] );
		$this->assertGreaterThan( 0, $entry['timestamp'] );
	}

	public function test_log_preview_creates_entry(): void {
		$this->logger->log_preview( 42, 'Titre', 'unchanged' );
		$entries = $this->repo->all();
		$this->assertSame( 'preview', $entries[0]['event'] );
		$this->assertSame( 'unchanged', $entries[0]['status'] );
		$this->assertSame( 0, $entries[0]['revision_id'] );
	}

	public function test_log_settings_creates_entry_with_null_post(): void {
		$this->logger->log_settings_change( 'P3 désactivé' );
		$entries = $this->repo->all();
		$this->assertSame( 'settings', $entries[0]['event'] );
		$this->assertSame( 'updated', $entries[0]['status'] );
		$this->assertNull( $entries[0]['post_id'] );
		$this->assertNull( $entries[0]['post_title'] );
		$this->assertSame( 'P3 désactivé', $entries[0]['message'] );
	}

	public function test_user_id_is_zero_in_test_env(): void {
		// Stub wp_get_current_user retourne user_id=0 en test.
		$this->logger->log_normalize( 1, 'x', 'modified' );
		$entries = $this->repo->all();
		$this->assertSame( 0, $entries[0]['user_id'] );
	}
}
