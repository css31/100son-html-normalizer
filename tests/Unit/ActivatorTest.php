<?php
/**
 * Tests Activator — Phase 2.1 V1.0.
 *
 * Smoke tests : on verifie que `Activator::activate()` produit les bons
 * effets observables (db_version bumpee, dbDelta() appele 2 fois avec les
 * 2 schemas attendus) sans toucher de vraie BDD.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit;

use Cent_Son\Html_Normalizer\Activator;
use PHPUnit\Framework\TestCase;

final class ActivatorTest extends TestCase {

	protected function setUp(): void {
		// Reset stubs WP (cf. tests/bootstrap.php).
		$GLOBALS['son100_htmln_options']     = [];
		$GLOBALS['son100_htmln_dbdelta_log'] = [];
		$GLOBALS['wpdb']                     = new \Son100_Htmln_Test_Wpdb();
	}

	public function test_db_version_constant_is_set(): void {
		// Cohérence : la constante doit être bumpée a chaque évolution de schema.
		$this->assertSame( '2.0.0', Activator::DB_VERSION );
	}

	public function test_activate_seeds_settings_option(): void {
		Activator::activate();
		$settings = $GLOBALS['son100_htmln_options']['son100_htmln_settings'] ?? null;
		$this->assertIsArray( $settings );
		$this->assertSame( [ 'post' ], $settings['f8_post_types_selection'] );
	}

	public function test_activate_seeds_presets_option(): void {
		Activator::activate();
		$presets = $GLOBALS['son100_htmln_options']['son100_htmln_presets'] ?? null;
		$this->assertIsArray( $presets );
		// Les 9 presets doivent etre presents et actives par defaut.
		foreach ( [ 'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9' ] as $id ) {
			$this->assertArrayHasKey( $id, $presets, "Preset $id manquant" );
			$this->assertTrue( $presets[ $id ]['enabled'] );
		}
	}

	public function test_activate_seeds_user_rules_option(): void {
		Activator::activate();
		$user_rules = $GLOBALS['son100_htmln_options']['son100_htmln_rules_user'] ?? null;
		$this->assertSame( [], $user_rules );
	}

	public function test_activate_persists_db_version(): void {
		Activator::activate();
		$this->assertSame(
			Activator::DB_VERSION,
			$GLOBALS['son100_htmln_options']['son100_htmln_db_version'] ?? null
		);
	}

	public function test_activate_calls_dbdelta_for_both_tables(): void {
		Activator::activate();
		$queries = $GLOBALS['son100_htmln_dbdelta_log'];
		$this->assertCount( 2, $queries );
	}

	public function test_activate_creates_diagnostics_table_with_correct_schema(): void {
		Activator::activate();
		$queries = $GLOBALS['son100_htmln_dbdelta_log'];
		$diag    = array_values( array_filter( $queries, static fn( $q ) => str_contains( $q, 'son100_htmln_diagnostics' ) ) );
		$this->assertCount( 1, $diag );
		// Champs cles attendus (cahier v2.0 §4.2).
		$this->assertStringContainsString( 'post_id BIGINT UNSIGNED NOT NULL', $diag[0] );
		$this->assertStringContainsString( 'status VARCHAR(20) NOT NULL', $diag[0] );
		$this->assertStringContainsString( 'is_stale TINYINT(1)', $diag[0] );
		$this->assertStringContainsString( 'UNIQUE KEY uniq_post_id (post_id)', $diag[0] );
		$this->assertStringContainsString( 'KEY idx_status', $diag[0] );
		$this->assertStringContainsString( 'KEY idx_stale', $diag[0] );
	}

	public function test_activate_creates_steps_table_with_correct_schema(): void {
		Activator::activate();
		$queries = $GLOBALS['son100_htmln_dbdelta_log'];
		$steps   = array_values( array_filter( $queries, static fn( $q ) => str_contains( $q, 'son100_htmln_steps' ) ) );
		$this->assertCount( 1, $steps );
		$this->assertStringContainsString( 'step_uuid VARCHAR(36) NOT NULL', $steps[0] );
		$this->assertStringContainsString( 'applied_rules LONGTEXT NOT NULL', $steps[0] );
		$this->assertStringContainsString( 'started_at DATETIME NOT NULL', $steps[0] );
		$this->assertStringContainsString( 'finished_at DATETIME NULL', $steps[0] );
		$this->assertStringContainsString( 'UNIQUE KEY uniq_step_uuid (step_uuid)', $steps[0] );
		$this->assertStringContainsString( 'KEY idx_started_at', $steps[0] );
	}

	public function test_activate_uses_wpdb_prefix_for_table_names(): void {
		Activator::activate();
		$queries = implode( "\n", $GLOBALS['son100_htmln_dbdelta_log'] );
		$this->assertStringContainsString( 'wptests_son100_htmln_diagnostics', $queries );
		$this->assertStringContainsString( 'wptests_son100_htmln_steps', $queries );
	}

	public function test_activate_includes_charset_collate(): void {
		Activator::activate();
		$queries = implode( "\n", $GLOBALS['son100_htmln_dbdelta_log'] );
		// Le stub renvoie un charset_collate fige : on verifie qu'il est colle aux SQL.
		$this->assertStringContainsString( 'CHARACTER SET utf8mb4', $queries );
	}

	public function test_activate_is_idempotent_for_options(): void {
		// 1ere activation : seed normal.
		Activator::activate();
		$first_settings = $GLOBALS['son100_htmln_options']['son100_htmln_settings'];

		// On modifie l'option entre les deux activations.
		update_option( 'son100_htmln_settings', [ 'f8_post_types_selection' => [ 'page' ] ] );

		// 2eme activation : ne doit PAS ecraser la modification utilisateur.
		Activator::activate();
		$this->assertSame(
			[ 'page' ],
			$GLOBALS['son100_htmln_options']['son100_htmln_settings']['f8_post_types_selection'],
			'Activator ne doit pas ecraser une option deja seedee.'
		);
	}
}
