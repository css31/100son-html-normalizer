<?php
/**
 * Tests DiagnosticEngine — Phase 3.2 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Diagnostics;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\BuilderScopedRule;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class DiagnosticEngineTest extends TestCase {

	/**
	 * Crée une fake-rule retournant un countMatches() arbitraire.
	 *
	 * @param string $id     Identifiant rule.
	 * @param int    $count  Nombre de matches simulé.
	 * @return RuleInterface
	 */
	private function fake_rule( string $id, int $count ): RuleInterface {
		return new class( $id, $count ) implements RuleInterface {
			public function __construct( private string $rule_id, private int $matches ) {}
			public function id(): string { return $this->rule_id; }
			public function label(): string { return $this->rule_id; }
			public function apply( string $html, array $context = [] ): string { return $html; }
			public function countMatches( string $html, array $context = [] ): int { return $this->matches; }
		};
	}

	/**
	 * Construit un PresetRegistry stubbé renvoyant une liste fixe de règles.
	 *
	 * @param list<RuleInterface> $rules Règles à exposer.
	 */
	private function registry_with( array $rules ): PresetRegistry {
		return new class( $rules ) extends PresetRegistry {
			/** @param list<RuleInterface> $rules */
			public function __construct( private array $rules ) {
				// Ne pas appeler parent::__construct (besoin d'un SettingsRepository).
				parent::__construct( new SettingsRepository() );
			}
			public function get_enabled_rules(): array {
				return $this->rules;
			}
		};
	}

	/**
	 * Construit un WP_Post stub.
	 *
	 * @param int    $id      Identifiant.
	 * @param string $content Contenu.
	 * @param string $modified Datetime post_modified.
	 */
	private function post( int $id, string $content, string $modified = '' ): WP_Post {
		$p                = new WP_Post();
		$p->ID            = $id;
		$p->post_content  = $content;
		$p->post_modified = $modified;
		return $p;
	}

	public function test_clean_post_returns_normal_status(): void {
		// 2 règles qui ne matchent rien : status normal.
		$registry = $this->registry_with( array(
			$this->fake_rule( 'R1', 0 ),
			$this->fake_rule( 'R5', 0 ),
		) );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 42, '<p>Texte propre</p>' ) );

		$this->assertSame( 42, $record->post_id );
		$this->assertSame( DiagnosticRecord::STATUS_NORMAL, $record->status );
		$this->assertSame( array(), $record->matching_rules );
	}

	public function test_post_with_matches_returns_to_improve_status(): void {
		$registry = $this->registry_with( array(
			$this->fake_rule( 'R1', 3 ),
			$this->fake_rule( 'R5', 0 ),
			$this->fake_rule( 'R7', 2 ),
		) );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 42, '<p>x</p>' ) );

		$this->assertSame( DiagnosticRecord::STATUS_TO_IMPROVE, $record->status );
		$this->assertCount( 2, $record->matching_rules, 'R5 (count=0) ne doit pas etre liste' );
		$this->assertSame( 'R1', $record->matching_rules[0]['rule_id'] );
		$this->assertSame( 3, $record->matching_rules[0]['occurrences'] );
		$this->assertSame( 'R7', $record->matching_rules[1]['rule_id'] );
		$this->assertSame( 2, $record->matching_rules[1]['occurrences'] );
	}

	public function test_diagnose_includes_metrics_snapshot(): void {
		$registry = $this->registry_with( array() );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 1, '<h2>Titre</h2><p>Bonjour le monde</p>' ) );

		// metrics est un array (cohérent avec MetricsSnapshot::toArray()).
		$this->assertArrayHasKey( 'chars', $record->metrics );
		$this->assertArrayHasKey( 'paragraphs', $record->metrics );
		$this->assertArrayHasKey( 'headings', $record->metrics );
		$this->assertSame( 1, $record->metrics['paragraphs'] );
		$this->assertSame( 1, $record->metrics['headings']['h2'] );
	}

	public function test_diagnose_sets_is_stale_to_false(): void {
		$registry = $this->registry_with( array() );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 1, '<p>x</p>' ) );
		$this->assertFalse( $record->is_stale );
	}

	public function test_diagnose_writes_diagnosed_at_in_mysql_format(): void {
		$registry = $this->registry_with( array() );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 1, '' ) );
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$record->diagnosed_at
		);
	}

	public function test_diagnose_snapshots_post_modified_at_diagnosis(): void {
		$registry = $this->registry_with( array() );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 1, '', '2026-05-09 12:30:45' ) );
		$this->assertSame( '2026-05-09 12:30:45', $record->post_modified_at_diagnosis );
	}

	public function test_diagnose_post_modified_empty_falls_back_to_null(): void {
		$registry = $this->registry_with( array() );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 1, '' ) );
		$this->assertNull( $record->post_modified_at_diagnosis );
	}

	public function test_diagnose_record_id_is_null_pre_persist(): void {
		// Le record sortant n'a pas d'id (pas encore persisté).
		$registry = $this->registry_with( array( $this->fake_rule( 'R1', 1 ) ) );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 1, '<p>x</p>' ) );
		$this->assertNull( $record->id );
	}

	public function test_diagnose_with_empty_html_does_not_crash(): void {
		$registry = $this->registry_with( array( $this->fake_rule( 'R1', 0 ) ) );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$record   = $engine->diagnose( $this->post( 1, '' ) );
		$this->assertSame( DiagnosticRecord::STATUS_NORMAL, $record->status );
		$this->assertSame( 0, $record->metrics['chars'] );
	}

	public function test_diagnose_skips_builder_scoped_rule_on_gutenberg(): void {
		// Règle qui claimerait 5 matches mais est exclue de TYPE_GUTENBERG.
		$scoped_rule = new class() implements RuleInterface, BuilderScopedRule {
			public int $count_calls = 0;
			public int $apply_calls = 0;
			public function id(): string { return 'R_SCOPED'; }
			public function label(): string { return 'R_SCOPED'; }
			public function apply( string $html, array $context = [] ): string {
				++$this->apply_calls;
				return $html;
			}
			public function countMatches( string $html, array $context = [] ): int {
				++$this->count_calls;
				return 5;
			}
			public function excluded_builder_types(): array {
				return array( BuilderClassifier::TYPE_GUTENBERG );
			}
		};

		$registry   = $this->registry_with( array( $scoped_rule ) );
		$classifier = new class() extends BuilderClassifier {
			public function classify( int $post_id ): string {
				return BuilderClassifier::TYPE_GUTENBERG;
			}
		};

		$engine = new DiagnosticEngine( $registry, new MetricsCalculator(), $classifier );
		$record = $engine->diagnose( $this->post( 99, '<p>x</p>' ) );

		$this->assertSame( 0, $scoped_rule->count_calls, 'countMatches ne doit pas etre appele sur builder exclu' );
		$this->assertSame( 0, $scoped_rule->apply_calls, 'apply ne doit pas etre appele sur builder exclu' );
		$this->assertSame( array(), $record->matching_rules );
		$this->assertSame( DiagnosticRecord::STATUS_NORMAL, $record->status );
		$this->assertSame( BuilderClassifier::TYPE_GUTENBERG, $record->builder_type );
	}

	public function test_diagnose_applies_builder_scoped_rule_on_non_excluded_type(): void {
		$scoped_rule = new class() implements RuleInterface, BuilderScopedRule {
			public function id(): string { return 'R_SCOPED'; }
			public function label(): string { return 'R_SCOPED'; }
			public function apply( string $html, array $context = [] ): string { return $html; }
			public function countMatches( string $html, array $context = [] ): int { return 5; }
			public function excluded_builder_types(): array {
				return array( BuilderClassifier::TYPE_GUTENBERG );
			}
		};

		$registry   = $this->registry_with( array( $scoped_rule ) );
		$classifier = new class() extends BuilderClassifier {
			public function classify( int $post_id ): string {
				return BuilderClassifier::TYPE_SITEORIGIN;
			}
		};

		$engine = new DiagnosticEngine( $registry, new MetricsCalculator(), $classifier );
		$record = $engine->diagnose( $this->post( 99, '<p>x</p>' ) );

		$this->assertSame( DiagnosticRecord::STATUS_TO_IMPROVE, $record->status );
		$this->assertSame( 'R_SCOPED', $record->matching_rules[0]['rule_id'] );
		$this->assertSame( 5, $record->matching_rules[0]['occurrences'] );
	}

	public function test_diagnose_propagates_builder_type_in_context(): void {
		$received_builder_type = null;
		$rule                  = new class( $received_builder_type ) implements RuleInterface {
			public function __construct( public ?string &$received_builder_type ) {}
			public function id(): string { return 'PX'; }
			public function label(): string { return 'PX'; }
			public function apply( string $html, array $context = [] ): string { return $html; }
			public function countMatches( string $html, array $context = [] ): int {
				$this->received_builder_type = $context['builder_type'] ?? null;
				return 0;
			}
		};
		$registry   = $this->registry_with( array( $rule ) );
		$classifier = new class() extends BuilderClassifier {
			public function classify( int $post_id ): string {
				return BuilderClassifier::TYPE_SITEORIGIN_FLAT;
			}
		};
		$engine = new DiagnosticEngine( $registry, new MetricsCalculator(), $classifier );
		$engine->diagnose( $this->post( 7, '<p>x</p>' ) );
		$this->assertSame( BuilderClassifier::TYPE_SITEORIGIN_FLAT, $received_builder_type );
	}

	public function test_diagnose_passes_post_content_to_rules(): void {
		// Vérifie que `countMatches` reçoit bien le post_content.
		$received_html = null;
		$rule          = new class( $received_html ) implements RuleInterface {
			public function __construct( public ?string &$received_html ) {}
			public function id(): string { return 'PX'; }
			public function label(): string { return 'PX'; }
			public function apply( string $html, array $context = [] ): string { return $html; }
			public function countMatches( string $html, array $context = [] ): int {
				$this->received_html = $html;
				return 0;
			}
		};
		$registry = $this->registry_with( array( $rule ) );
		$engine   = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$engine->diagnose( $this->post( 1, '<p>SPECIFIC_CONTENT</p>' ) );
		$this->assertSame( '<p>SPECIFIC_CONTENT</p>', $received_html );
	}
}
