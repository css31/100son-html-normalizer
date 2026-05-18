<?php
/**
 * Tests RuleCoverageService — couverture historique des règles.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Steps;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\BuilderScopedRule;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Steps\ArticleResult;
use Cent_Son\Html_Normalizer\Steps\RuleCoverageService;
use Cent_Son\Html_Normalizer\Steps\StepRecord;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Wpdb;

final class RuleCoverageServiceTest extends TestCase {

	/**
	 * @param list<StepRecord>                  $steps
	 * @param array<int, string>                $corpus      Map post_id → builder_type.
	 * @param array<string, array<int, true>>   $pending     Map rule_id → set<post_id> ayant la
	 *                                                       règle dans matching_rules (par défaut
	 *                                                       aucun pending → couverture full dès
	 *                                                       qu'un touched existe).
	 */
	private function make_service(
		array $steps,
		array $corpus,
		array $rule_factories = array(),
		array $pending = array()
	): RuleCoverageService {
		$wpdb = new Son100_Htmln_Test_Wpdb();

		$steps_repo = new class( $wpdb, $steps ) extends StepsRepository {
			/** @var list<StepRecord> */
			private array $steps_in_memory;
			public function __construct( object $wpdb, array $steps ) {
				parent::__construct( $wpdb );
				$this->steps_in_memory = $steps;
			}
			public function list_all_finished(): array {
				return $this->steps_in_memory;
			}
		};

		$diag_repo = new class( $wpdb, $corpus, $pending ) extends DiagnosticsRepository {
			/** @var array<int, string> */
			private array $corpus;
			/** @var array<string, array<int, true>> */
			private array $pending;
			public function __construct( object $wpdb, array $corpus, array $pending ) {
				parent::__construct( $wpdb );
				$this->corpus  = $corpus;
				$this->pending = $pending;
			}
			public function list_post_id_by_builder_type(): array {
				return $this->corpus;
			}
			public function list_post_ids_by_applicable_rule(): array {
				$out = array();
				foreach ( PresetRegistry::PRESETS as $rule_id ) {
					$out[ $rule_id ] = $this->pending[ $rule_id ] ?? array();
				}
				return $out;
			}
		};

		$registry = new class( $rule_factories ) extends PresetRegistry {
			/** @var array<string, callable():RuleInterface> */
			private array $factories;
			public function __construct( array $factories ) {
				parent::__construct( new SettingsRepository() );
				$this->factories = $factories;
			}
			public function build_rule( string $id ): ?RuleInterface {
				if ( ! isset( $this->factories[ $id ] ) ) {
					return null;
				}
				return ( $this->factories[ $id ] )();
			}
		};

		return new RuleCoverageService( $steps_repo, $diag_repo, $registry );
	}

	private function make_step(
		array $applied_rules,
		array $per_article_results
	): StepRecord {
		return new StepRecord(
			id: 1,
			step_uuid: 'uuid-test',
			applied_rules: $applied_rules,
			affected_post_ids: array_keys( $per_article_results ),
			total_articles: count( $per_article_results ),
			successful_articles: 0,
			refused_articles: 0,
			errored_articles: 0,
			pending_articles: 0,
			per_article_results: $per_article_results,
			user_id: null,
			started_at: '2026-05-18 10:00:00',
			finished_at: '2026-05-18 10:05:00',
		);
	}

	private function success( int $revision_id = 100 ): array {
		return array(
			'status'      => ArticleResult::STATUS_SUCCESS,
			'revision_id' => $revision_id,
		);
	}

	public function test_no_step_means_every_rule_is_none(): void {
		$service = $this->make_service(
			array(),
			array( 100 => BuilderClassifier::TYPE_SITEORIGIN ),
		);
		$result = $service->compute();
		// 17 règles toutes en `none`.
		$this->assertCount( count( PresetRegistry::PRESETS ), $result );
		foreach ( PresetRegistry::PRESETS as $rule_id ) {
			$this->assertSame(
				RuleCoverageService::STATUS_NONE,
				$result[ $rule_id ],
				"Règle {$rule_id} sans step doit être 'none'"
			);
		}
	}

	public function test_full_when_all_eligible_posts_are_success(): void {
		$step = $this->make_step(
			array( 'R3', 'R4' ),
			array(
				100 => $this->success(),
				101 => $this->success(),
				102 => $this->success(),
			),
		);
		$service = $this->make_service(
			array( $step ),
			array(
				100 => BuilderClassifier::TYPE_SITEORIGIN,
				101 => BuilderClassifier::TYPE_OTHER,
				102 => BuilderClassifier::TYPE_SITEORIGIN_FLAT,
			),
		);
		$result = $service->compute();
		$this->assertSame( RuleCoverageService::STATUS_FULL, $result['R3'] );
		$this->assertSame( RuleCoverageService::STATUS_FULL, $result['R4'] );
		// R1 n'a été appliquée à aucun article → 'none'.
		$this->assertSame( RuleCoverageService::STATUS_NONE, $result['R1'] );
	}

	public function test_partial_when_a_non_touched_post_still_has_rule_in_matching(): void {
		// 100 success en R1 ; 101 et 102 non touchés mais 101 a encore R1
		// dans matching_rules → partial. 102 n'a pas R1 dans matching_rules :
		// il ne pèse pas dans le verdict.
		$step = $this->make_step(
			array( 'R1' ),
			array( 100 => $this->success() ),
		);
		$service = $this->make_service(
			array( $step ),
			array(
				100 => BuilderClassifier::TYPE_SITEORIGIN,
				101 => BuilderClassifier::TYPE_SITEORIGIN,
				102 => BuilderClassifier::TYPE_SITEORIGIN,
			),
			array(),
			array(
				'R1' => array( 101 => true ),
			),
		);
		$result = $service->compute();
		$this->assertSame( RuleCoverageService::STATUS_PARTIAL, $result['R1'] );
	}

	public function test_full_when_non_touched_posts_have_no_pending_match(): void {
		// 100 success en R3 ; 101 et 102 non touchés MAIS n'ont pas R3
		// dans leur matching_rules → la règle est "appliquée à tout le
		// corpus" pour son périmètre métier → full.
		$step = $this->make_step(
			array( 'R3' ),
			array( 100 => $this->success() ),
		);
		$service = $this->make_service(
			array( $step ),
			array(
				100 => BuilderClassifier::TYPE_SITEORIGIN,
				101 => BuilderClassifier::TYPE_SITEORIGIN,
				102 => BuilderClassifier::TYPE_GUTENBERG,
			),
			array(),
			array() // Aucun pending sur R3 → rien à faire ailleurs.
		);
		$result = $service->compute();
		$this->assertSame( RuleCoverageService::STATUS_FULL, $result['R3'] );
	}

	public function test_out_posts_are_excluded_from_eligible(): void {
		// 100 success en R3, 101 en 'out' (donc hors périmètre) →
		// le périmètre éligible = {100}, full atteint.
		$step = $this->make_step(
			array( 'R3' ),
			array( 100 => $this->success() ),
		);
		$service = $this->make_service(
			array( $step ),
			array(
				100 => BuilderClassifier::TYPE_SITEORIGIN,
				101 => BuilderClassifier::TYPE_OUT,
			),
		);
		$result = $service->compute();
		$this->assertSame( RuleCoverageService::STATUS_FULL, $result['R3'] );
	}

	public function test_builder_scoped_rule_excludes_gutenberg_from_eligible(): void {
		// Règle factice « R_SCOPED » excluant TYPE_GUTENBERG.
		// 100 (SO) → success ; 101 (Gutenberg) → hors périmètre.
		// Couverture du périmètre éligible = {100} → full.
		$rule_factories = array(
			'R3' => fn(): RuleInterface => new class() implements RuleInterface, BuilderScopedRule {
				public function id(): string {
					return 'R3'; }
				public function label(): string {
					return 'R3'; }
				public function apply( string $html, array $context = array() ): string {
					return $html; }
				public function countMatches( string $html, array $context = array() ): int {
					return 0; }
				public function excluded_builder_types(): array {
					return array( BuilderClassifier::TYPE_GUTENBERG );
				}
			},
		);
		$step = $this->make_step(
			array( 'R3' ),
			array( 100 => $this->success() ),
		);
		$service = $this->make_service(
			array( $step ),
			array(
				100 => BuilderClassifier::TYPE_SITEORIGIN,
				101 => BuilderClassifier::TYPE_GUTENBERG,
			),
			$rule_factories,
		);
		$result = $service->compute();
		$this->assertSame( RuleCoverageService::STATUS_FULL, $result['R3'] );
	}

	public function test_non_success_statuses_do_not_count(): void {
		// 100 refused, 101 error, 102 regression_pending : aucun success
		// dans le step → R3 reste 'none'.
		$step = $this->make_step(
			array( 'R3' ),
			array(
				100 => array( 'status' => ArticleResult::STATUS_REFUSED ),
				101 => array( 'status' => ArticleResult::STATUS_ERROR ),
				102 => array( 'status' => ArticleResult::STATUS_REGRESSION_PENDING ),
			),
		);
		$service = $this->make_service(
			array( $step ),
			array(
				100 => BuilderClassifier::TYPE_SITEORIGIN,
				101 => BuilderClassifier::TYPE_SITEORIGIN,
				102 => BuilderClassifier::TYPE_SITEORIGIN,
			),
		);
		$result = $service->compute();
		$this->assertSame( RuleCoverageService::STATUS_NONE, $result['R3'] );
	}

	public function test_empty_corpus_returns_none_everywhere(): void {
		$step = $this->make_step(
			array( 'R3' ),
			array( 100 => $this->success() ),
		);
		$service = $this->make_service( array( $step ), array() );
		$result  = $service->compute();
		$this->assertSame( RuleCoverageService::STATUS_NONE, $result['R3'] );
	}

	public function test_one_step_with_multiple_rules_credits_each(): void {
		// Un step appliquant R3 + R4 sur 100 (sur 100) → les deux 'full'.
		$step = $this->make_step(
			array( 'R3', 'R4' ),
			array( 100 => $this->success() ),
		);
		$service = $this->make_service(
			array( $step ),
			array( 100 => BuilderClassifier::TYPE_SITEORIGIN ),
		);
		$result = $service->compute();
		$this->assertSame( RuleCoverageService::STATUS_FULL, $result['R3'] );
		$this->assertSame( RuleCoverageService::STATUS_FULL, $result['R4'] );
		$this->assertSame( RuleCoverageService::STATUS_NONE, $result['R1'] );
	}
}
