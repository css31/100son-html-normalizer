<?php
/**
 * Tests PostNormalizer.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Posts;

use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;
use Cent_Son\Html_Normalizer\Core\Logs\Logger;
use Cent_Son\Html_Normalizer\Core\Logs\LogRepository;
use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Posts\PostNormalizer;
use Cent_Son\Html_Normalizer\Core\Posts\SiteOriginDetector;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use WP_Post;

final class PostNormalizerTest extends TestCase {

	use HtmlAssertions;

	private PostNormalizer $service;
	private LogRepository  $log_repo;

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$GLOBALS['son100_htmln_options'] = [];

		$settings = new class extends SettingsRepository {
			public function is_preset_enabled( string $preset_id ): bool { return true; }
			public function get_preset_config( string $preset_id ): array {
				return match ( $preset_id ) {
					'P5' => [ 'enabled' => true, 'threshold' => 2 ],
					'P6' => [ 'enabled' => true, 'keep_text_align' => true ],
					'P7' => [
						'enabled' => true, 'threshold' => 2,
						'markers' => [ 'dash' => true, 'emdash' => true, 'asterix' => true, 'bullet' => true, 'numeric' => true ],
						'custom_markers' => [],
					],
					'P8' => [ 'enabled' => true, 'mappings' => [ 'bold' => true, 'italic' => true ] ],
					default => [ 'enabled' => true ],
				};
			}
		};
		$normalizer    = new HtmlNormalizer( new PresetRegistry( $settings ), new Pipeline() );
		$this->log_repo = new LogRepository();
		$logger        = new Logger( $this->log_repo );
		$this->service = new PostNormalizer( $normalizer, new SiteOriginDetector(), $logger );
	}

	private function set_post( int $id, string $content, ?array $panels_data = null ): void {
		$post               = new WP_Post();
		$post->ID           = $id;
		$post->post_content = $content;
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $post;
		if ( null !== $panels_data ) {
			Son100_Htmln_Test_Posts_Registry::$meta[ $id ]['panels_data'] = $panels_data;
		}
	}

	// ====== Preview ====== //

	public function test_preview_unchanged_for_clean_content(): void {
		$this->set_post( 1, '<p>Texte propre.</p>' );
		$result = $this->service->preview( 1 );
		$this->assertSame( PostNormalizer::STATUS_UNCHANGED, $result['status'] );
		$this->assertHtmlEquals( '<p>Texte propre.</p>', $result['html_after'] );
		$this->assertFalse( $result['has_panels_data'] );
	}

	public function test_preview_modified_for_dirty_content(): void {
		$this->set_post( 2, '<p style="color:red"></p><p>OK</p>[shareaholic id="1"]' );
		$result = $this->service->preview( 2 );
		$this->assertSame( PostNormalizer::STATUS_MODIFIED, $result['status'] );
		$this->assertHtmlEquals( '<p>OK</p>', $result['html_after'] );
	}

	public function test_preview_returns_so_flag(): void {
		$this->set_post( 3, '<p>x</p>', [ 'widgets' => [ [ 'foo' => 'bar' ] ] ] );
		$result = $this->service->preview( 3 );
		$this->assertTrue( $result['has_panels_data'] );
	}

	public function test_preview_does_not_write(): void {
		$this->set_post( 4, '<p style="color:red">x</p>' );
		$this->service->preview( 4 );
		$this->assertArrayNotHasKey( 4, Son100_Htmln_Test_Posts_Registry::$updates );
		$this->assertArrayNotHasKey( 4, Son100_Htmln_Test_Posts_Registry::$revisions_created );
	}

	public function test_preview_404_for_missing_post(): void {
		$result = $this->service->preview( 9999 );
		$this->assertSame( PostNormalizer::STATUS_ERROR_NOT_FOUND, $result['status'] );
	}

	// ====== Normalize ====== //

	public function test_normalize_writes_and_creates_revision(): void {
		$this->set_post( 5, '<p style="color:red"></p><p>OK</p>' );
		$result = $this->service->normalize_post( 5, false );

		$this->assertSame( PostNormalizer::STATUS_MODIFIED, $result['status'] );
		$this->assertGreaterThan( 0, (int) ( $result['revision_id'] ?? 0 ) );
		$this->assertArrayHasKey( 5, Son100_Htmln_Test_Posts_Registry::$updates );
		$this->assertHtmlEquals( '<p>OK</p>', Son100_Htmln_Test_Posts_Registry::$updates[ 5 ] );
	}

	public function test_normalize_unchanged_does_not_write(): void {
		$this->set_post( 6, '<p>Texte propre.</p>' );
		$result = $this->service->normalize_post( 6, false );
		$this->assertSame( PostNormalizer::STATUS_UNCHANGED, $result['status'] );
		$this->assertArrayNotHasKey( 6, Son100_Htmln_Test_Posts_Registry::$updates );
	}

	public function test_normalize_refuses_siteorigin_without_force(): void {
		$this->set_post( 7, '<p style="color:red">x</p>', [ 'widgets' => [ [ 'a' => 1 ] ] ] );
		$result = $this->service->normalize_post( 7, false );
		$this->assertSame( PostNormalizer::STATUS_SKIPPED_SO, $result['status'] );
		$this->assertArrayNotHasKey( 7, Son100_Htmln_Test_Posts_Registry::$updates );
	}

	public function test_normalize_writes_siteorigin_when_force_true(): void {
		$this->set_post( 8, '<p style="color:red"></p><p>OK</p>', [ 'widgets' => [ [ 'a' => 1 ] ] ] );
		$result = $this->service->normalize_post( 8, true );
		$this->assertSame( PostNormalizer::STATUS_MODIFIED, $result['status'] );
		$this->assertArrayHasKey( 8, Son100_Htmln_Test_Posts_Registry::$updates );
	}

	public function test_normalize_404_for_missing_post(): void {
		$result = $this->service->normalize_post( 99999, false );
		$this->assertSame( PostNormalizer::STATUS_ERROR_NOT_FOUND, $result['status'] );
	}

	// ====== Logging ====== //

	public function test_normalize_logs_entry_on_success(): void {
		$this->set_post( 10, '<p style="color:red"></p><p>OK</p>' );
		$this->service->normalize_post( 10, false );

		$entries = $this->log_repo->all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'normalize', $entries[0]['event'] );
		$this->assertSame( PostNormalizer::STATUS_MODIFIED, $entries[0]['status'] );
		$this->assertSame( 10, $entries[0]['post_id'] );
	}

	public function test_normalize_logs_entry_on_so_skip(): void {
		$this->set_post( 11, '<p>x</p>', [ 'widgets' => [ [ 'a' => 1 ] ] ] );
		$this->service->normalize_post( 11, false );

		$entries = $this->log_repo->all();
		$this->assertCount( 1, $entries );
		$this->assertSame( PostNormalizer::STATUS_SKIPPED_SO, $entries[0]['status'] );
	}

	public function test_preview_logs_entry(): void {
		$this->set_post( 12, '<p style="color:red"></p><p>OK</p>' );
		$this->service->preview( 12 );

		$entries = $this->log_repo->all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'preview', $entries[0]['event'] );
		$this->assertSame( PostNormalizer::STATUS_MODIFIED, $entries[0]['status'] );
	}
}
