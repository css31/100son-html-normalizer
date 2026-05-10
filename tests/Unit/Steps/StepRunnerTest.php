<?php
/**
 * Tests StepRunner — Phase 4.1 V1.0 (start_step + happy path + dry_run + regression_pending).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Steps;

use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Regression\RegressionDetector;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Steps\ArticleResult;
use Cent_Son\Html_Normalizer\Steps\StepRecord;
use Cent_Son\Html_Normalizer\Steps\StepRunner;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use Son100_Htmln_Test_Wpdb;
use WP_Post;

final class StepRunnerTest extends TestCase {

	private Son100_Htmln_Test_Wpdb $wpdb;

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$GLOBALS['son100_htmln_options'] = array();
		$this->wpdb                       = new Son100_Htmln_Test_Wpdb();
	}

	// =========================================================================
	//  Helpers — stubs in-memory pour les repos et le registry.
	// =========================================================================

	/**
	 * StepsRepository stub : stocke en mémoire les pas et leurs résultats.
	 * Évite de jongler avec `Son100_Htmln_Test_Wpdb::get_row_queue` à chaque
	 * call ; les assertions se font sur les attributs publics du stub.
	 */
	private function steps_stub(): StepsRepository {
		return new class( $this->wpdb ) extends StepsRepository {
			/** @var array<string, StepRecord> */
			public array $records = array();
			/** @var list<array{uuid: string, post_id: int, result: array<string, mixed>}> */
			public array $written_results = array();

			public function find_by_uuid( string $uuid ): ?StepRecord {
				return $this->records[ $uuid ] ?? null;
			}
			public function insert_running(
				string $uuid,
				array $applied_rules,
				array $affected_post_ids,
				?int $user_id = null,
				string $started_at = ''
			): int|false {
				$record                = new StepRecord(
					id: count( $this->records ) + 1,
					step_uuid: $uuid,
					applied_rules: $applied_rules,
					affected_post_ids: $affected_post_ids,
					total_articles: count( $affected_post_ids ),
					successful_articles: 0,
					refused_articles: 0,
					errored_articles: 0,
					per_article_results: array(),
					user_id: $user_id,
					started_at: '' !== $started_at ? $started_at : '2026-05-09 12:00:00',
					finished_at: null,
				);
				$this->records[ $uuid ] = $record;
				return $record->id;
			}
			public function update_per_article_result( string $uuid, int $post_id, array $result ): bool {
				$this->written_results[] = array(
					'uuid'    => $uuid,
					'post_id' => $post_id,
					'result'  => $result,
				);
				// Reconstruit le StepRecord avec les per_article_results à jour
				// (sinon `confirm_article` qui relit le pas ne voit pas le
				// `regression_pending` posé par `process_article`).
				$existing = $this->records[ $uuid ] ?? null;
				if ( null === $existing ) {
					return true;
				}
				$merged                 = $existing->per_article_results;
				$merged[ $post_id ]      = $result;
				$this->records[ $uuid ] = new StepRecord(
					id: $existing->id,
					step_uuid: $existing->step_uuid,
					applied_rules: $existing->applied_rules,
					affected_post_ids: $existing->affected_post_ids,
					total_articles: $existing->total_articles,
					successful_articles: $existing->successful_articles,
					refused_articles: $existing->refused_articles,
					errored_articles: $existing->errored_articles,
					per_article_results: $merged,
					user_id: $existing->user_id,
					started_at: $existing->started_at,
					finished_at: $existing->finished_at,
				);
				return true;
			}
		};
	}

	/**
	 * DiagnosticsRepository stub : capture chaque upsert.
	 */
	private function diagnostics_stub(): DiagnosticsRepository {
		return new class( $this->wpdb ) extends DiagnosticsRepository {
			/** @var list<DiagnosticRecord> */
			public array $upserted = array();
			public function upsert( DiagnosticRecord $record ): bool {
				$this->upserted[] = $record;
				return true;
			}
		};
	}

	/**
	 * PresetRegistry stub : expose une liste fixe de règles.
	 *
	 * @param list<RuleInterface> $rules Règles activées pour le test.
	 */
	private function registry_stub( array $rules ): PresetRegistry {
		return new class( $rules ) extends PresetRegistry {
			/** @param list<RuleInterface> $rules */
			public function __construct( private array $rules ) {
				parent::__construct( new SettingsRepository() );
			}
			public function get_enabled_rules(): array {
				return $this->rules;
			}
		};
	}

	/**
	 * Crée une fake-rule simple : applique une transformation déterministe.
	 *
	 * @param string   $id        Identifiant rule.
	 * @param callable $transform fn(string $html): string
	 */
	private function fake_rule( string $id, callable $transform ): RuleInterface {
		return new class( $id, $transform ) implements RuleInterface {
			public function __construct( private string $rule_id, private mixed $transform ) {}
			public function id(): string { return $this->rule_id; }
			public function label(): string { return $this->rule_id; }
			public function apply( string $html, array $context = array() ): string {
				return ( $this->transform )( $html );
			}
			public function countMatches( string $html, array $context = array() ): int { return 0; }
		};
	}

	/**
	 * Construit un StepRunner câblé avec les stubs fournis.
	 *
	 * @param StepsRepository       $steps       Steps stub.
	 * @param DiagnosticsRepository $diagnostics Diagnostics stub.
	 * @param list<RuleInterface>   $rules       Règles activées.
	 */
	private function make_runner(
		StepsRepository $steps,
		DiagnosticsRepository $diagnostics,
		array $rules
	): StepRunner {
		return new StepRunner(
			$steps,
			$diagnostics,
			$this->registry_stub( $rules ),
			new Pipeline(),
			new MetricsCalculator(),
			new RegressionDetector(),
			new DiagnosticEngine( $this->registry_stub( $rules ), new MetricsCalculator() ),
			new SettingsRepository(),
		);
	}

	private function seed_post( int $id, string $content ): void {
		$p                 = new WP_Post();
		$p->ID             = $id;
		$p->post_content   = $content;
		$p->post_type      = 'post';
		$p->post_status    = 'publish';
		$p->post_modified  = '2026-05-09 12:00:00';
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $p;
	}

	// =========================================================================
	//  start_step
	// =========================================================================

	public function test_start_step_generates_uuid_v4_and_returns_it(): void {
		$steps  = $this->steps_stub();
		$runner = $this->make_runner( $steps, $this->diagnostics_stub(), array() );

		$uuid = $runner->start_step( array( 100, 101 ), array( 'P1' ), 5 );

		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$uuid,
			'UUID v4 attendu (généré côté serveur cf. §13)'
		);
	}

	public function test_start_step_inserts_running_with_snapshot(): void {
		$steps  = $this->steps_stub();
		$runner = $this->make_runner( $steps, $this->diagnostics_stub(), array() );

		$uuid   = $runner->start_step( array( 100, 101, 102 ), array( 'P1', 'P5' ), 7 );
		$record = $steps->find_by_uuid( $uuid );

		$this->assertNotNull( $record );
		$this->assertSame( $uuid, $record->step_uuid );
		$this->assertSame( array( 'P1', 'P5' ), $record->applied_rules );
		$this->assertSame( array( 100, 101, 102 ), $record->affected_post_ids );
		$this->assertSame( 3, $record->total_articles );
		$this->assertSame( 7, $record->user_id );
		$this->assertNull( $record->finished_at );
		$this->assertFalse( $record->is_finished() );
	}

	public function test_start_step_with_null_user_id_is_accepted(): void {
		$steps  = $this->steps_stub();
		$runner = $this->make_runner( $steps, $this->diagnostics_stub(), array() );

		$uuid   = $runner->start_step( array( 100 ), array( 'P1' ), null );
		$record = $steps->find_by_uuid( $uuid );

		$this->assertNotNull( $record );
		$this->assertNull( $record->user_id, 'CLI invocations doivent autoriser user_id null' );
	}

	public function test_start_step_throws_when_insert_fails(): void {
		$failing = new class( $this->wpdb ) extends StepsRepository {
			public function insert_running(
				string $uuid,
				array $applied_rules,
				array $affected_post_ids,
				?int $user_id = null,
				string $started_at = ''
			): int|false {
				return false;
			}
		};
		$runner = $this->make_runner( $failing, $this->diagnostics_stub(), array() );

		$this->expectException( \RuntimeException::class );
		$runner->start_step( array( 100 ), array( 'P1' ), null );
	}

	// =========================================================================
	//  process_article — happy path
	// =========================================================================

	public function test_process_article_happy_path_writes_post_content(): void {
		$this->seed_post( 100, '<p>Hello</p>' );
		$rule   = $this->fake_rule(
			'P1',
			static fn( string $html ): string => str_replace( 'Hello', 'Bonjour', $html )
		);
		$steps  = $this->steps_stub();
		$diags  = $this->diagnostics_stub();
		$runner = $this->make_runner( $steps, $diags, array( $rule ) );

		$uuid   = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$result = $runner->process_article( $uuid, 100 );

		$this->assertSame( ArticleResult::STATUS_SUCCESS, $result->status );
		$this->assertSame(
			'<p>Bonjour</p>',
			Son100_Htmln_Test_Posts_Registry::$posts[100]->post_content,
			'post_content doit avoir été normalisé'
		);
		$this->assertSame(
			'<p>Bonjour</p>',
			Son100_Htmln_Test_Posts_Registry::$updates[100] ?? null,
			'wp_update_post a dû être appelé avec le HTML normalisé'
		);
	}

	public function test_process_article_creates_revision_before_write(): void {
		// Garde-fou §13 : wp_save_post_revision SYSTÉMATIQUEMENT avant tout write.
		$this->seed_post( 100, '<p>Hello</p>' );
		$rule   = $this->fake_rule( 'P1', static fn( string $h ): string => $h . ' ' );
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array( $rule ) );

		$uuid = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$runner->process_article( $uuid, 100 );

		$this->assertArrayHasKey(
			100,
			Son100_Htmln_Test_Posts_Registry::$revisions_created,
			'wp_save_post_revision doit avoir été appelé pour le post 100'
		);
	}

	public function test_process_article_recalculates_diagnostic_post_write(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$rule   = $this->fake_rule( 'P1', static fn( string $h ): string => $h );
		$diags  = $this->diagnostics_stub();
		$runner = $this->make_runner( $this->steps_stub(), $diags, array( $rule ) );

		$uuid = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$runner->process_article( $uuid, 100 );

		$this->assertCount( 1, $diags->upserted, 'Un upsert diagnostic attendu après écriture' );
		$this->assertSame( 100, $diags->upserted[0]->post_id );
		$this->assertFalse( $diags->upserted[0]->is_stale );
	}

	public function test_process_article_persists_success_in_per_article_results(): void {
		$this->seed_post( 100, '<p>Hello</p>' );
		$rule   = $this->fake_rule( 'P1', static fn( string $h ): string => $h );
		$steps  = $this->steps_stub();
		$runner = $this->make_runner( $steps, $this->diagnostics_stub(), array( $rule ) );

		$uuid = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$runner->process_article( $uuid, 100 );

		$this->assertCount( 1, $steps->written_results );
		$entry = $steps->written_results[0];
		$this->assertSame( $uuid, $entry['uuid'] );
		$this->assertSame( 100, $entry['post_id'] );
		$this->assertSame( 'success', $entry['result']['status'] );
		$this->assertArrayNotHasKey( 'regression', $entry['result'] );
		$this->assertArrayNotHasKey( 'error', $entry['result'] );
	}

	public function test_process_article_returns_metrics_before_and_after(): void {
		$this->seed_post( 100, '<p>Hello world</p>' );
		$rule   = $this->fake_rule(
			'P1',
			static fn( string $html ): string => $html . '<p>Extra</p>'
		);
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array( $rule ) );

		$uuid   = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$result = $runner->process_article( $uuid, 100 );

		$this->assertSame( 1, $result->metrics_before->paragraphs );
		$this->assertSame( 2, $result->metrics_after->paragraphs );
	}

	// =========================================================================
	//  process_article — dry_run
	// =========================================================================

	public function test_dry_run_does_not_write_post_content(): void {
		$this->seed_post( 100, '<p>Original</p>' );
		$rule   = $this->fake_rule(
			'P1',
			static fn( string $html ): string => str_replace( 'Original', 'Modified', $html )
		);
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array( $rule ) );

		$uuid   = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$result = $runner->process_article( $uuid, 100, dry_run: true );

		$this->assertSame( ArticleResult::STATUS_DRY_RUN, $result->status );
		$this->assertSame(
			'<p>Original</p>',
			Son100_Htmln_Test_Posts_Registry::$posts[100]->post_content,
			'post_content doit rester intact en dry_run'
		);
		$this->assertArrayNotHasKey(
			100,
			Son100_Htmln_Test_Posts_Registry::$updates,
			'wp_update_post ne doit PAS avoir été appelé en dry_run'
		);
	}

	public function test_dry_run_does_not_create_revision(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$rule   = $this->fake_rule( 'P1', static fn( string $h ): string => $h );
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array( $rule ) );

		$uuid = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$runner->process_article( $uuid, 100, dry_run: true );

		$this->assertArrayNotHasKey(
			100,
			Son100_Htmln_Test_Posts_Registry::$revisions_created,
			'wp_save_post_revision ne doit PAS être appelé en dry_run'
		);
	}

	public function test_dry_run_does_not_recalculate_diagnostic(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$rule   = $this->fake_rule( 'P1', static fn( string $h ): string => $h );
		$diags  = $this->diagnostics_stub();
		$runner = $this->make_runner( $this->steps_stub(), $diags, array( $rule ) );

		$uuid = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$runner->process_article( $uuid, 100, dry_run: true );

		$this->assertCount( 0, $diags->upserted, 'Aucun upsert diagnostic attendu en dry_run' );
	}

	public function test_dry_run_still_computes_metrics_before_and_after(): void {
		// L'intérêt du dry_run c'est de prévisualiser : metrics_after doit refléter ce
		// que l'écriture aurait produit, pour permettre l'affichage d'un diff dans la SPA.
		$this->seed_post( 100, '<p>One</p>' );
		$rule   = $this->fake_rule(
			'P1',
			static fn( string $html ): string => $html . '<p>Two</p><p>Three</p>'
		);
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array( $rule ) );

		$uuid   = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$result = $runner->process_article( $uuid, 100, dry_run: true );

		$this->assertSame( 1, $result->metrics_before->paragraphs );
		$this->assertSame( 3, $result->metrics_after->paragraphs );
	}

	public function test_dry_run_persists_dry_run_status_in_per_article_results(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$rule   = $this->fake_rule( 'P1', static fn( string $h ): string => $h );
		$steps  = $this->steps_stub();
		$runner = $this->make_runner( $steps, $this->diagnostics_stub(), array( $rule ) );

		$uuid = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$runner->process_article( $uuid, 100, dry_run: true );

		$this->assertCount( 1, $steps->written_results );
		$this->assertSame( 'dry_run', $steps->written_results[0]['result']['status'] );
	}

	// =========================================================================
	//  process_article — régression (sécurité 4.1, détaillé en 4.2)
	// =========================================================================

	public function test_regression_detected_blocks_write(): void {
		// Cf. §13 : RegressionDetector appelé SYSTÉMATIQUEMENT, jamais shortcircuit.
		// Une règle qui supprime tout le contenu déclenche perte 100% chars/words/paragraphs.
		$this->seed_post( 100, '<p>Texte qui sera entièrement effacé</p>' );
		$destructive = $this->fake_rule( 'P_DESTROY', static fn(): string => '' );
		$runner      = $this->make_runner(
			$this->steps_stub(),
			$this->diagnostics_stub(),
			array( $destructive )
		);

		$uuid   = $runner->start_step( array( 100 ), array( 'P_DESTROY' ), 1 );
		$result = $runner->process_article( $uuid, 100 );

		$this->assertSame( ArticleResult::STATUS_REGRESSION_PENDING, $result->status );
		$this->assertNotNull( $result->regression_report );
		$this->assertSame(
			'<p>Texte qui sera entièrement effacé</p>',
			Son100_Htmln_Test_Posts_Registry::$posts[100]->post_content,
			'post_content doit rester intact en cas de régression détectée'
		);
		$this->assertArrayNotHasKey( 100, Son100_Htmln_Test_Posts_Registry::$updates );
	}

	public function test_regression_persists_pending_status_with_report(): void {
		$this->seed_post( 100, '<p>Hello</p><p>World</p>' );
		$destructive = $this->fake_rule( 'P_DESTROY', static fn(): string => '' );
		$steps       = $this->steps_stub();
		$runner      = $this->make_runner(
			$steps,
			$this->diagnostics_stub(),
			array( $destructive )
		);

		$uuid = $runner->start_step( array( 100 ), array( 'P_DESTROY' ), 1 );
		$runner->process_article( $uuid, 100 );

		$this->assertCount( 1, $steps->written_results );
		$entry = $steps->written_results[0]['result'];
		$this->assertSame( 'regression_pending', $entry['status'] );
		$this->assertArrayHasKey( 'regression', $entry );
		$this->assertArrayHasKey( 'failures', $entry['regression'] );
		$this->assertNotEmpty( $entry['regression']['failures'] );
	}

	// =========================================================================
	//  process_article — erreurs
	// =========================================================================

	public function test_unknown_post_returns_error_result(): void {
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array() );

		$uuid   = $runner->start_step( array( 999 ), array( 'P1' ), 1 );
		$result = $runner->process_article( $uuid, 999 );

		$this->assertSame( ArticleResult::STATUS_ERROR, $result->status );
		$this->assertNotNull( $result->error );
		$this->assertStringContainsString( '999', $result->error );
	}

	public function test_unknown_step_uuid_returns_error_result(): void {
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array() );

		$result = $runner->process_article( 'not-a-real-uuid', 100 );

		$this->assertSame( ArticleResult::STATUS_ERROR, $result->status );
		$this->assertStringContainsString( 'not-a-real-uuid', (string) $result->error );
	}

	// =========================================================================
	//  process_article — robustesse (Phase 4.2)
	// =========================================================================

	public function test_rule_exception_does_not_break_step(): void {
		// Pipeline::run() catche les exceptions des règles individuelles (cf. §13).
		// Le StepRunner profite donc d'un Pipeline robuste : une règle qui throw
		// ne casse pas le pas, le HTML reste inchangé, et l'article est traité
		// en success. Le try/catch global de process_article reste utile en
		// défense en profondeur pour les exceptions imprévues hors-règle.
		$this->seed_post( 100, '<p>Original</p>' );
		$throwing = new class implements \Cent_Son\Html_Normalizer\Core\Rules\RuleInterface {
			public function id(): string { return 'P_THROW'; }
			public function label(): string { return 'P_THROW'; }
			public function apply( string $html, array $context = array() ): string {
				throw new \RuntimeException( 'rule exploded' );
			}
			public function countMatches( string $html, array $context = array() ): int { return 0; }
		};
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array( $throwing ) );

		$uuid   = $runner->start_step( array( 100 ), array( 'P_THROW' ), 1 );
		$result = $runner->process_article( $uuid, 100 );

		$this->assertSame(
			ArticleResult::STATUS_SUCCESS,
			$result->status,
			'Pipeline catche l\'exception ; HTML inchangé → success'
		);
		$this->assertSame(
			'<p>Original</p>',
			Son100_Htmln_Test_Posts_Registry::$posts[100]->post_content,
			'HTML inchangé puisque la règle a été skip'
		);
	}

	// =========================================================================
	//  confirm_article (Phase 4.2)
	// =========================================================================

	/**
	 * Helper : amène un article en `regression_pending` puis renvoie l'uuid.
	 *
	 * @param int  $post_id Article concerné.
	 * @param StepsRepository $steps  Stub réutilisé (la régression doit y être stockée).
	 * @param DiagnosticsRepository $diags  Stub réutilisé.
	 * @return array{uuid: string, runner: StepRunner, rule: RuleInterface}
	 */
	private function arrange_regression_pending(
		int $post_id,
		StepsRepository $steps,
		DiagnosticsRepository $diags
	): array {
		$this->seed_post( $post_id, '<p>Phrase à effacer</p>' );
		$destructive = $this->fake_rule( 'P_DESTROY', static fn(): string => '' );
		$runner      = $this->make_runner( $steps, $diags, array( $destructive ) );
		$uuid        = $runner->start_step( array( $post_id ), array( 'P_DESTROY' ), 1 );
		$pre         = $runner->process_article( $uuid, $post_id );
		$this->assertSame( ArticleResult::STATUS_REGRESSION_PENDING, $pre->status, 'précondition arrange' );
		return array( 'uuid' => $uuid, 'runner' => $runner, 'rule' => $destructive );
	}

	public function test_confirm_article_writes_post_content(): void {
		$steps  = $this->steps_stub();
		$diags  = $this->diagnostics_stub();
		$ctx    = $this->arrange_regression_pending( 100, $steps, $diags );
		$result = $ctx['runner']->confirm_article( $ctx['uuid'], 100 );

		$this->assertSame( ArticleResult::STATUS_SUCCESS, $result->status );
		// La règle destructive a vraiment été appliquée → post_content vide.
		$this->assertSame( '', Son100_Htmln_Test_Posts_Registry::$posts[100]->post_content );
		$this->assertSame( '', Son100_Htmln_Test_Posts_Registry::$updates[100] ?? null );
	}

	public function test_confirm_article_creates_revision_before_write(): void {
		$steps = $this->steps_stub();
		$ctx   = $this->arrange_regression_pending( 100, $steps, $this->diagnostics_stub() );
		// Reset le registre des révisions pour ne mesurer que celle créée par confirm_article.
		Son100_Htmln_Test_Posts_Registry::$revisions_created = array();

		$ctx['runner']->confirm_article( $ctx['uuid'], 100 );

		$this->assertArrayHasKey(
			100,
			Son100_Htmln_Test_Posts_Registry::$revisions_created,
			'wp_save_post_revision doit être appelée AVANT l\'écriture forcée'
		);
	}

	public function test_confirm_article_recalculates_diagnostic(): void {
		$steps = $this->steps_stub();
		$diags = $this->diagnostics_stub();
		$ctx   = $this->arrange_regression_pending( 100, $steps, $diags );

		$ctx['runner']->confirm_article( $ctx['uuid'], 100 );

		$this->assertCount( 1, $diags->upserted, 'Un upsert diagnostic attendu post-confirm' );
		$this->assertSame( 100, $diags->upserted[0]->post_id );
	}

	public function test_confirm_article_preserves_regression_in_persistence(): void {
		$steps = $this->steps_stub();
		$ctx   = $this->arrange_regression_pending( 100, $steps, $this->diagnostics_stub() );

		$ctx['runner']->confirm_article( $ctx['uuid'], 100 );

		// Dernière entrée écrite par confirm_article.
		$last = end( $steps->written_results );
		$this->assertSame( 100, $last['post_id'] );
		$this->assertSame( 'success', $last['result']['status'] );
		$this->assertArrayHasKey( 'regression', $last['result'], 'trace régression doit être préservée' );
		$this->assertArrayHasKey( 'failures', $last['result']['regression'] );
		$this->assertNotEmpty( $last['result']['regression']['failures'] );
	}

	public function test_confirm_article_returns_dto_with_regression_report(): void {
		$ctx    = $this->arrange_regression_pending( 100, $this->steps_stub(), $this->diagnostics_stub() );
		$result = $ctx['runner']->confirm_article( $ctx['uuid'], 100 );

		$this->assertNotNull( $result->regression_report, 'DTO retour porte la trace pour la SPA' );
		$this->assertGreaterThan( 0, $result->regression_report->failure_count() );
	}

	public function test_confirm_article_errors_when_no_regression_pending(): void {
		// Article jamais traité — pas de regression_pending → confirm doit erreur.
		$this->seed_post( 100, '<p>x</p>' );
		$steps  = $this->steps_stub();
		$runner = $this->make_runner( $steps, $this->diagnostics_stub(), array() );
		$uuid   = $runner->start_step( array( 100 ), array(), 1 );

		$result = $runner->confirm_article( $uuid, 100 );

		$this->assertSame( ArticleResult::STATUS_ERROR, $result->status );
		$this->assertStringContainsString( 'regression_pending', (string) $result->error );
		// Aucune écriture, aucune persistance modifiée pour cet article.
		$this->assertArrayNotHasKey( 100, Son100_Htmln_Test_Posts_Registry::$updates );
	}

	public function test_confirm_article_errors_when_step_unknown(): void {
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array() );
		$result = $runner->confirm_article( 'not-a-uuid', 100 );

		$this->assertSame( ArticleResult::STATUS_ERROR, $result->status );
		$this->assertStringContainsString( 'not-a-uuid', (string) $result->error );
	}

	public function test_confirm_article_after_already_success_errors(): void {
		// Si l'article est déjà success (pas regression_pending), confirm doit refuser.
		$this->seed_post( 100, '<p>Hello</p>' );
		$rule   = $this->fake_rule( 'P1', static fn( string $h ): string => $h );
		$steps  = $this->steps_stub();
		$runner = $this->make_runner( $steps, $this->diagnostics_stub(), array( $rule ) );
		$uuid   = $runner->start_step( array( 100 ), array( 'P1' ), 1 );
		$runner->process_article( $uuid, 100 );

		$result = $runner->confirm_article( $uuid, 100 );

		$this->assertSame( ArticleResult::STATUS_ERROR, $result->status );
	}

	// =========================================================================
	//  refuse_article (Phase 4.2)
	// =========================================================================

	public function test_refuse_article_does_not_write_post_content(): void {
		$steps = $this->steps_stub();
		$ctx   = $this->arrange_regression_pending( 100, $steps, $this->diagnostics_stub() );
		Son100_Htmln_Test_Posts_Registry::$revisions_created = array();
		Son100_Htmln_Test_Posts_Registry::$updates           = array();

		$result = $ctx['runner']->refuse_article( $ctx['uuid'], 100 );

		$this->assertSame( ArticleResult::STATUS_REFUSED, $result->status );
		$this->assertSame(
			'<p>Phrase à effacer</p>',
			Son100_Htmln_Test_Posts_Registry::$posts[100]->post_content,
			'post_content doit rester intact en cas de refus'
		);
		$this->assertArrayNotHasKey( 100, Son100_Htmln_Test_Posts_Registry::$updates );
		$this->assertArrayNotHasKey(
			100,
			Son100_Htmln_Test_Posts_Registry::$revisions_created,
			'aucune révision attendue (pas d\'écriture)'
		);
	}

	public function test_refuse_article_sets_manual_check_post_meta(): void {
		$ctx = $this->arrange_regression_pending( 100, $this->steps_stub(), $this->diagnostics_stub() );

		$ctx['runner']->refuse_article( $ctx['uuid'], 100 );

		$this->assertSame(
			1,
			Son100_Htmln_Test_Posts_Registry::$meta[100]['_son100_htmln_manual_check_required'] ?? null,
			'post_meta de relance manuelle doit être posée'
		);
	}

	public function test_refuse_article_does_not_recalculate_diagnostic(): void {
		// Pas d'écriture = pas de raison de recalculer le diagnostic
		// (le précédent reste valide tant que post_content n'a pas bougé).
		$diags = $this->diagnostics_stub();
		$ctx   = $this->arrange_regression_pending( 100, $this->steps_stub(), $diags );

		$ctx['runner']->refuse_article( $ctx['uuid'], 100 );

		$this->assertCount( 0, $diags->upserted );
	}

	public function test_refuse_article_persists_refused_with_regression(): void {
		$steps = $this->steps_stub();
		$ctx   = $this->arrange_regression_pending( 100, $steps, $this->diagnostics_stub() );

		$ctx['runner']->refuse_article( $ctx['uuid'], 100 );

		$last = end( $steps->written_results );
		$this->assertSame( 'refused', $last['result']['status'] );
		$this->assertArrayHasKey( 'regression', $last['result'] );
		$this->assertArrayHasKey( 'failures', $last['result']['regression'] );
	}

	public function test_refuse_article_returns_dto_with_regression_report(): void {
		$ctx    = $this->arrange_regression_pending( 100, $this->steps_stub(), $this->diagnostics_stub() );
		$result = $ctx['runner']->refuse_article( $ctx['uuid'], 100 );

		$this->assertNotNull( $result->regression_report );
		$this->assertGreaterThan( 0, $result->regression_report->failure_count() );
	}

	public function test_refuse_article_errors_when_no_regression_pending(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array() );
		$uuid   = $runner->start_step( array( 100 ), array(), 1 );

		$result = $runner->refuse_article( $uuid, 100 );

		$this->assertSame( ArticleResult::STATUS_ERROR, $result->status );
		$this->assertStringContainsString( 'regression_pending', (string) $result->error );
		// Pas de post_meta posée (l'erreur arrive avant).
		$this->assertArrayNotHasKey(
			'_son100_htmln_manual_check_required',
			Son100_Htmln_Test_Posts_Registry::$meta[100] ?? array()
		);
	}

	public function test_refuse_article_errors_when_step_unknown(): void {
		$runner = $this->make_runner( $this->steps_stub(), $this->diagnostics_stub(), array() );
		$result = $runner->refuse_article( 'not-a-uuid', 100 );

		$this->assertSame( ArticleResult::STATUS_ERROR, $result->status );
	}

	public function test_refuse_then_confirm_is_blocked(): void {
		// Une fois refusé, l'article n'est plus en regression_pending → confirm doit erreur.
		$ctx = $this->arrange_regression_pending( 100, $this->steps_stub(), $this->diagnostics_stub() );
		$ctx['runner']->refuse_article( $ctx['uuid'], 100 );

		$confirm = $ctx['runner']->confirm_article( $ctx['uuid'], 100 );

		$this->assertSame( ArticleResult::STATUS_ERROR, $confirm->status );
	}
}
