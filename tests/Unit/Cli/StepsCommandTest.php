<?php
/**
 * Tests StepsCommand — Phase 5.5 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Cli;

use Cent_Son\Html_Normalizer\Cli\StepsCommand;
use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Regression\RegressionDetector;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Steps\StepRecord;
use Cent_Son\Html_Normalizer\Steps\StepRunner;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Cli_Exit_Exception;
use Son100_Htmln_Test_Wpdb;
use WP_CLI;

final class StepsCommandTest extends TestCase {

	protected function setUp(): void {
		WP_CLI::reset();
	}

	// =========================================================================
	//  Helpers
	// =========================================================================

	/**
	 * @param array<string, callable> $overrides
	 */
	private function runner_stub( array $overrides = array() ): StepRunner {
		$wpdb = new Son100_Htmln_Test_Wpdb();
		return new class( $wpdb, $overrides ) extends StepRunner {
			/** @param array<string, callable> $overrides */
			public function __construct(
				Son100_Htmln_Test_Wpdb $wpdb,
				private array $overrides
			) {
				$settings = new SettingsRepository();
				$registry = new PresetRegistry( $settings );
				$metrics  = new MetricsCalculator();
				parent::__construct(
					new StepsRepository( $wpdb ),
					new DiagnosticsRepository( $wpdb ),
					$registry,
					new Pipeline(),
					$metrics,
					new RegressionDetector(),
					new DiagnosticEngine( $registry, $metrics ),
					$settings,
				);
			}
			public function resume_progress( string $uuid ): ?array {
				return isset( $this->overrides['resume_progress'] )
					? ( $this->overrides['resume_progress'] )( $uuid )
					: parent::resume_progress( $uuid );
			}
		};
	}

	/**
	 * @param array<string, StepRecord> $by_uuid
	 * @param list<StepRecord>          $list
	 */
	private function repo_stub( array $by_uuid = array(), array $list = array(), ?int $count = null ): StepsRepository {
		$wpdb = new Son100_Htmln_Test_Wpdb();
		return new class( $wpdb, $by_uuid, $list, $count ) extends StepsRepository {
			/**
			 * @param array<string, StepRecord> $by_uuid
			 * @param list<StepRecord>          $list
			 */
			public function __construct(
				Son100_Htmln_Test_Wpdb $wpdb,
				private array $by_uuid,
				private array $list,
				private ?int $count_override
			) {
				parent::__construct( $wpdb );
			}
			public function find_by_uuid( string $uuid ): ?StepRecord {
				return $this->by_uuid[ $uuid ] ?? null;
			}
			public function list_filtered( ?string $from, ?string $to, int $limit = 50, int $offset = 0 ): array {
				return array_slice( $this->list, $offset, $limit );
			}
			public function count_filtered( ?string $from, ?string $to ): int {
				return $this->count_override ?? count( $this->list );
			}
		};
	}

	private function make_step_record( string $uuid, bool $finished = false ): StepRecord {
		return new StepRecord(
			id: 1,
			step_uuid: $uuid,
			applied_rules: array( 'R1' ),
			affected_post_ids: array( 100 ),
			total_articles: 1,
			successful_articles: $finished ? 1 : 0,
			refused_articles: 0,
			errored_articles: 0,
			pending_articles: 0,
			per_article_results: array(),
			user_id: 5,
			started_at: '2026-05-09 10:00:00',
			finished_at: $finished ? '2026-05-09 10:05:00' : null,
		);
	}

	private function make_command(
		?StepRunner $runner = null,
		?StepsRepository $repo = null
	): StepsCommand {
		return new StepsCommand(
			$runner ?? $this->runner_stub(),
			$repo ?? $this->repo_stub(),
		);
	}

	// =========================================================================
	//  list_steps
	// =========================================================================

	public function test_list_logs_total_returned_and_items(): void {
		$records = array(
			$this->make_step_record( 'uuid-a' ),
			$this->make_step_record( 'uuid-b' ),
		);
		$cmd = $this->make_command( null, $this->repo_stub( array(), $records, 2 ) );

		$cmd->list_steps( array(), array() );

		$this->assertCount( 1, WP_CLI::$logs );
		$decoded = json_decode( WP_CLI::$logs[0], true );
		$this->assertSame( 2, $decoded['total'] );
		$this->assertSame( 2, $decoded['returned'] );
		$this->assertCount( 2, $decoded['items'] );
		$this->assertSame( 'uuid-a', $decoded['items'][0]['uuid'] );
	}

	public function test_list_respects_limit_flag(): void {
		$records = array(
			$this->make_step_record( 'uuid-a' ),
			$this->make_step_record( 'uuid-b' ),
			$this->make_step_record( 'uuid-c' ),
		);
		$cmd = $this->make_command( null, $this->repo_stub( array(), $records, 3 ) );

		$cmd->list_steps( array(), array( 'limit' => '2' ) );

		$decoded = json_decode( WP_CLI::$logs[0], true );
		$this->assertSame( 3, $decoded['total'] );
		$this->assertSame( 2, $decoded['returned'] );
	}

	// =========================================================================
	//  show
	// =========================================================================

	public function test_show_logs_step_and_progress(): void {
		$record   = $this->make_step_record( 'uuid-X' );
		$progress = array(
			'uuid' => 'uuid-X', 'total_articles' => 1,
			'processed' => array(), 'regression_pending' => array(), 'pending' => array( 100 ),
		);
		$cmd = $this->make_command(
			$this->runner_stub( array( 'resume_progress' => fn() => $progress ) ),
			$this->repo_stub( array( 'uuid-X' => $record ) ),
		);

		$cmd->show( array( 'uuid-X' ), array() );

		$this->assertCount( 1, WP_CLI::$logs );
		$decoded = json_decode( WP_CLI::$logs[0], true );
		$this->assertSame( 'uuid-X', $decoded['step']['uuid'] );
		$this->assertSame( $progress, $decoded['progress'] );
	}

	public function test_show_errors_when_uuid_missing(): void {
		$cmd = $this->make_command();

		$this->expectException( Son100_Htmln_Test_Cli_Exit_Exception::class );
		$cmd->show( array(), array() );
	}

	public function test_show_errors_when_step_unknown(): void {
		$cmd = $this->make_command();

		$this->expectException( Son100_Htmln_Test_Cli_Exit_Exception::class );
		$this->expectExceptionMessageMatches( '/uuid-Z/' );
		$cmd->show( array( 'uuid-Z' ), array() );
	}

	// =========================================================================
	//  export
	// =========================================================================

	public function test_export_to_stdout_logs_payload(): void {
		$records = array( $this->make_step_record( 'uuid-a' ) );
		$cmd     = $this->make_command( null, $this->repo_stub( array(), $records, 1 ) );

		$cmd->export( array(), array() );

		$this->assertCount( 1, WP_CLI::$logs );
		$decoded = json_decode( WP_CLI::$logs[0], true );
		$this->assertSame( 1, $decoded['total'] );
		$this->assertFalse( $decoded['capped'] );
		$this->assertCount( 1, $decoded['items'] );
	}

	public function test_export_marks_capped_when_total_exceeds_max(): void {
		$cmd = $this->make_command( null, $this->repo_stub( array(), array(), 500 ) );

		$cmd->export( array(), array() );

		$decoded = json_decode( WP_CLI::$logs[0], true );
		$this->assertTrue( $decoded['capped'] );
		$this->assertSame( StepsCommand::EXPORT_MAX, $decoded['capped_at'] );
		$this->assertSame( 500, $decoded['total'] );
	}

	public function test_export_to_file_writes_and_logs_success(): void {
		$tmp     = tempnam( sys_get_temp_dir(), 'son100-htmln-export-' );
		$records = array( $this->make_step_record( 'uuid-a' ) );
		$cmd     = $this->make_command( null, $this->repo_stub( array(), $records, 1 ) );

		try {
			$cmd->export( array(), array( 'file' => $tmp ) );

			$this->assertCount( 0, WP_CLI::$logs, 'log() ne doit pas dumper le payload sur stdout en mode --file' );
			$this->assertCount( 1, WP_CLI::$success );
			$contents = file_get_contents( $tmp );
			$this->assertIsString( $contents );
			$decoded = json_decode( $contents, true );
			$this->assertSame( 1, $decoded['total'] );
		} finally {
			@unlink( $tmp );
		}
	}
}
