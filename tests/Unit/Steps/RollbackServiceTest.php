<?php
/**
 * Tests RollbackService — F-rollback.
 *
 * Couvre :
 *  - périmètre (tout le step / sous-ensemble post_ids) ;
 *  - skips explicites (no_result, not_success, revision_not_captured,
 *    revision_purged, revision_parent_mismatch) ;
 *  - dry_run = pas d'écriture, plan retourné ;
 *  - cascade detection : seuls les steps postérieurs ayant ré-écrit en
 *    `success` sont remontés (pas les error / regression_pending) ;
 *  - erreur d'exécution wp_restore_post_revision → status `error`.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Steps;

use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Steps\RollbackService;
use Cent_Son\Html_Normalizer\Steps\StepRecord;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use WP_Post;

final class RollbackServiceTest extends TestCase {

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$GLOBALS['son100_htmln_test_restored_revisions'] = array();
		$GLOBALS['son100_htmln_test_restore_returns']    = array();
	}

	// =========================================================================
	//  Helpers — stubs
	// =========================================================================

	/**
	 * Stub StepsRepository — find_by_uuid retourne le record passé,
	 * find_subsequent_steps_for_post retourne la liste configurée.
	 *
	 * @param StepRecord|null   $record      Pour find_by_uuid.
	 * @param list<StepRecord>  $subsequent  Pour find_subsequent_steps_for_post.
	 */
	private function steps_stub( ?StepRecord $record, array $subsequent = array() ): StepsRepository {
		return new class( $record, $subsequent ) extends StepsRepository {
			public function __construct(
				private readonly ?StepRecord $record,
				private readonly array $subsequent,
			) {
				// Bypass parent constructor (qui ouvrirait wpdb).
			}
			public function find_by_uuid( string $uuid ): ?StepRecord {
				unset( $uuid );
				return $this->record;
			}
			public function find_subsequent_steps_for_post(
				int $post_id,
				string $after_datetime,
				?string $exclude_uuid = null
			): array {
				unset( $post_id, $after_datetime, $exclude_uuid );
				return $this->subsequent;
			}
		};
	}

	private function noop_diagnostics(): DiagnosticsRepository {
		return new class() extends DiagnosticsRepository {
			public function __construct() {}
			public function upsert( mixed $diagnostic ): bool {
				unset( $diagnostic );
				return true;
			}
		};
	}

	private function noop_engine(): DiagnosticEngine {
		return new class() extends DiagnosticEngine {
			public function __construct() {}
			public function diagnose( WP_Post $post ): DiagnosticRecord {
				// Record minimal — RollbackService ne consomme pas le retour,
				// il passe simplement à `DiagnosticsRepository::upsert()`.
				return new DiagnosticRecord(
					id: null,
					post_id: (int) $post->ID,
					status: DiagnosticRecord::STATUS_NORMAL,
					matching_rules: array(),
					metrics: array(),
					is_stale: false,
					diagnosed_at: '2026-05-16 00:00:00',
					post_modified_at_diagnosis: null,
				);
			}
		};
	}

	private function make_service( ?StepRecord $record, array $subsequent = array() ): RollbackService {
		return new RollbackService(
			$this->steps_stub( $record, $subsequent ),
			$this->noop_diagnostics(),
			$this->noop_engine(),
		);
	}

	private function make_step(
		array $per_article,
		array $affected = array( 100, 101, 102 ),
		string $finished = '2026-05-09 10:05:00',
	): StepRecord {
		return new StepRecord(
			id: 1,
			step_uuid: 'uuid-step-1',
			applied_rules: array( 'R5' ),
			affected_post_ids: $affected,
			total_articles: count( $affected ),
			successful_articles: 0,
			refused_articles: 0,
			errored_articles: 0,
			pending_articles: 0,
			per_article_results: $per_article,
			user_id: 5,
			started_at: '2026-05-09 10:00:00',
			finished_at: $finished,
		);
	}

	private function register_post( int $id ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		$post->post_content = '<p>content</p>';
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $post;
		return $post;
	}

	private function register_revision( int $rev_id, int $parent_id ): void {
		$rev              = new WP_Post();
		$rev->ID          = $rev_id;
		$rev->post_type   = 'revision';
		$rev->post_parent = $parent_id;
		Son100_Htmln_Test_Posts_Registry::$posts[ $rev_id ] = $rev;
	}

	// =========================================================================
	//  Cas nominal
	// =========================================================================

	public function test_returns_empty_result_when_step_unknown(): void {
		$service = $this->make_service( null );
		$result  = $service->rollback_step( 'unknown', null, false );

		$this->assertNull( $result['step'] );
		$this->assertSame( array(), $result['actions'] );
		$this->assertSame( 0, $result['summary']['rolled_back'] );
	}

	public function test_rollback_full_step_restores_all_success_articles(): void {
		$this->register_post( 100 );
		$this->register_revision( 100100, 100 );
		$this->register_post( 101 );
		$this->register_revision( 100101, 101 );

		$step = $this->make_step( array(
			100 => array( 'status' => 'success', 'revision_id' => 100100 ),
			101 => array( 'status' => 'success', 'revision_id' => 100101 ),
		), array( 100, 101 ) );

		$service = $this->make_service( $step );
		$result  = $service->rollback_step( 'uuid-step-1', null, false );

		$this->assertSame( 2, $result['summary']['rolled_back'] );
		$this->assertSame( 0, $result['summary']['skipped'] );
		$this->assertSame( 0, $result['summary']['errors'] );
		$this->assertSame(
			array( 100100, 100101 ),
			$GLOBALS['son100_htmln_test_restored_revisions']
		);
	}

	public function test_dry_run_does_not_call_wp_restore(): void {
		$this->register_post( 100 );
		$this->register_revision( 100100, 100 );

		$step = $this->make_step(
			array( 100 => array( 'status' => 'success', 'revision_id' => 100100 ) ),
			array( 100 )
		);

		$service = $this->make_service( $step );
		$result  = $service->rollback_step( 'uuid-step-1', null, true );

		$this->assertSame( 1, $result['summary']['rolled_back'] );
		$this->assertTrue( $result['summary']['dry_run'] );
		$this->assertSame( 'would_rollback', $result['actions'][0]['status'] );
		$this->assertSame( array(), $GLOBALS['son100_htmln_test_restored_revisions'] );
	}

	public function test_post_ids_filter_restricts_scope_to_intersection(): void {
		$this->register_post( 100 );
		$this->register_revision( 100100, 100 );
		$this->register_post( 101 );
		$this->register_revision( 100101, 101 );

		$step = $this->make_step( array(
			100 => array( 'status' => 'success', 'revision_id' => 100100 ),
			101 => array( 'status' => 'success', 'revision_id' => 100101 ),
		), array( 100, 101 ) );

		$service = $this->make_service( $step );
		// On ne demande que 101 — 100 et un id hors périmètre (999) doivent être ignorés.
		$result = $service->rollback_step( 'uuid-step-1', array( 101, 999 ), false );

		$this->assertCount( 1, $result['actions'] );
		$this->assertSame( 101, $result['actions'][0]['post_id'] );
		$this->assertSame( array( 100101 ), $GLOBALS['son100_htmln_test_restored_revisions'] );
	}

	// =========================================================================
	//  Skips
	// =========================================================================

	public function test_skip_when_no_per_article_result(): void {
		$step = $this->make_step( array(), array( 100 ) );
		$service = $this->make_service( $step );
		$result = $service->rollback_step( 'uuid-step-1', null, true );

		$this->assertSame( 1, $result['summary']['skipped'] );
		$this->assertSame(
			RollbackService::SKIP_NO_RESULT,
			$result['actions'][0]['reason']
		);
	}

	public function test_skip_when_article_not_success(): void {
		$step = $this->make_step(
			array( 100 => array( 'status' => 'error', 'error' => 'boom' ) ),
			array( 100 )
		);
		$service = $this->make_service( $step );
		$result = $service->rollback_step( 'uuid-step-1', null, true );

		$this->assertSame( 1, $result['summary']['skipped'] );
		$this->assertSame(
			RollbackService::SKIP_NOT_SUCCESS,
			$result['actions'][0]['reason']
		);
	}

	public function test_skip_when_revision_not_captured(): void {
		$step = $this->make_step(
			array( 100 => array( 'status' => 'success' ) ),
			array( 100 )
		);
		$service = $this->make_service( $step );
		$result = $service->rollback_step( 'uuid-step-1', null, true );

		$this->assertSame( 1, $result['summary']['skipped'] );
		$this->assertSame(
			RollbackService::SKIP_REVISION_NOT_CAPTURED,
			$result['actions'][0]['reason']
		);
	}

	public function test_skip_when_revision_purged(): void {
		// revision_id pointe sur un post inexistant dans le registre.
		$step = $this->make_step(
			array( 100 => array( 'status' => 'success', 'revision_id' => 999999 ) ),
			array( 100 )
		);
		$service = $this->make_service( $step );
		$result = $service->rollback_step( 'uuid-step-1', null, true );

		$this->assertSame( 1, $result['summary']['skipped'] );
		$this->assertSame(
			RollbackService::SKIP_REVISION_PURGED,
			$result['actions'][0]['reason']
		);
	}

	public function test_skip_when_revision_parent_mismatch(): void {
		// Révision existe mais pointe sur un autre article.
		$this->register_post( 100 );
		$this->register_revision( 100100, 999 ); // post_parent = 999, pas 100.

		$step = $this->make_step(
			array( 100 => array( 'status' => 'success', 'revision_id' => 100100 ) ),
			array( 100 )
		);
		$service = $this->make_service( $step );
		$result = $service->rollback_step( 'uuid-step-1', null, true );

		$this->assertSame( 1, $result['summary']['skipped'] );
		$this->assertSame(
			RollbackService::SKIP_REVISION_MISMATCH,
			$result['actions'][0]['reason']
		);
	}

	// =========================================================================
	//  Cascade
	// =========================================================================

	public function test_cascade_lists_only_subsequent_success_steps(): void {
		$this->register_post( 100 );
		$this->register_revision( 100100, 100 );

		$step = $this->make_step(
			array( 100 => array( 'status' => 'success', 'revision_id' => 100100 ) ),
			array( 100 )
		);

		// 3 steps postérieurs : 1 success (compte), 1 error (ne compte pas), 1 sans entrée (ne compte pas).
		$later1 = new StepRecord(
			id: 2, step_uuid: 'later-success', applied_rules: array(),
			affected_post_ids: array( 100 ), total_articles: 1,
			successful_articles: 1, refused_articles: 0, errored_articles: 0, pending_articles: 0,
			per_article_results: array( 100 => array( 'status' => 'success' ) ),
			user_id: null, started_at: '2026-05-10 09:00:00', finished_at: '2026-05-10 09:05:00',
		);
		$later2 = new StepRecord(
			id: 3, step_uuid: 'later-error', applied_rules: array(),
			affected_post_ids: array( 100 ), total_articles: 1,
			successful_articles: 0, refused_articles: 0, errored_articles: 1, pending_articles: 0,
			per_article_results: array( 100 => array( 'status' => 'error', 'error' => 'x' ) ),
			user_id: null, started_at: '2026-05-11 09:00:00', finished_at: '2026-05-11 09:05:00',
		);
		$later3 = new StepRecord(
			id: 4, step_uuid: 'later-no-entry', applied_rules: array(),
			affected_post_ids: array( 100 ), total_articles: 1,
			successful_articles: 0, refused_articles: 0, errored_articles: 0, pending_articles: 1,
			per_article_results: array(),
			user_id: null, started_at: '2026-05-12 09:00:00', finished_at: '2026-05-12 09:05:00',
		);

		$service = $this->make_service( $step, array( $later1, $later2, $later3 ) );
		$result  = $service->rollback_step( 'uuid-step-1', null, true );

		$this->assertArrayHasKey( 100, $result['cascade'] );
		$this->assertSame( array( 'later-success' ), $result['cascade'][100] );
	}

	public function test_cascade_not_reported_on_skipped_articles(): void {
		$this->register_post( 100 );
		$this->register_revision( 100100, 100 );

		// Article 100 sera skipped (revision_not_captured).
		$step = $this->make_step(
			array( 100 => array( 'status' => 'success' ) ),
			array( 100 )
		);
		$later = new StepRecord(
			id: 2, step_uuid: 'later', applied_rules: array(),
			affected_post_ids: array( 100 ), total_articles: 1,
			successful_articles: 1, refused_articles: 0, errored_articles: 0, pending_articles: 0,
			per_article_results: array( 100 => array( 'status' => 'success' ) ),
			user_id: null, started_at: '2026-05-10 09:00:00', finished_at: '2026-05-10 09:05:00',
		);
		$service = $this->make_service( $step, array( $later ) );
		$result  = $service->rollback_step( 'uuid-step-1', null, true );

		$this->assertSame( array(), $result['cascade'] );
	}

	// =========================================================================
	//  Erreur d'exécution
	// =========================================================================

	public function test_error_when_wp_restore_returns_null(): void {
		$this->register_post( 100 );
		$this->register_revision( 100100, 100 );
		// Force le retour null (échec WP) sur 100100.
		$GLOBALS['son100_htmln_test_restore_returns'][100100] = null;

		$step = $this->make_step(
			array( 100 => array( 'status' => 'success', 'revision_id' => 100100 ) ),
			array( 100 )
		);
		$service = $this->make_service( $step );
		$result  = $service->rollback_step( 'uuid-step-1', null, false );

		$this->assertSame( 1, $result['summary']['errors'] );
		$this->assertSame( 'error', $result['actions'][0]['status'] );
		$this->assertStringContainsString(
			'wp_restore_post_revision',
			$result['actions'][0]['message']
		);
	}
}
