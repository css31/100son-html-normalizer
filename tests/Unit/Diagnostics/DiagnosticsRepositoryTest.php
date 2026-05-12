<?php
/**
 * Tests DiagnosticsRepository — Phase 2.2 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Diagnostics;

use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Wpdb;

final class DiagnosticsRepositoryTest extends TestCase {

	private Son100_Htmln_Test_Wpdb $wpdb;
	private DiagnosticsRepository $repo;
	private string $table;

	protected function setUp(): void {
		$this->wpdb  = new Son100_Htmln_Test_Wpdb();
		$this->table = $this->wpdb->prefix . 'son100_htmln_diagnostics';
		$this->repo  = new DiagnosticsRepository( $this->wpdb );
	}

	public function test_find_by_post_id_returns_null_when_absent(): void {
		$this->assertNull( $this->repo->find_by_post_id( 1234 ) );
	}

	public function test_find_by_post_id_decodes_db_row(): void {
		$this->wpdb->get_row_queue[] = array(
			'id'                         => '7',
			'post_id'                    => '1234',
			'status'                     => 'to_improve',
			'matching_rules'             => '[{"rule_id":"P1","occurrences":3},{"rule_id":"P5","occurrences":2}]',
			'metrics'                    => '{"chars":1024,"words":150,"paragraphs":12}',
			'is_stale'                   => '0',
			'diagnosed_at'               => '2026-05-09 12:34:56',
			'post_modified_at_diagnosis' => '2026-05-08 09:00:00',
		);
		$record = $this->repo->find_by_post_id( 1234 );
		$this->assertInstanceOf( DiagnosticRecord::class, $record );
		$this->assertSame( 7, $record->id );
		$this->assertSame( 1234, $record->post_id );
		$this->assertSame( 'to_improve', $record->status );
		$this->assertCount( 2, $record->matching_rules );
		$this->assertSame( 'P1', $record->matching_rules[0]['rule_id'] );
		$this->assertSame( 3, $record->matching_rules[0]['occurrences'] );
		$this->assertSame( 1024, $record->metrics['chars'] );
		$this->assertFalse( $record->is_stale );
	}

	public function test_upsert_inserts_when_absent(): void {
		// 1er appel get_row pour find_by_post_id : null (absent).
		$this->wpdb->get_row_queue[] = null;

		$record = new DiagnosticRecord(
			id: null,
			post_id: 42,
			status: DiagnosticRecord::STATUS_NORMAL,
			matching_rules: array(),
			metrics: array( 'chars' => 100 ),
			is_stale: false,
			diagnosed_at: '2026-05-09 12:00:00',
			post_modified_at_diagnosis: null,
		);
		$ok = $this->repo->upsert( $record );
		$this->assertTrue( $ok );
		$this->assertCount( 1, $this->wpdb->insert_log );
		$this->assertSame( $this->table, $this->wpdb->insert_log[0]['table'] );
		$this->assertSame( 42, $this->wpdb->insert_log[0]['data']['post_id'] );
		$this->assertSame( 'normal', $this->wpdb->insert_log[0]['data']['status'] );
		$this->assertArrayNotHasKey( 'id', $this->wpdb->insert_log[0]['data'], 'Upsert ne doit pas envoyer la colonne id' );
	}

	public function test_upsert_updates_when_present(): void {
		// 1er appel get_row pour find_by_post_id : ligne existante.
		$this->wpdb->get_row_queue[] = array(
			'id'                         => 5,
			'post_id'                    => 42,
			'status'                     => 'normal',
			'matching_rules'             => '[]',
			'metrics'                    => '{}',
			'is_stale'                   => 0,
			'diagnosed_at'               => '2026-05-08 00:00:00',
			'post_modified_at_diagnosis' => null,
		);
		$record = new DiagnosticRecord(
			id: null,
			post_id: 42,
			status: DiagnosticRecord::STATUS_TO_IMPROVE,
			matching_rules: array( array( 'rule_id' => 'P1', 'occurrences' => 1 ) ),
			metrics: array( 'chars' => 999 ),
			is_stale: false,
			diagnosed_at: '2026-05-09 12:00:00',
			post_modified_at_diagnosis: null,
		);
		$ok = $this->repo->upsert( $record );
		$this->assertTrue( $ok );
		$this->assertCount( 0, $this->wpdb->insert_log, 'Upsert ne doit pas inserer si ligne existe' );
		$this->assertCount( 1, $this->wpdb->update_log );
		$this->assertSame( $this->table, $this->wpdb->update_log[0]['table'] );
		$this->assertSame( array( 'post_id' => 42 ), $this->wpdb->update_log[0]['where'] );
		$this->assertSame( 'to_improve', $this->wpdb->update_log[0]['data']['status'] );
	}

	public function test_mark_stale_for_post_calls_update(): void {
		$this->wpdb->update_return = 1;
		$this->assertTrue( $this->repo->mark_stale_for_post( 42 ) );
		$this->assertCount( 1, $this->wpdb->update_log );
		$this->assertSame( array( 'is_stale' => 1 ), $this->wpdb->update_log[0]['data'] );
		$this->assertSame( array( 'post_id' => 42 ), $this->wpdb->update_log[0]['where'] );
	}

	public function test_mark_stale_returns_false_when_no_row_changed(): void {
		$this->wpdb->update_return = 0;
		$this->assertFalse( $this->repo->mark_stale_for_post( 9999 ) );
	}

	public function test_delete_for_post_uses_prepared_query(): void {
		$this->wpdb->query_return = 1;
		$this->assertTrue( $this->repo->delete_for_post( 42 ) );
		$this->assertNotEmpty( $this->wpdb->query_log );
		$last = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringContainsString( 'DELETE FROM', $last );
		$this->assertStringContainsString( '42', $last );
	}

	public function test_list_by_status_returns_decoded_records(): void {
		$this->wpdb->get_results_queue[] = array(
			array(
				'id'             => 1,
				'post_id'        => 100,
				'status'         => 'to_improve',
				'matching_rules' => '[]',
				'metrics'        => '{}',
				'is_stale'       => 0,
				'diagnosed_at'   => '2026-05-09 10:00:00',
			),
			array(
				'id'             => 2,
				'post_id'        => 101,
				'status'         => 'to_improve',
				'matching_rules' => '[]',
				'metrics'        => '{}',
				'is_stale'       => 0,
				'diagnosed_at'   => '2026-05-09 09:00:00',
			),
		);
		$records = $this->repo->list_by_status( 'to_improve', 50, 0 );
		$this->assertCount( 2, $records );
		$this->assertSame( 100, $records[0]->post_id );
		$this->assertSame( 101, $records[1]->post_id );
	}

	// =========================================================================
	//  list_paginated / count_paginated (Phase 5.3 — REST F13 unifié)
	// =========================================================================

	public function test_list_paginated_with_null_status_omits_where(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_paginated( null, 10, 0 );
		$last_sql = end( $this->wpdb->query_log );
		$this->assertStringNotContainsString( 'WHERE', $last_sql );
		// Post-rc3 : la requête utilise désormais l'alias `d` (cf.
		// `build_filter_clauses` qui partage le pattern entre list/count).
		$this->assertStringContainsString( 'ORDER BY d.diagnosed_at DESC', $last_sql );
	}

	public function test_list_paginated_with_normal_status_excludes_stale(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_paginated( 'normal', 10, 0 );
		$last_sql = end( $this->wpdb->query_log );
		$this->assertStringContainsString( "d.status = 'normal'", $last_sql );
		$this->assertStringContainsString( 'd.is_stale = 0', $last_sql );
	}

	public function test_list_paginated_with_to_improve_status(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_paginated( 'to_improve', 10, 0 );
		$last_sql = end( $this->wpdb->query_log );
		$this->assertStringContainsString( "d.status = 'to_improve'", $last_sql );
		$this->assertStringContainsString( 'd.is_stale = 0', $last_sql );
	}

	public function test_list_paginated_with_stale_status_only_filters_stale_flag(): void {
		$this->wpdb->get_results_queue[] = array();
		$this->repo->list_paginated( 'stale', 10, 0 );
		$last_sql = end( $this->wpdb->query_log );
		$this->assertStringContainsString( 'd.is_stale = 1', $last_sql );
		$this->assertStringNotContainsString( "d.status = 'normal'", $last_sql );
	}

	public function test_list_paginated_with_unknown_status_returns_empty_without_query(): void {
		// Défense en profondeur : status inconnu → pas d'appel BDD du tout.
		$initial_count = count( $this->wpdb->query_log );
		$result        = $this->repo->list_paginated( 'bogus', 10, 0 );
		$this->assertSame( array(), $result );
		$this->assertCount( $initial_count, $this->wpdb->query_log, 'Aucune requête ne doit être envoyée pour un status inconnu' );
	}

	public function test_count_paginated_with_null_status_returns_total(): void {
		$this->wpdb->get_var_queue[] = '42';
		$this->assertSame( 42, $this->repo->count_paginated( null ) );
	}

	public function test_count_paginated_with_status_includes_where(): void {
		$this->wpdb->get_var_queue[] = '17';
		$this->repo->count_paginated( 'to_improve' );
		$last_sql = end( $this->wpdb->query_log );
		$this->assertStringContainsString( 'COUNT(*)', $last_sql );
		$this->assertStringContainsString( "d.status = 'to_improve'", $last_sql );
	}

	public function test_count_paginated_with_unknown_status_returns_zero_without_query(): void {
		$initial_count = count( $this->wpdb->query_log );
		$this->assertSame( 0, $this->repo->count_paginated( 'bogus' ) );
		$this->assertCount( $initial_count, $this->wpdb->query_log );
	}

	public function test_count_by_status_returns_four_counts(): void {
		$this->wpdb->get_var_queue[] = '12';   // normal
		$this->wpdb->get_var_queue[] = '34';   // to_improve
		$this->wpdb->get_var_queue[] = '5';    // stale
		$this->wpdb->get_var_queue[] = '55';   // total (Phase 5.3 — F13 stats)
		$counts = $this->repo->count_by_status();
		$this->assertSame(
			array( 'normal' => 12, 'to_improve' => 34, 'stale' => 5, 'total' => 55 ),
			$counts
		);
	}

	public function test_list_stale_filters_on_is_stale_flag(): void {
		$this->wpdb->get_results_queue[] = array(
			array(
				'id'           => 9,
				'post_id'      => 999,
				'status'       => 'to_improve',
				'is_stale'     => 1,
				'diagnosed_at' => '2026-05-01 00:00:00',
			),
		);
		$records = $this->repo->list_stale();
		$this->assertCount( 1, $records );
		$this->assertTrue( $records[0]->is_stale );
		// On verifie que le SQL emis filtre bien sur is_stale.
		$last = $this->wpdb->query_log[ count( $this->wpdb->query_log ) - 1 ];
		$this->assertStringContainsString( 'is_stale = 1', $last );
	}
}
