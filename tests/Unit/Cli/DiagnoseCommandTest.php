<?php
/**
 * Tests DiagnoseCommand — Phase 5.5 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Cli;

use Cent_Son\Html_Normalizer\Cli\DiagnoseCommand;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticBatchRunner;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Cli_Exit_Exception;
use Son100_Htmln_Test_Posts_Registry;
use Son100_Htmln_Test_Wpdb;
use WP_CLI;
use WP_Post;

final class DiagnoseCommandTest extends TestCase {

	protected function setUp(): void {
		WP_CLI::reset();
		Son100_Htmln_Test_Posts_Registry::reset();
	}

	// =========================================================================
	//  Helpers stubs
	// =========================================================================

	/**
	 * @param array<string, callable> $overrides
	 */
	private function runner_stub( array $overrides = array() ): DiagnosticBatchRunner {
		$wpdb = new Son100_Htmln_Test_Wpdb();
		return new class( $wpdb, $overrides ) extends DiagnosticBatchRunner {
			/** @param array<string, callable> $overrides */
			public function __construct(
				Son100_Htmln_Test_Wpdb $wpdb,
				private array $overrides
			) {
				$settings = new SettingsRepository();
				$registry = new PresetRegistry( $settings );
				$metrics  = new MetricsCalculator();
				parent::__construct(
					new DiagnosticEngine( $registry, $metrics ),
					new DiagnosticsRepository( $wpdb ),
					$settings,
				);
			}
			public function start_batch(
				?int $chunk_size = null,
				?array $post_types_override = null,
				array $filters = array(),
				bool $exclude_normalized = false
			): array {
				return isset( $this->overrides['start_batch'] )
					? ( $this->overrides['start_batch'] )( $chunk_size, $post_types_override, $filters, $exclude_normalized )
					: parent::start_batch( $chunk_size, $post_types_override, $filters, $exclude_normalized );
			}
			public function process_chunk( array $post_ids ): array {
				return isset( $this->overrides['process_chunk'] )
					? ( $this->overrides['process_chunk'] )( $post_ids )
					: parent::process_chunk( $post_ids );
			}
		};
	}

	/**
	 * @param array<string, callable> $overrides
	 */
	private function engine_stub( array $overrides = array() ): DiagnosticEngine {
		$settings = new SettingsRepository();
		return new class( new PresetRegistry( $settings ), new MetricsCalculator(), $overrides ) extends DiagnosticEngine {
			/** @param array<string, callable> $overrides */
			public function __construct(
				PresetRegistry $registry,
				MetricsCalculator $metrics,
				private array $overrides
			) {
				parent::__construct( $registry, $metrics );
			}
			public function diagnose( WP_Post $post ): DiagnosticRecord {
				return isset( $this->overrides['diagnose'] )
					? ( $this->overrides['diagnose'] )( $post )
					: parent::diagnose( $post );
			}
		};
	}

	/**
	 * @param list<DiagnosticRecord> $stale
	 * @param object|null            $tracker stdClass capturant les upserts (`$tracker->upserted`).
	 */
	private function repo_stub( array $stale = array(), ?object $tracker = null ): DiagnosticsRepository {
		$wpdb    = new Son100_Htmln_Test_Wpdb();
		$tracker = $tracker ?? new \stdClass();
		if ( ! isset( $tracker->upserted ) ) {
			$tracker->upserted = array();
		}
		return new class( $wpdb, $stale, $tracker ) extends DiagnosticsRepository {
			/** @param list<DiagnosticRecord> $stale_records */
			public function __construct(
				Son100_Htmln_Test_Wpdb $wpdb,
				private array $stale_records,
				private object $tracker
			) {
				parent::__construct( $wpdb );
			}
			public function list_stale( int $limit = 50, int $offset = 0 ): array {
				return $this->stale_records;
			}
			public function upsert( DiagnosticRecord $record ): bool {
				$this->tracker->upserted[ $record->post_id ] = $record;
				return true;
			}
		};
	}

	private function make_record( int $post_id, string $status = 'normal' ): DiagnosticRecord {
		return new DiagnosticRecord(
			id: null,
			post_id: $post_id,
			status: $status,
			matching_rules: array(),
			metrics: array(),
			is_stale: false,
			diagnosed_at: '2026-05-09 10:00:00',
			post_modified_at_diagnosis: null,
		);
	}

	private function seed_post( int $id ): void {
		$p              = new WP_Post();
		$p->ID          = $id;
		$p->post_content = '<p>x</p>';
		$p->post_type   = 'post';
		$p->post_status = 'publish';
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $p;
	}

	private function make_command(
		?DiagnosticBatchRunner $runner = null,
		?DiagnosticEngine $engine = null,
		?DiagnosticsRepository $repo = null
	): DiagnoseCommand {
		return new DiagnoseCommand(
			$runner ?? $this->runner_stub(),
			$engine ?? $this->engine_stub(),
			$repo   ?? $this->repo_stub(),
		);
	}

	// =========================================================================
	//  Mode 1 : scan all
	// =========================================================================

	public function test_scan_all_processes_all_chunks(): void {
		$processed_chunks = array();
		$runner = $this->runner_stub( array(
			'start_batch'   => fn() => array(
				'batch_id'       => 'job-1',
				'total_articles' => 4,
				'post_ids'       => array( 100, 101, 102, 103 ),
				'chunk_size'     => 2,
			),
			'process_chunk' => function ( $ids ) use ( &$processed_chunks ) {
				$processed_chunks[] = $ids;
				$out = array();
				foreach ( $ids as $id ) {
					$out[ $id ] = $this->make_record( $id );
				}
				return $out;
			},
		) );

		$this->make_command( $runner )->__invoke( array(), array() );

		$this->assertSame( array( array( 100, 101 ), array( 102, 103 ) ), $processed_chunks );
		$this->assertNotEmpty( WP_CLI::$success );
		$this->assertStringContainsString( '4 / 4', WP_CLI::$success[0] );
	}

	public function test_scan_all_with_zero_articles_logs_success(): void {
		$runner = $this->runner_stub( array(
			'start_batch' => fn() => array(
				'batch_id'       => 'x',
				'total_articles' => 0,
				'post_ids'       => array(),
				'chunk_size'     => 20,
			),
		) );

		$this->make_command( $runner )->__invoke( array(), array() );

		$this->assertNotEmpty( WP_CLI::$success );
		$this->assertStringContainsString( 'No article', WP_CLI::$success[0] );
	}

	public function test_scan_all_passes_post_type_override(): void {
		$received = null;
		$runner = $this->runner_stub( array(
			'start_batch' => function ( $chunk, $types ) use ( &$received ) {
				$received = $types;
				return array(
					'batch_id'       => 'x',
					'total_articles' => 0,
					'post_ids'       => array(),
					'chunk_size'     => 20,
				);
			},
		) );

		$this->make_command( $runner )->__invoke( array(), array( 'post-type' => 'post,page' ) );

		$this->assertSame( array( 'post', 'page' ), $received );
	}

	// =========================================================================
	//  Mode 2 : scan <id>
	// =========================================================================

	public function test_scan_single_diagnoses_and_upserts(): void {
		$this->seed_post( 42 );
		$diagnosed_post = null;
		$engine = $this->engine_stub( array(
			'diagnose' => function ( WP_Post $post ) use ( &$diagnosed_post ) {
				$diagnosed_post = $post->ID;
				return $this->make_record( $post->ID, 'to_improve' );
			},
		) );
		$tracker = new \stdClass();
		$repo    = $this->repo_stub( array(), $tracker );

		$this->make_command( null, $engine, $repo )->__invoke( array( '42' ), array() );

		$this->assertSame( 42, $diagnosed_post );
		$this->assertArrayHasKey( 42, $tracker->upserted );
		$this->assertNotEmpty( WP_CLI::$success );
	}

	public function test_scan_single_errors_for_unknown_post(): void {
		$this->expectException( Son100_Htmln_Test_Cli_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/Post 999 not found/' );

		$this->make_command()->__invoke( array( '999' ), array() );
	}

	// =========================================================================
	//  Mode 3 : scan --status=stale
	// =========================================================================

	public function test_scan_stale_without_rebuild_lists_only(): void {
		$stale = array( $this->make_record( 100 ), $this->make_record( 101 ) );
		$repo  = $this->repo_stub( $stale );

		$this->make_command( null, null, $repo )->__invoke( array(), array( 'status' => 'stale' ) );

		// Logs : "Found N", JSON list, "Pass --rebuild".
		$this->assertGreaterThanOrEqual( 2, count( WP_CLI::$logs ) );
		$found_log = array_values( array_filter(
			WP_CLI::$logs,
			static fn( string $l ): bool => str_contains( $l, 'Found' )
		) );
		$this->assertNotEmpty( $found_log );

		$json_log = array_values( array_filter(
			WP_CLI::$logs,
			static fn( string $l ): bool => str_starts_with( trim( $l ), '{' )
		) );
		$this->assertNotEmpty( $json_log );
		$decoded = json_decode( $json_log[0], true );
		$this->assertSame( array( 100, 101 ), $decoded['stale_post_ids'] );
	}

	public function test_scan_stale_with_rebuild_re_diagnoses(): void {
		$this->seed_post( 100 );
		$this->seed_post( 101 );
		$stale = array( $this->make_record( 100 ), $this->make_record( 101 ) );

		$rebuilt = array();
		$engine = $this->engine_stub( array(
			'diagnose' => function ( WP_Post $post ) use ( &$rebuilt ) {
				$rebuilt[] = $post->ID;
				return $this->make_record( $post->ID );
			},
		) );

		$repo = $this->repo_stub( $stale );

		$this->make_command( null, $engine, $repo )->__invoke(
			array(),
			array( 'status' => 'stale', 'rebuild' => true )
		);

		$this->assertSame( array( 100, 101 ), $rebuilt );
		$this->assertNotEmpty( WP_CLI::$success );
		$this->assertStringContainsString( '2 / 2', WP_CLI::$success[0] );
	}

	public function test_scan_stale_with_no_stale_logs_success(): void {
		$this->make_command()->__invoke( array(), array( 'status' => 'stale' ) );

		$this->assertNotEmpty( WP_CLI::$success );
		$this->assertStringContainsString( 'No stale', WP_CLI::$success[0] );
	}
}
