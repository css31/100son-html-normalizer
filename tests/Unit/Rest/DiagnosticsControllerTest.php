<?php
/**
 * Tests DiagnosticsController — Phase 5.3 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticBatchRunner;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Rest\DiagnosticsController;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use Son100_Htmln_Test_Wpdb;
use WP_REST_Request;

final class DiagnosticsControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;
		Son100_Htmln_Test_Posts_Registry::reset();
	}

	// =========================================================================
	//  Helpers — stubs.
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
			public function start_batch( ?int $chunk_size = null, ?array $post_types_override = null ): array {
				return isset( $this->overrides['start_batch'] )
					? ( $this->overrides['start_batch'] )( $chunk_size, $post_types_override )
					: parent::start_batch( $chunk_size, $post_types_override );
			}
			public function process_chunk( array $post_ids ): array {
				return isset( $this->overrides['process_chunk'] )
					? ( $this->overrides['process_chunk'] )( $post_ids )
					: parent::process_chunk( $post_ids );
			}
		};
	}

	/**
	 * @param array<int, DiagnosticRecord>      $by_post_id
	 * @param list<DiagnosticRecord>            $list
	 * @param int|null                          $count
	 * @param array<string, int>|null           $stats
	 * @param array{post_id: int, deleted: bool}|null $delete_result
	 */
	private function repo_stub(
		array $by_post_id = array(),
		array $list = array(),
		?int $count = null,
		?array $stats = null,
		?array $delete_result = null
	): DiagnosticsRepository {
		$wpdb = new Son100_Htmln_Test_Wpdb();
		return new class( $wpdb, $by_post_id, $list, $count, $stats, $delete_result ) extends DiagnosticsRepository {
			/**
			 * @param array<int, DiagnosticRecord>            $by_post_id
			 * @param list<DiagnosticRecord>                  $list
			 * @param array<string, int>|null                 $stats
			 * @param array{post_id: int, deleted: bool}|null $delete_result
			 */
			public function __construct(
				Son100_Htmln_Test_Wpdb $wpdb,
				private array $by_post_id,
				private array $list,
				private ?int $count_override,
				private ?array $stats_override,
				private ?array $delete_override
			) {
				parent::__construct( $wpdb );
			}
			public function find_by_post_id( int $post_id ): ?DiagnosticRecord {
				return $this->by_post_id[ $post_id ] ?? null;
			}
			public function list_paginated( ?string $status, int $limit = 50, int $offset = 0, array $filters = array() ): array {
				$this->last_filters = $filters;
				return array_slice( $this->list, $offset, $limit );
			}
			public function count_paginated( ?string $status, array $filters = array() ): int {
				return $this->count_override ?? count( $this->list );
			}
			public function list_distinct_years(): array {
				return array();
			}
			public function count_by_builder(): array {
				return array(
					'siteorigin' => 0,
					'gutenberg'  => 0,
					'other'      => 0,
					'out'        => 0,
					'unknown'    => 0,
				);
			}
			public function count_by_applicable_rule(): array {
				// Aligné sur PresetRegistry::PRESETS — toutes clés présentes (UX stable).
				return array(
					'P3' => 0, 'P4' => 0, 'P8' => 0, 'P6' => 0,
					'P7' => 0, 'P5' => 0, 'P9' => 0, 'P1' => 0, 'P2' => 0,
				);
			}
			/**
			 * Capture le dernier `$filters` reçu par list_paginated() pour
			 * permettre aux tests de vérifier le parsing du contrôleur.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $last_filters = null;
			public function count_null_builder_types(): int {
				return 0;
			}
			public function backfill_builder_types_batch(
				object $classifier,
				int $batch_size = 500
			): int {
				unset( $classifier, $batch_size );
				return 0;
			}
			public function count_by_status(): array {
				return $this->stats_override ?? array(
					'normal' => 0, 'to_improve' => 0, 'stale' => 0, 'total' => 0,
				);
			}
			public function delete_for_post( int $post_id ): bool {
				if ( null !== $this->delete_override ) {
					return $this->delete_override['post_id'] === $post_id
						? $this->delete_override['deleted']
						: false;
				}
				return false;
			}
		};
	}

	private function make_diagnostic_record( int $post_id, string $status = 'to_improve' ): DiagnosticRecord {
		return new DiagnosticRecord(
			id: 1,
			post_id: $post_id,
			status: $status,
			matching_rules: array( array( 'rule_id' => 'P1', 'occurrences' => 2 ) ),
			metrics: array(),
			is_stale: false,
			diagnosed_at: '2026-05-09 10:00:00',
			post_modified_at_diagnosis: '2026-05-09 09:00:00',
		);
	}

	private function make_request( string $method = 'GET', array $params = array() ): WP_REST_Request {
		$req = new WP_REST_Request( $method, '/diagnostics' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function make_controller(
		?DiagnosticBatchRunner $runner = null,
		?DiagnosticsRepository $repo = null
	): DiagnosticsController {
		return new DiagnosticsController(
			$runner ?? $this->runner_stub(),
			$repo ?? $this->repo_stub(),
		);
	}

	// =========================================================================
	//  register_routes
	// =========================================================================

	public function test_register_routes_creates_seven_endpoints(): void {
		$this->make_controller()->register_routes();
		// 6 endpoints initiaux + /diagnostics/facets (post-rc3).
		$this->assertCount( 7, $GLOBALS['son100_htmln_test_rest_routes'] );
	}

	public function test_register_routes_uses_htmln_v1_namespace(): void {
		$this->make_controller()->register_routes();
		foreach ( $GLOBALS['son100_htmln_test_rest_routes'] as $entry ) {
			$this->assertSame( 'htmln/v1', $entry['namespace'] );
		}
	}

	// =========================================================================
	//  GET /diagnostics — list
	// =========================================================================

	public function test_list_returns_paginated_envelope(): void {
		$records   = array(
			$this->make_diagnostic_record( 100 ),
			$this->make_diagnostic_record( 101, 'normal' ),
		);
		$controller = $this->make_controller( null, $this->repo_stub( array(), $records, 2 ) );
		$response   = $controller->list_diagnostics( $this->make_request() );

		$body = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $body['items'] );
		$this->assertSame( 2, $body['total'] );
		$this->assertSame( 100, $body['items'][0]['post_id'] );
	}

	public function test_list_400_for_invalid_status(): void {
		$response = $this->make_controller()->list_diagnostics(
			$this->make_request( 'GET', array( 'status' => 'bogus' ) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_status', $response->get_data()['code'] );
	}

	public function test_list_accepts_status_to_improve(): void {
		$records = array( $this->make_diagnostic_record( 100 ) );
		$controller = $this->make_controller( null, $this->repo_stub( array(), $records, 1 ) );
		$response   = $controller->list_diagnostics(
			$this->make_request( 'GET', array( 'status' => 'to_improve' ) )
		);
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_list_accepts_empty_status_as_no_filter(): void {
		$controller = $this->make_controller();
		$response   = $controller->list_diagnostics(
			$this->make_request( 'GET', array( 'status' => '' ) )
		);
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_list_caps_per_page_at_max(): void {
		$controller = $this->make_controller();
		$response   = $controller->list_diagnostics(
			$this->make_request( 'GET', array( 'per_page' => 9999 ) )
		);
		$this->assertSame( DiagnosticsController::MAX_PER_PAGE, $response->get_data()['per_page'] );
	}

	// =========================================================================
	//  GET /diagnostics/<post_id>
	// =========================================================================

	public function test_get_returns_diagnostic(): void {
		$record   = $this->make_diagnostic_record( 100 );
		$response = $this->make_controller(
			null,
			$this->repo_stub( array( 100 => $record ) )
		)->get_diagnostic( $this->make_request( 'GET', array( 'post_id' => 100 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 100, $response->get_data()['diagnostic']['post_id'] );
	}

	public function test_get_404_for_unknown_post(): void {
		$response = $this->make_controller()->get_diagnostic(
			$this->make_request( 'GET', array( 'post_id' => 999 ) )
		);
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'diagnostic_not_found', $response->get_data()['code'] );
	}

	// =========================================================================
	//  DELETE /diagnostics/<post_id>
	// =========================================================================

	public function test_delete_returns_true_when_existed(): void {
		$response = $this->make_controller(
			null,
			$this->repo_stub( delete_result: array( 'post_id' => 100, 'deleted' => true ) )
		)->delete_diagnostic( $this->make_request( 'DELETE', array( 'post_id' => 100 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertTrue( $body['deleted'] );
		$this->assertSame( 100, $body['post_id'] );
	}

	public function test_delete_returns_false_when_idempotent(): void {
		// Aucun diagnostic à supprimer → repo retourne false → 200 (pas 404).
		$response = $this->make_controller()->delete_diagnostic(
			$this->make_request( 'DELETE', array( 'post_id' => 999 ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['deleted'] );
	}

	// =========================================================================
	//  GET /diagnostics/stats
	// =========================================================================

	public function test_stats_returns_four_counts(): void {
		$repo = $this->repo_stub(
			stats: array( 'normal' => 12, 'to_improve' => 34, 'stale' => 5, 'total' => 51 )
		);
		$response = $this->make_controller( null, $repo )->stats( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'normal' => 12, 'to_improve' => 34, 'stale' => 5, 'total' => 51 ),
			$response->get_data()
		);
	}

	// =========================================================================
	//  POST /diagnostics/run
	// =========================================================================

	public function test_run_batch_starts_scan_with_defaults(): void {
		$called  = null;
		$runner  = $this->runner_stub( array(
			'start_batch' => function ( $chunk_size, $post_types ) use ( &$called ) {
				$called = array( 'chunk_size' => $chunk_size, 'post_types' => $post_types );
				return array(
					'batch_id'       => 'job-uuid-1',
					'total_articles' => 3,
					'post_ids'       => array( 100, 101, 102 ),
					'chunk_size'     => 20,
				);
			},
		) );
		$response = $this->make_controller( $runner )->run_batch( $this->make_request( 'POST' ) );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'job-uuid-1', $body['job_id'] );
		$this->assertSame( 3, $body['total_articles'] );
		$this->assertSame( array( 100, 101, 102 ), $body['post_ids'] );
		$this->assertNull( $called['chunk_size'] );
		$this->assertNull( $called['post_types'] );
	}

	public function test_run_batch_with_chunk_size_override(): void {
		$received = null;
		$runner   = $this->runner_stub( array(
			'start_batch' => function ( $chunk_size, $post_types ) use ( &$received ) {
				$received = $chunk_size;
				return array( 'batch_id' => 'x', 'total_articles' => 0, 'post_ids' => array(), 'chunk_size' => $chunk_size ?? 10 );
			},
		) );
		$this->make_controller( $runner )->run_batch(
			$this->make_request( 'POST', array( 'chunk_size' => 50 ) )
		);
		$this->assertSame( 50, $received );
	}

	public function test_run_batch_with_post_types_override(): void {
		$received = null;
		$runner   = $this->runner_stub( array(
			'start_batch' => function ( $chunk_size, $post_types ) use ( &$received ) {
				$received = $post_types;
				return array( 'batch_id' => 'x', 'total_articles' => 0, 'post_ids' => array(), 'chunk_size' => 20 );
			},
		) );
		$this->make_controller( $runner )->run_batch(
			$this->make_request( 'POST', array( 'post_types' => array( 'post', 'page' ) ) )
		);
		$this->assertSame( array( 'post', 'page' ), $received );
	}

	// =========================================================================
	//  POST /diagnostics/run/chunk
	// =========================================================================

	public function test_run_chunk_processes_articles(): void {
		$received = null;
		$runner   = $this->runner_stub( array(
			'process_chunk' => function ( $post_ids ) use ( &$received ) {
				$received = $post_ids;
				$out = array();
				foreach ( $post_ids as $id ) {
					$out[ $id ] = $this->make_record( $id );
				}
				return $out;
			},
		) );

		$response = $this->make_controller( $runner )->run_chunk(
			$this->make_request( 'POST', array(
				'job_id'         => 'client-uuid',
				'chunk_post_ids' => array( 100, 101 ),
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'client-uuid', $body['job_id'] );
		$this->assertSame( 2, $body['processed'] );
		$this->assertSame( array( 100, 101 ), $received );
	}

	private function make_record( int $post_id ): DiagnosticRecord {
		return $this->make_diagnostic_record( $post_id );
	}

	public function test_run_chunk_400_for_empty_chunk_post_ids(): void {
		$response = $this->make_controller()->run_chunk(
			$this->make_request( 'POST', array( 'chunk_post_ids' => array() ) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_chunk_post_ids', $response->get_data()['code'] );
	}

	public function test_run_chunk_echoes_empty_job_id_when_missing(): void {
		$runner = $this->runner_stub( array(
			'process_chunk' => fn( $ids ) => array(),
		) );
		$response = $this->make_controller( $runner )->run_chunk(
			$this->make_request( 'POST', array( 'chunk_post_ids' => array( 100 ) ) )
		);
		$this->assertSame( '', $response->get_data()['job_id'] );
	}

	// =========================================================================
	//  rule_ids[] (rc4 — filtre multi-règles depuis FiltersBar SPA)
	// =========================================================================

	public function test_list_forwards_valid_rule_ids_to_repo_filter(): void {
		$repo       = $this->repo_stub( array(), array(), 0 );
		$controller = $this->make_controller( null, $repo );
		$response   = $controller->list_diagnostics(
			$this->make_request( 'GET', array( 'rule_ids' => array( 'P1', 'P5' ) ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'P1', 'P5' ), $repo->last_filters['rule_ids'] ?? null );
	}

	public function test_list_filters_out_unknown_rule_ids(): void {
		$repo       = $this->repo_stub( array(), array(), 0 );
		$controller = $this->make_controller( null, $repo );
		// `INVALID` n'est pas dans PresetRegistry::PRESETS → ignoré ;
		// `P9` reste → tableau non vide donc clé `rule_ids` présente.
		$controller->list_diagnostics(
			$this->make_request( 'GET', array( 'rule_ids' => array( 'INVALID', 'P9' ) ) )
		);
		$this->assertSame( array( 'P9' ), $repo->last_filters['rule_ids'] ?? null );
	}

	public function test_list_omits_rule_ids_filter_when_only_invalid_values(): void {
		$repo       = $this->repo_stub( array(), array(), 0 );
		$controller = $this->make_controller( null, $repo );
		$controller->list_diagnostics(
			$this->make_request( 'GET', array( 'rule_ids' => array( 'INVALID', 'Pxxx' ) ) )
		);
		// Tous les IDs filtrés → clé `rule_ids` absente du filtre (UX :
		// équivalent « pas de filtre rule_ids », pas « zéro résultat »).
		$this->assertArrayNotHasKey( 'rule_ids', $repo->last_filters ?? array() );
	}

	public function test_list_ignores_non_array_rule_ids(): void {
		$repo       = $this->repo_stub( array(), array(), 0 );
		$controller = $this->make_controller( null, $repo );
		// Une string nue ne doit pas être interprétée comme un ID — l'API
		// est volontairement strict sur le contrat « tableau ou rien ».
		$controller->list_diagnostics(
			$this->make_request( 'GET', array( 'rule_ids' => 'P1' ) )
		);
		$this->assertArrayNotHasKey( 'rule_ids', $repo->last_filters ?? array() );
	}

	public function test_list_dedupes_rule_ids(): void {
		$repo       = $this->repo_stub( array(), array(), 0 );
		$controller = $this->make_controller( null, $repo );
		$controller->list_diagnostics(
			$this->make_request( 'GET', array( 'rule_ids' => array( 'P1', 'P1', 'P5' ) ) )
		);
		$this->assertSame( array( 'P1', 'P5' ), $repo->last_filters['rule_ids'] ?? null );
	}

	public function test_facets_includes_applicable_rules_key(): void {
		$controller = $this->make_controller();
		$response   = $controller->get_facets( $this->make_request() );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'applicable_rules', $data );
		$this->assertIsArray( $data['applicable_rules'] );
		// Toutes les clés PresetRegistry::PRESETS présentes pour UX stable.
		foreach ( array( 'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9' ) as $rid ) {
			$this->assertArrayHasKey( $rid, $data['applicable_rules'] );
		}
	}

	// =========================================================================
	//  has_fossil_panels_data (rc4 — signal Gutenberg + vestige SO)
	// =========================================================================

	/**
	 * Helper : fabrique un record + l'enregistre côté registry de posts.
	 *
	 * @param int    $post_id      Identifiant.
	 * @param string $builder_type Type persisté (`gutenberg`, `siteorigin`…).
	 * @param array  $meta         Post-meta à associer.
	 * @return DiagnosticRecord
	 */
	private function record_with_meta(
		int $post_id,
		string $builder_type,
		array $meta = array()
	): DiagnosticRecord {
		$post              = new \WP_Post();
		$post->ID          = $post_id;
		$post->post_title  = '';
		$post->post_content = '';
		$post->post_date   = '2026-05-12 09:00:00';
		Son100_Htmln_Test_Posts_Registry::$posts[ $post_id ] = $post;
		Son100_Htmln_Test_Posts_Registry::$meta[ $post_id ]  = $meta;

		return new DiagnosticRecord(
			id: 1,
			post_id: $post_id,
			status: 'normal',
			matching_rules: array(),
			metrics: array(),
			is_stale: false,
			diagnosed_at: '2026-05-12 10:00:00',
			post_modified_at_diagnosis: '2026-05-12 09:00:00',
			builder_type: $builder_type,
		);
	}

	public function test_gutenberg_with_panels_data_meta_flags_fossil_true(): void {
		$record = $this->record_with_meta(
			100,
			'gutenberg',
			array( 'panels_data' => array( 'widgets' => array( 'x' ) ) )
		);
		$controller = $this->make_controller( null, $this->repo_stub( array(), array( $record ), 1 ) );
		$response   = $controller->list_diagnostics( $this->make_request() );
		$item       = $response->get_data()['items'][0];

		$this->assertArrayHasKey( 'has_fossil_panels_data', $item );
		$this->assertTrue( $item['has_fossil_panels_data'] );
	}

	public function test_gutenberg_without_panels_data_flags_fossil_false(): void {
		$record = $this->record_with_meta( 101, 'gutenberg', array() );
		$controller = $this->make_controller( null, $this->repo_stub( array(), array( $record ), 1 ) );
		$response   = $controller->list_diagnostics( $this->make_request() );
		$item       = $response->get_data()['items'][0];

		$this->assertFalse( $item['has_fossil_panels_data'] );
	}

	public function test_siteorigin_with_panels_data_does_not_flag_fossil(): void {
		// Pour un article SO, `panels_data` est attendu — pas un fossile.
		// Le flag doit rester false (signal réservé au cas Gut + vestige).
		$record = $this->record_with_meta(
			102,
			'siteorigin',
			array( 'panels_data' => array( 'widgets' => array( 'x' ) ) )
		);
		$controller = $this->make_controller( null, $this->repo_stub( array(), array( $record ), 1 ) );
		$response   = $controller->list_diagnostics( $this->make_request() );
		$item       = $response->get_data()['items'][0];

		$this->assertFalse( $item['has_fossil_panels_data'] );
	}

	public function test_gutenberg_with_empty_panels_data_array_is_not_fossil(): void {
		// `panels_data = []` est considéré comme absent (cf. `panels_data_is_non_empty`).
		$record = $this->record_with_meta(
			103,
			'gutenberg',
			array( 'panels_data' => array() )
		);
		$controller = $this->make_controller( null, $this->repo_stub( array(), array( $record ), 1 ) );
		$response   = $controller->list_diagnostics( $this->make_request() );
		$item       = $response->get_data()['items'][0];

		$this->assertFalse( $item['has_fossil_panels_data'] );
	}
}
