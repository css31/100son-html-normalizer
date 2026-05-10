<?php
/**
 * Tests StepsRepository — Phase 2.2 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Steps;

use Cent_Son\Html_Normalizer\Steps\StepRecord;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Wpdb;

final class StepsRepositoryTest extends TestCase {

	private Son100_Htmln_Test_Wpdb $wpdb;
	private StepsRepository $repo;
	private string $table;

	protected function setUp(): void {
		$this->wpdb  = new Son100_Htmln_Test_Wpdb();
		$this->table = $this->wpdb->prefix . 'son100_htmln_steps';
		$this->repo  = new StepsRepository( $this->wpdb );
	}

	public function test_find_by_uuid_returns_null_when_absent(): void {
		$this->assertNull( $this->repo->find_by_uuid( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ) );
	}

	public function test_find_by_uuid_decodes_db_row(): void {
		$this->wpdb->get_row_queue[] = array(
			'id'                  => '12',
			'step_uuid'           => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'applied_rules'       => '["P1","P5"]',
			'affected_post_ids'   => '[100,101,102]',
			'total_articles'      => '3',
			'successful_articles' => '2',
			'refused_articles'    => '1',
			'errored_articles'    => '0',
			'per_article_results' => '{"100":{"status":"success"},"101":{"status":"refused","regression":{"images":1}}}',
			'user_id'             => '5',
			'started_at'          => '2026-05-09 10:00:00',
			'finished_at'         => '2026-05-09 10:05:30',
		);
		$record = $this->repo->find_by_uuid( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );
		$this->assertInstanceOf( StepRecord::class, $record );
		$this->assertSame( 12, $record->id );
		$this->assertSame( array( 'P1', 'P5' ), $record->applied_rules );
		$this->assertSame( array( 100, 101, 102 ), $record->affected_post_ids );
		$this->assertSame( 3, $record->total_articles );
		$this->assertSame( 2, $record->successful_articles );
		$this->assertSame( 1, $record->refused_articles );
		$this->assertSame( 'success', $record->per_article_results[100]['status'] );
		$this->assertSame( 'refused', $record->per_article_results[101]['status'] );
		$this->assertTrue( $record->is_finished() );
	}

	public function test_insert_running_creates_record_in_running_state(): void {
		$this->wpdb->insert_return = 1;
		$id = $this->repo->insert_running(
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			array( 'P1', 'P5' ),
			array( 100, 101, 102 ),
			5,
			'2026-05-09 10:00:00'
		);
		$this->assertNotFalse( $id );
		$this->assertCount( 1, $this->wpdb->insert_log );
		$inserted = $this->wpdb->insert_log[0]['data'];
		$this->assertSame( 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $inserted['step_uuid'] );
		$this->assertSame( 3, $inserted['total_articles'] );
		$this->assertSame( 0, $inserted['successful_articles'] );
		$this->assertSame( 0, $inserted['refused_articles'] );
		$this->assertSame( 0, $inserted['errored_articles'] );
		$this->assertNull( $inserted['finished_at'] );
		$this->assertSame( '2026-05-09 10:00:00', $inserted['started_at'] );
		// applied_rules et affected_post_ids doivent etre serialises en JSON.
		$this->assertSame( '["P1","P5"]', $inserted['applied_rules'] );
		$this->assertSame( '[100,101,102]', $inserted['affected_post_ids'] );
	}

	public function test_insert_running_returns_false_on_failure(): void {
		$this->wpdb->insert_return = false;
		$id = $this->repo->insert_running( 'uuid', array(), array() );
		$this->assertFalse( $id );
	}

	public function test_finalize_updates_totals_and_finished_at(): void {
		$this->wpdb->update_return = 1;
		$ok = $this->repo->finalize(
			'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			10, 2, 1,
			'2026-05-09 10:30:00'
		);
		$this->assertTrue( $ok );
		$this->assertCount( 1, $this->wpdb->update_log );
		$update = $this->wpdb->update_log[0];
		$this->assertSame( $this->table, $update['table'] );
		$this->assertSame( 10, $update['data']['successful_articles'] );
		$this->assertSame( 2, $update['data']['refused_articles'] );
		$this->assertSame( 1, $update['data']['errored_articles'] );
		$this->assertSame( '2026-05-09 10:30:00', $update['data']['finished_at'] );
		$this->assertSame( array( 'step_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ), $update['where'] );
	}

	public function test_update_per_article_result_appends_to_existing(): void {
		// 1er get_row pour find_by_uuid : retourne le pas avec 1 resultat existant.
		$this->wpdb->get_row_queue[] = array(
			'id'                  => 1,
			'step_uuid'           => 'uuid',
			'applied_rules'       => '["P1"]',
			'affected_post_ids'   => '[100,101]',
			'total_articles'      => 2,
			'successful_articles' => 0,
			'refused_articles'    => 0,
			'errored_articles'    => 0,
			'per_article_results' => '{"100":{"status":"success"}}',
			'user_id'             => null,
			'started_at'          => '2026-05-09 10:00:00',
			'finished_at'         => null,
		);
		$this->wpdb->update_return = 1;
		$ok = $this->repo->update_per_article_result( 'uuid', 101, array( 'status' => 'refused', 'regression' => array( 'images' => 2 ) ) );
		$this->assertTrue( $ok );
		$this->assertCount( 1, $this->wpdb->update_log );
		$update = $this->wpdb->update_log[0];
		$encoded = $update['data']['per_article_results'];
		// Le json doit contenir les 2 entrées : 100 (succes existant) et 101 (refused nouveau).
		$this->assertStringContainsString( '"100":', $encoded );
		$this->assertStringContainsString( '"101":', $encoded );
		$this->assertStringContainsString( 'refused', $encoded );
	}

	public function test_update_per_article_result_returns_false_when_step_unknown(): void {
		// Aucune entrée dans la queue : find_by_uuid retournera null.
		$ok = $this->repo->update_per_article_result( 'unknown-uuid', 100, array( 'status' => 'success' ) );
		$this->assertFalse( $ok );
		$this->assertCount( 0, $this->wpdb->update_log );
	}

	public function test_find_unfinished_returns_decoded_records(): void {
		$this->wpdb->get_results_queue[] = array(
			array(
				'id'                  => 1,
				'step_uuid'           => 'unfinished-1',
				'applied_rules'       => '["P1"]',
				'affected_post_ids'   => '[100]',
				'total_articles'      => 1,
				'successful_articles' => 0,
				'refused_articles'    => 0,
				'errored_articles'    => 0,
				'per_article_results' => '{}',
				'user_id'             => null,
				'started_at'          => '2026-05-09 10:00:00',
				'finished_at'         => null,
			),
		);
		$records = $this->repo->find_unfinished();
		$this->assertCount( 1, $records );
		$this->assertFalse( $records[0]->is_finished() );
		// Le SQL doit filtrer sur finished_at IS NULL.
		$last_sql = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringContainsString( 'finished_at IS NULL', $last_sql );
	}

	public function test_count_total_returns_int(): void {
		$this->wpdb->get_var_queue[] = '42';
		$this->assertSame( 42, $this->repo->count_total() );
	}

	public function test_list_recent_orders_by_started_at_desc(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_recent( 10, 0 );
		$last_sql = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringContainsString( 'ORDER BY started_at DESC', $last_sql );
	}

	// =========================================================================
	//  list_filtered / count_filtered (Phase 5.2 — REST historique avec from/to)
	// =========================================================================

	public function test_list_filtered_without_bounds_omits_where_clause(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_filtered( null, null, 10, 0 );
		$last_sql = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringNotContainsString( 'WHERE', $last_sql );
		$this->assertStringContainsString( 'ORDER BY started_at DESC', $last_sql );
	}

	public function test_list_filtered_applies_from_bound(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_filtered( '2026-05-01 00:00:00', null, 10, 0 );
		$last_sql = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringContainsString( 'started_at >= ', $last_sql );
		$this->assertStringContainsString( '2026-05-01 00:00:00', $last_sql );
	}

	public function test_list_filtered_applies_to_bound(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_filtered( null, '2026-05-31 23:59:59', 10, 0 );
		$last_sql = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringContainsString( 'started_at <= ', $last_sql );
		$this->assertStringContainsString( '2026-05-31 23:59:59', $last_sql );
	}

	public function test_list_filtered_applies_both_bounds_with_AND(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_filtered( '2026-05-01 00:00:00', '2026-05-31 23:59:59', 10, 0 );
		$last_sql = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringContainsString( 'started_at >= ', $last_sql );
		$this->assertStringContainsString( ' AND ', $last_sql );
		$this->assertStringContainsString( 'started_at <= ', $last_sql );
	}

	public function test_list_filtered_treats_empty_string_as_null(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_filtered( '', '', 10, 0 );
		$last_sql = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringNotContainsString( 'WHERE', $last_sql );
	}

	public function test_count_filtered_without_bounds_returns_total(): void {
		$this->wpdb->get_var_queue[] = '17';
		$this->assertSame( 17, $this->repo->count_filtered( null, null ) );
	}

	public function test_count_filtered_with_bounds_includes_where(): void {
		$this->wpdb->get_var_queue[] = '5';
		$this->repo->count_filtered( '2026-05-01 00:00:00', '2026-05-31 23:59:59' );
		$last_sql = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringContainsString( 'COUNT(*)', $last_sql );
		$this->assertStringContainsString( 'started_at >= ', $last_sql );
		$this->assertStringContainsString( 'started_at <= ', $last_sql );
	}
}
