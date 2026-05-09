<?php
/**
 * Tests DiagnosticBatchRunner — Phase 3.3 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Diagnostics;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticBatchRunner;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use Son100_Htmln_Test_Wpdb;
use WP_Post;

final class DiagnosticBatchRunnerTest extends TestCase {

	private Son100_Htmln_Test_Wpdb $wpdb;
	private DiagnosticsRepository $repo;
	private DiagnosticBatchRunner $runner;
	private SettingsRepository $settings;

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$GLOBALS['son100_htmln_options'] = [];

		$this->wpdb     = new Son100_Htmln_Test_Wpdb();
		$this->repo     = new DiagnosticsRepository( $this->wpdb );
		$this->settings = new SettingsRepository();

		// Engine avec un PresetRegistry stub : aucune règle activée → tout est `normal`.
		$registry = new class extends PresetRegistry {
			public function __construct() { parent::__construct( new SettingsRepository() ); }
			public function get_enabled_rules(): array { return []; }
		};
		$engine        = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$this->runner  = new DiagnosticBatchRunner( $engine, $this->repo, $this->settings );
	}

	private function seed_post( int $id, string $content = '<p>x</p>', string $type = 'post', string $status = 'publish' ): void {
		$p                = new WP_Post();
		$p->ID            = $id;
		$p->post_content  = $content;
		$p->post_type     = $type;
		$p->post_status   = $status;
		$p->post_modified = '2026-05-09 12:00:00';
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $p;
	}

	// =========================================================================
	//  start_batch
	// =========================================================================

	public function test_start_batch_lists_published_posts(): void {
		$this->seed_post( 1, '<p>a</p>' );
		$this->seed_post( 2, '<p>b</p>' );
		$this->seed_post( 3, '<p>c</p>' );

		$batch = $this->runner->start_batch();
		$this->assertSame( 3, $batch['total_articles'] );
		$this->assertSame( array( 1, 2, 3 ), $batch['post_ids'] );
		$this->assertSame( DiagnosticBatchRunner::DEFAULT_CHUNK_SIZE, $batch['chunk_size'] );
		$this->assertNotEmpty( $batch['batch_id'] );
		// batch_id ressemble à un UUID v4.
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[a-f0-9]{4}-[a-f0-9]{12}$/',
			$batch['batch_id']
		);
	}

	public function test_start_batch_filters_by_post_status(): void {
		$this->seed_post( 1, '<p>a</p>', 'post', 'publish' );
		$this->seed_post( 2, '<p>b</p>', 'post', 'draft' );
		$batch = $this->runner->start_batch();
		// Seul l'article publish est retenu.
		$this->assertSame( array( 1 ), $batch['post_ids'] );
	}

	public function test_start_batch_respects_f8_post_types_selection(): void {
		$this->settings->set_f8_post_types_selection( array( 'post' ) );
		$this->seed_post( 1, '<p>a</p>', 'post' );
		$this->seed_post( 2, '<p>b</p>', 'page' );
		$this->seed_post( 3, '<p>c</p>', 'post' );

		$batch = $this->runner->start_batch();
		$this->assertSame( array( 1, 3 ), $batch['post_ids'] );
	}

	public function test_start_batch_chunk_size_overrideable(): void {
		$batch = $this->runner->start_batch( 5 );
		$this->assertSame( 5, $batch['chunk_size'] );
	}

	public function test_start_batch_chunk_size_floor_at_one(): void {
		$batch = $this->runner->start_batch( 0 );
		$this->assertSame( 1, $batch['chunk_size'] );
		$batch = $this->runner->start_batch( -10 );
		$this->assertSame( 1, $batch['chunk_size'] );
	}

	public function test_start_batch_returns_zero_total_when_no_posts(): void {
		$batch = $this->runner->start_batch();
		$this->assertSame( 0, $batch['total_articles'] );
		$this->assertSame( array(), $batch['post_ids'] );
	}

	// =========================================================================
	//  process_chunk
	// =========================================================================

	public function test_process_chunk_diagnoses_each_post(): void {
		$this->seed_post( 1, '<p>a</p>' );
		$this->seed_post( 2, '<p>b</p>' );
		// 2 inserts attendus dans la table diagnostics.
		$this->wpdb->get_row_queue = array( null, null );  // upsert -> find absent ×2

		$results = $this->runner->process_chunk( array( 1, 2 ) );
		$this->assertCount( 2, $results );
		$this->assertInstanceOf( DiagnosticRecord::class, $results[1] );
		$this->assertInstanceOf( DiagnosticRecord::class, $results[2] );
		$this->assertSame( 1, $results[1]->post_id );
		$this->assertSame( 2, $results[2]->post_id );

		// Verifie que les upserts ont eu lieu (2 inserts, 0 update).
		$this->assertCount( 2, $this->wpdb->insert_log );
	}

	public function test_process_chunk_skips_unknown_post_ids(): void {
		$this->seed_post( 1, '<p>a</p>' );
		$this->wpdb->get_row_queue = array( null );  // 1 upsert -> 1 insert

		$results = $this->runner->process_chunk( array( 1, 99999, 88888 ) );
		// Seul l'ID 1 produit un record.
		$this->assertCount( 1, $results );
		$this->assertArrayHasKey( 1, $results );
		$this->assertCount( 1, $this->wpdb->insert_log );
	}

	public function test_process_chunk_with_empty_input_is_noop(): void {
		$results = $this->runner->process_chunk( array() );
		$this->assertSame( array(), $results );
		$this->assertCount( 0, $this->wpdb->insert_log );
	}

	public function test_process_chunk_calls_upsert_with_record(): void {
		$this->seed_post( 1, '<p>x</p>' );
		$this->wpdb->get_row_queue = array( null );

		$this->runner->process_chunk( array( 1 ) );
		$this->assertCount( 1, $this->wpdb->insert_log );
		$inserted = $this->wpdb->insert_log[0]['data'];
		$this->assertSame( 1, $inserted['post_id'] );
		$this->assertSame( 'normal', $inserted['status'] );
		// metrics est sérialisé en JSON.
		$this->assertIsString( $inserted['metrics'] );
		$decoded = json_decode( $inserted['metrics'], true );
		$this->assertArrayHasKey( 'paragraphs', $decoded );
	}
}
