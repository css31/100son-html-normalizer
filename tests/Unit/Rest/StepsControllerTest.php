<?php
/**
 * Tests StepsController — Phase 5.2 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Metrics\MetricsSnapshot;
use Cent_Son\Html_Normalizer\Regression\RegressionDetector;
use Cent_Son\Html_Normalizer\Rest\StepsController;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Steps\ArticleResult;
use Cent_Son\Html_Normalizer\Steps\StepRecord;
use Cent_Son\Html_Normalizer\Steps\StepRunner;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Wpdb;
use WP_REST_Request;

final class StepsControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;
	}

	// =========================================================================
	//  Helpers — stubs StepRunner et StepsRepository.
	// =========================================================================

	/**
	 * StepRunner stub : surface complète overridée pour piloter le retour.
	 *
	 * @param array<string, callable> $overrides Surcharges par méthode.
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

			public function start_step( array $post_ids, array $rule_ids, ?int $user_id = null ): string {
				return isset( $this->overrides['start_step'] )
					? ( $this->overrides['start_step'] )( $post_ids, $rule_ids, $user_id )
					: parent::start_step( $post_ids, $rule_ids, $user_id );
			}
			public function process_article( string $uuid, int $post_id, bool $dry_run = false ): ArticleResult {
				return isset( $this->overrides['process_article'] )
					? ( $this->overrides['process_article'] )( $uuid, $post_id, $dry_run )
					: parent::process_article( $uuid, $post_id, $dry_run );
			}
			public function confirm_article( string $uuid, int $post_id ): ArticleResult {
				return isset( $this->overrides['confirm_article'] )
					? ( $this->overrides['confirm_article'] )( $uuid, $post_id )
					: parent::confirm_article( $uuid, $post_id );
			}
			public function refuse_article( string $uuid, int $post_id ): ArticleResult {
				return isset( $this->overrides['refuse_article'] )
					? ( $this->overrides['refuse_article'] )( $uuid, $post_id )
					: parent::refuse_article( $uuid, $post_id );
			}
			public function resume_progress( string $uuid ): ?array {
				return isset( $this->overrides['resume_progress'] )
					? ( $this->overrides['resume_progress'] )( $uuid )
					: parent::resume_progress( $uuid );
			}
			public function finalize_step( string $uuid ): ?StepRecord {
				return isset( $this->overrides['finalize_step'] )
					? ( $this->overrides['finalize_step'] )( $uuid )
					: parent::finalize_step( $uuid );
			}
		};
	}

	/**
	 * StepsRepository stub avec données en mémoire.
	 *
	 * @param array<string, StepRecord>      $by_uuid Records par uuid pour find_by_uuid.
	 * @param list<StepRecord>               $list    Records pour list_filtered.
	 * @param int|null                       $count   Override count_filtered.
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
			affected_post_ids: array( 100, 101 ),
			total_articles: 2,
			successful_articles: $finished ? 2 : 0,
			refused_articles: 0,
			errored_articles: 0,
			pending_articles: 0,
			per_article_results: array(),
			user_id: 5,
			started_at: '2026-05-09 10:00:00',
			finished_at: $finished ? '2026-05-09 10:05:00' : null,
		);
	}

	private function make_request( string $method = 'GET', array $params = array() ): WP_REST_Request {
		$req = new WP_REST_Request( $method, '/steps' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function snapshot_zero(): MetricsSnapshot {
		return MetricsSnapshot::zero();
	}

	private function make_controller(
		?StepRunner $runner = null,
		?StepsRepository $repo = null,
		?\Cent_Son\Html_Normalizer\Steps\RollbackService $rollback = null
	): StepsController {
		return new StepsController(
			$runner ?? $this->runner_stub(),
			$repo ?? $this->repo_stub(),
			$rollback ?? $this->rollback_stub(),
		);
	}

	/**
	 * Stub minimal de RollbackService — extends pour court-circuiter le
	 * constructeur (qui exige des dépendances `final readonly`). Comme on ne
	 * teste pas le rollback dans cette suite, on retourne un résultat vide
	 * neutre — les tests de rollback proprement dits vivent ailleurs.
	 */
	private function rollback_stub(): \Cent_Son\Html_Normalizer\Steps\RollbackService {
		return new class() extends \Cent_Son\Html_Normalizer\Steps\RollbackService {
			public function __construct() {} // phpcs:ignore Generic.CodeAnalysis.UselessOverriding
			public function rollback_step( string $uuid, ?array $post_ids = null, bool $dry_run = false ): array {
				return array(
					'step'    => null,
					'actions' => array(),
					'cascade' => array(),
					'summary' => array(
						'rolled_back' => 0,
						'skipped'     => 0,
						'errors'      => 0,
						'dry_run'     => $dry_run,
					),
				);
			}
		};
	}

	// =========================================================================
	//  register_routes
	// =========================================================================

	public function test_register_routes_creates_eight_endpoints(): void {
		// Post-rollback : ajout de `POST /steps/<uuid>/rollback` (cf. F-rollback).
		$this->make_controller()->register_routes();
		$this->assertCount( 8, $GLOBALS['son100_htmln_test_rest_routes'] );
	}

	public function test_register_routes_uses_htmln_v1_namespace(): void {
		$this->make_controller()->register_routes();
		foreach ( $GLOBALS['son100_htmln_test_rest_routes'] as $entry ) {
			$this->assertSame( 'htmln/v1', $entry['namespace'] );
		}
	}

	public function test_register_routes_set_permission_callback_to_manage_options(): void {
		// Post-v1.0.0 : les routes mutatives (POST/PUT/DELETE) sont protégées
		// par `permission_check_locked` (capability + verrou session), les
		// routes de lecture (GET) restent sur `permission_check_manage_options`.
		$controller = $this->make_controller();
		$controller->register_routes();
		foreach ( $GLOBALS['son100_htmln_test_rest_routes'] as $entry ) {
			$this->assertIsArray( $entry['args']['permission_callback'] );
			$this->assertSame( $controller, $entry['args']['permission_callback'][0] );
			$expected = 'GET' === $entry['args']['methods']
				? 'permission_check_manage_options'
				: 'permission_check_locked';
			$this->assertSame( $expected, $entry['args']['permission_callback'][1] );
		}
	}

	// =========================================================================
	//  GET /steps — list_steps
	// =========================================================================

	public function test_list_steps_returns_paginated_envelope(): void {
		$records   = array(
			$this->make_step_record( 'uuid-1' ),
			$this->make_step_record( 'uuid-2' ),
		);
		$controller = $this->make_controller( null, $this->repo_stub( array(), $records, 2 ) );
		$response  = $controller->list_steps( $this->make_request( 'GET', array(
			'page'     => 1,
			'per_page' => 50,
		) ) );

		$body = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $body['items'] );
		$this->assertSame( 2, $body['total'] );
		$this->assertSame( 1, $body['page'] );
		$this->assertSame( 50, $body['per_page'] );
		$this->assertSame( 1, $body['total_pages'] );
	}

	public function test_list_steps_caps_per_page_at_max(): void {
		$controller = $this->make_controller( null, $this->repo_stub( array(), array(), 0 ) );
		$response   = $controller->list_steps( $this->make_request( 'GET', array(
			'per_page' => 500, // au-dessus de MAX_PER_PAGE = 200
		) ) );
		$this->assertSame( StepsController::MAX_PER_PAGE, $response->get_data()['per_page'] );
	}

	public function test_list_steps_total_pages_uses_ceil(): void {
		$controller = $this->make_controller( null, $this->repo_stub( array(), array(), 51 ) );
		$response   = $controller->list_steps( $this->make_request( 'GET', array(
			'page'     => 1,
			'per_page' => 50,
		) ) );
		$this->assertSame( 2, $response->get_data()['total_pages'], '51 items / 50 per_page = 2 pages' );
	}

	// =========================================================================
	//  GET /steps/<uuid> — get_step
	// =========================================================================

	public function test_get_step_returns_step_and_progress(): void {
		$record   = $this->make_step_record( 'uuid-X' );
		$progress = array(
			'uuid'               => 'uuid-X',
			'total_articles'     => 2,
			'processed'          => array(),
			'regression_pending' => array(),
			'pending'            => array( 100, 101 ),
		);

		$runner = $this->runner_stub( array(
			'resume_progress' => fn() => $progress,
		) );
		$repo   = $this->repo_stub( array( 'uuid-X' => $record ) );

		$response = $this->make_controller( $runner, $repo )->get_step(
			$this->make_request( 'GET', array( 'uuid' => 'uuid-X' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'uuid-X', $body['step']['uuid'] );
		$this->assertSame( $progress, $body['progress'] );
	}

	public function test_get_step_returns_404_for_unknown_uuid(): void {
		$runner = $this->runner_stub( array(
			'resume_progress' => fn() => null,
		) );
		$response = $this->make_controller( $runner )->get_step(
			$this->make_request( 'GET', array( 'uuid' => 'unknown' ) )
		);
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'step_not_found', $response->get_data()['code'] );
	}

	// =========================================================================
	//  POST /steps/run — run_step
	// =========================================================================

	public function test_run_step_returns_201_with_uuid(): void {
		$runner = $this->runner_stub( array(
			'start_step' => fn() => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
		) );
		$response = $this->make_controller( $runner )->run_step(
			$this->make_request( 'POST', array(
				'post_ids' => array( 100, 101, 102 ),
				'rule_ids' => array( 'R1' ),
			) )
		);

		$this->assertSame( 201, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $body['uuid'] );
		$this->assertSame( 3, $body['total_articles'] );
	}

	public function test_run_step_400_for_empty_post_ids(): void {
		$response = $this->make_controller()->run_step(
			$this->make_request( 'POST', array(
				'post_ids' => array(),
				'rule_ids' => array( 'R1' ),
			) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_post_ids', $response->get_data()['code'] );
	}

	public function test_run_step_400_for_empty_rule_ids(): void {
		$response = $this->make_controller()->run_step(
			$this->make_request( 'POST', array(
				'post_ids' => array( 100 ),
				'rule_ids' => array(),
			) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_rule_ids', $response->get_data()['code'] );
	}

	public function test_run_step_500_when_runtime_exception(): void {
		$runner = $this->runner_stub( array(
			'start_step' => function () { throw new \RuntimeException( 'db-down' ); },
		) );
		$response = $this->make_controller( $runner )->run_step(
			$this->make_request( 'POST', array(
				'post_ids' => array( 100 ),
				'rule_ids' => array( 'R1' ),
			) )
		);
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'start_step_failed', $response->get_data()['code'] );
	}

	// =========================================================================
	//  POST /steps/<uuid>/process — process_chunk
	// =========================================================================

	public function test_process_chunk_returns_results_per_article(): void {
		$snap     = $this->snapshot_zero();
		$call_log = array();
		$runner   = $this->runner_stub( array(
			'process_article' => function ( $uuid, $post_id, $dry_run ) use ( $snap, &$call_log ) {
				$call_log[] = array( $uuid, $post_id, $dry_run );
				return ArticleResult::success( $snap, $snap );
			},
		) );

		$response = $this->make_controller( $runner )->process_chunk(
			$this->make_request( 'POST', array(
				'uuid'           => 'uuid-X',
				'chunk_post_ids' => array( 100, 101 ),
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 2, $body['processed_count'] );
		$this->assertArrayHasKey( 100, $body['results'] );
		$this->assertArrayHasKey( 101, $body['results'] );
		$this->assertSame( 'success', $body['results'][100]['status'] );
		$this->assertCount( 2, $call_log );
		$this->assertSame( array( 'uuid-X', 100, false ), $call_log[0] );
	}

	public function test_process_chunk_400_for_empty_chunk_post_ids(): void {
		$response = $this->make_controller()->process_chunk(
			$this->make_request( 'POST', array( 'uuid' => 'uuid-X', 'chunk_post_ids' => array() ) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_chunk_post_ids', $response->get_data()['code'] );
	}

	public function test_process_chunk_passes_dry_run_to_runner(): void {
		$snap     = $this->snapshot_zero();
		$received = null;
		$runner   = $this->runner_stub( array(
			'process_article' => function ( $u, $p, $dry ) use ( $snap, &$received ) {
				$received = $dry;
				return ArticleResult::dry_run( $snap, $snap );
			},
		) );
		$this->make_controller( $runner )->process_chunk(
			$this->make_request( 'POST', array(
				'uuid'           => 'uuid-X',
				'chunk_post_ids' => array( 100 ),
				'dry_run'        => true,
			) )
		);
		$this->assertTrue( $received );
	}

	public function test_process_chunk_response_includes_metrics_snapshots(): void {
		$snap = $this->snapshot_zero();
		$runner = $this->runner_stub( array(
			'process_article' => fn() => ArticleResult::success( $snap, $snap ),
		) );
		$response = $this->make_controller( $runner )->process_chunk(
			$this->make_request( 'POST', array( 'uuid' => 'uuid-X', 'chunk_post_ids' => array( 100 ) ) )
		);
		$body = $response->get_data();
		$this->assertArrayHasKey( 'metrics_before', $body['results'][100] );
		$this->assertArrayHasKey( 'metrics_after', $body['results'][100] );
	}

	// =========================================================================
	//  POST /steps/<uuid>/confirm-article — confirm_article_decision
	// =========================================================================

	public function test_confirm_decision_dispatches_to_confirm_article(): void {
		$snap   = $this->snapshot_zero();
		$called = null;
		$runner = $this->runner_stub( array(
			'confirm_article' => function ( $u, $p ) use ( $snap, &$called ) {
				$called = array( $u, $p );
				return ArticleResult::success( $snap, $snap );
			},
		) );
		$response = $this->make_controller( $runner )->confirm_article_decision(
			$this->make_request( 'POST', array(
				'uuid'     => 'uuid-X',
				'post_id'  => 100,
				'decision' => 'confirm',
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'uuid-X', 100 ), $called );
		$this->assertSame( 'success', $response->get_data()['result']['status'] );
	}

	public function test_refuse_decision_dispatches_to_refuse_article(): void {
		$snap   = $this->snapshot_zero();
		$called = null;
		$runner = $this->runner_stub( array(
			'refuse_article' => function ( $u, $p ) use ( $snap, &$called ) {
				$called = array( $u, $p );
				return ArticleResult::refused( $snap, $snap );
			},
		) );
		$response = $this->make_controller( $runner )->confirm_article_decision(
			$this->make_request( 'POST', array(
				'uuid'     => 'uuid-X',
				'post_id'  => 100,
				'decision' => 'refuse',
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'uuid-X', 100 ), $called );
		$this->assertSame( 'refused', $response->get_data()['result']['status'] );
	}

	public function test_confirm_decision_400_for_invalid_decision(): void {
		$response = $this->make_controller()->confirm_article_decision(
			$this->make_request( 'POST', array(
				'uuid'     => 'uuid-X',
				'post_id'  => 100,
				'decision' => 'maybe',
			) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_decision', $response->get_data()['code'] );
	}

	public function test_confirm_decision_400_for_zero_post_id(): void {
		$response = $this->make_controller()->confirm_article_decision(
			$this->make_request( 'POST', array(
				'uuid'     => 'uuid-X',
				'post_id'  => 0,
				'decision' => 'confirm',
			) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_post_id', $response->get_data()['code'] );
	}

	// =========================================================================
	//  POST /steps/<uuid>/finalize — finalize
	// =========================================================================

	public function test_finalize_returns_finalized_step(): void {
		$record = $this->make_step_record( 'uuid-X', finished: true );
		$runner = $this->runner_stub( array(
			'finalize_step' => fn() => $record,
		) );
		$response = $this->make_controller( $runner )->finalize(
			$this->make_request( 'POST', array( 'uuid' => 'uuid-X' ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'uuid-X', $body['step']['uuid'] );
		$this->assertTrue( $body['step']['is_finished'] );
	}

	public function test_finalize_returns_404_for_unknown_uuid(): void {
		$runner = $this->runner_stub( array(
			'finalize_step' => fn() => null,
		) );
		$response = $this->make_controller( $runner )->finalize(
			$this->make_request( 'POST', array( 'uuid' => 'unknown' ) )
		);
		$this->assertSame( 404, $response->get_status() );
	}

	// =========================================================================
	//  GET /steps/export — export
	// =========================================================================

	public function test_export_returns_items_and_total(): void {
		$records = array(
			$this->make_step_record( 'uuid-1' ),
			$this->make_step_record( 'uuid-2' ),
		);
		$controller = $this->make_controller( null, $this->repo_stub( array(), $records, 2 ) );
		$response   = $controller->export( $this->make_request( 'GET' ) );

		$body = $response->get_data();
		$this->assertCount( 2, $body['items'] );
		$this->assertSame( 2, $body['total'] );
		$this->assertFalse( $body['capped'] );
	}

	public function test_export_marks_capped_when_total_exceeds_max(): void {
		$controller = $this->make_controller( null, $this->repo_stub( array(), array(), 500 ) );
		$response   = $controller->export( $this->make_request( 'GET' ) );

		$body = $response->get_data();
		$this->assertTrue( $body['capped'] );
		$this->assertSame( StepsController::EXPORT_MAX, $body['capped_at'] );
		$this->assertSame( 500, $body['total'] );
	}
}
