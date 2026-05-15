<?php
/**
 * Tests Pipeline — applySubset() (Phase 1 V1.0).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Core;

use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use PHPUnit\Framework\TestCase;

/**
 * Couverture de Pipeline::applySubset().
 *
 * On utilise des regles "fake" qui taguent leur passage dans le HTML, ce qui
 * permet de verifier 1) qu'elles ont ete appelees, 2) dans quel ordre.
 */
final class PipelineTest extends TestCase {

	private Pipeline $pipeline;

	/** @var list<RuleInterface> */
	private array $all_rules;

	protected function setUp(): void {
		$this->pipeline = new Pipeline();

		$make_rule = static function ( string $id ): RuleInterface {
			return new class( $id ) implements RuleInterface {
				public function __construct( private string $rule_id ) {}
				public function id(): string {
					return $this->rule_id; }
				public function label(): string {
					return $this->rule_id; }
				public function apply( string $html, array $context = array() ): string {
					return $html . '|' . $this->rule_id;
				}
				public function countMatches( string $html, array $context = array() ): int {
					return 0;
				}
			};
		};

		// Ordre canonique simule (sous-ensemble suffit pour ces tests).
		$this->all_rules = array(
			$make_rule( 'R3' ),
			$make_rule( 'R4' ),
			$make_rule( 'R8' ),
			$make_rule( 'R5' ),
			$make_rule( 'R1' ),
		);
	}

	public function test_apply_subset_empty_rule_ids_is_noop(): void {
		$out = $this->pipeline->applySubset( $this->all_rules, array(), 'INPUT' );
		$this->assertSame( 'INPUT', $out );
	}

	public function test_apply_subset_unknown_rule_ids_is_noop(): void {
		$out = $this->pipeline->applySubset( $this->all_rules, array( 'R99', 'RXY' ), 'INPUT' );
		$this->assertSame( 'INPUT', $out );
	}

	public function test_apply_subset_filters_to_requested_only(): void {
		$out = $this->pipeline->applySubset( $this->all_rules, array( 'R5' ), 'X' );
		$this->assertSame( 'X|R5', $out );
	}

	public function test_apply_subset_respects_order_of_all_rules(): void {
		// Demande dans le desordre R1, R5, R3 : doit s'executer dans l'ordre
		// canonique fourni par $all_rules : R3, R5, R1.
		$out = $this->pipeline->applySubset( $this->all_rules, array( 'R1', 'R5', 'R3' ), 'X' );
		$this->assertSame( 'X|R3|R5|R1', $out );
	}

	public function test_apply_subset_ignores_unknown_among_known(): void {
		$out = $this->pipeline->applySubset( $this->all_rules, array( 'R5', 'R99', 'R1' ), 'X' );
		$this->assertSame( 'X|R5|R1', $out );
	}

	public function test_apply_subset_collects_warnings_from_throwing_rule(): void {
		$throwing_rule = new class() implements RuleInterface {
			public function id(): string {
				return 'P_BOOM'; }
			public function label(): string {
				return 'P_BOOM'; }
			public function apply( string $html, array $context = array() ): string {
				throw new \RuntimeException( 'kaboom' );
			}
			public function countMatches( string $html, array $context = array() ): int {
				return 0; }
		};
		$rules    = array_merge( $this->all_rules, array( $throwing_rule ) );
		$warnings = array();
		$out      = $this->pipeline->applySubset( $rules, array( 'R5', 'P_BOOM' ), 'X', array(), $warnings );
		// La regle qui throw n'arrete pas la pipeline, R5 doit avoir taggue.
		$this->assertSame( 'X|R5', $out );
		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'P_BOOM', $warnings[0] );
		$this->assertStringContainsString( 'kaboom', $warnings[0] );
	}
}
