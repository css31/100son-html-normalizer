<?php
/**
 * Tests DiagnosticBatchRunner — Phase 3.3 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Diagnostics;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticBatchRunner;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use Son100_Htmln_Test_Wpdb;
use WP_Post;

final class DiagnosticBatchRunnerTest extends TestCase {

	private Son100_Htmln_Test_Wpdb $wpdb;
	private DiagnosticsRepository $repo;
	private DiagnosticBatchRunner $runner;
	private SettingsRepository $settings;

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$GLOBALS['son100_htmln_options'] = [];

		$this->wpdb     = new Son100_Htmln_Test_Wpdb();
		$this->repo     = new DiagnosticsRepository( $this->wpdb );
		$this->settings = new SettingsRepository();

		// Engine avec un PresetRegistry stub : aucune règle activée → tout est `normal`.
		$registry = new class extends PresetRegistry {
			public function __construct() { parent::__construct( new SettingsRepository() ); }
			public function get_enabled_rules(): array { return []; }
		};
		$engine        = new DiagnosticEngine( $registry, new MetricsCalculator() );
		$this->runner  = new DiagnosticBatchRunner( $engine, $this->repo, $this->settings );
	}

	private function seed_post( int $id, string $content = '<p>x</p>', string $type = 'post', string $status = 'publish' ): void {
		$p                = new WP_Post();
		$p->ID            = $id;
		$p->post_content  = $content;
		$p->post_type     = $type;
		$p->post_status   = $status;
		$p->post_modified = '2026-05-09 12:00:00';
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $p;
	}

	// =========================================================================
	//  start_batch
	// =========================================================================

	public function test_start_batch_lists_published_posts(): void {
		$this->seed_post( 1, '<p>a</p>' );
		$this->seed_post( 2, '<p>b</p>' );
		$this->seed_post( 3, '<p>c</p>' );

		$batch = $this->runner->start_batch();
		$this->assertSame( 3, $batch['total_articles'] );
		$this->assertSame( array( 1, 2, 3 ), $batch['post_ids'] );
		$this->assertSame( DiagnosticBatchRunner::DEFAULT_CHUNK_SIZE, $batch['chunk_size'] );
		$this->assertNotEmpty( $batch['batch_id'] );
		// batch_id ressemble à un UUID v4.
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[a-f0-9]{4}-[a-f0-9]{12}$/',
			$batch['batch_id']
		);
	}

	public function test_start_batch_filters_by_post_status(): void {
		$this->seed_post( 1, '<p>a</p>', 'post', 'publish' );
		$this->seed_post( 2, '<p>b</p>', 'post', 'draft' );
		$batch = $this->runner->start_batch();
		// Seul l'article publish est retenu.
		$this->assertSame( array( 1 ), $batch['post_ids'] );
	}

	public function test_start_batch_respects_f8_post_types_selection(): void {
		$this->settings->set_f8_post_types_selection( array( 'post' ) );
		$this->seed_post( 1, '<p>a</p>', 'post' );
		$this->seed_post( 2, '<p>b</p>', 'page' );
		$this->seed_post( 3, '<p>c</p>', 'post' );

		$batch = $this->runner->start_batch();
		$this->assertSame( array( 1, 3 ), $batch['post_ids'] );
	}

	public function test_start_batch_chunk_size_overrideable(): void {
		$batch = $this->runner->start_batch( 5 );
		$this->assertSame( 5, $batch['chunk_size'] );
	}

	public function test_start_batch_chunk_size_floor_at_one(): void {
		$batch = $this->runner->start_batch( 0 );
		$this->assertSame( 1, $batch['chunk_size'] );
		$batch = $this->runner->start_batch( -10 );
		$this->assertSame( 1, $batch['chunk_size'] );
	}

	public function test_start_batch_returns_zero_total_when_no_posts(): void {
		$batch = $this->runner->start_batch();
		$this->assertSame( 0, $batch['total_articles'] );
		$this->assertSame( array(), $batch['post_ids'] );
	}

	// =========================================================================
	//  process_chunk
	// =========================================================================

	public function test_process_chunk_diagnoses_each_post(): void {
		$this->seed_post( 1, '<p>a</p>' );
		$this->seed_post( 2, '<p>b</p>' );
		// 2 inserts attendus dans la table diagnostics.
		$this->wpdb->get_row_queue = array( null, null );  // upsert -> find absent ×2

		$results = $this->runner->process_chunk( array( 1, 2 ) );
		$this->assertCount( 2, $results );
		$this->assertInstanceOf( DiagnosticRecord::class, $results[1] );
		$this->assertInstanceOf( DiagnosticRecord::class, $results[2] );
		$this->assertSame( 1, $results[1]->post_id );
		$this->assertSame( 2, $results[2]->post_id );

		// Verifie que les upserts ont eu lieu (2 inserts, 0 update).
		$this->assertCount( 2, $this->wpdb->insert_log );
	}

	public function test_process_chunk_skips_unknown_post_ids(): void {
		$this->seed_post( 1, '<p>a</p>' );
		$this->wpdb->get_row_queue = array( null );  // 1 upsert -> 1 insert

		$results = $this->runner->process_chunk( array( 1, 99999, 88888 ) );
		// Seul l'ID 1 produit un record.
		$this->assertCount( 1, $results );
		$this->assertArrayHasKey( 1, $results );
		$this->assertCount( 1, $this->wpdb->insert_log );
	}

	public function test_process_chunk_with_empty_input_is_noop(): void {
		$results = $this->runner->process_chunk( array() );
		$this->assertSame( array(), $results );
		$this->assertCount( 0, $this->wpdb->insert_log );
	}

	public function test_process_chunk_calls_upsert_with_record(): void {
		$this->seed_post( 1, '<p>x</p>' );
		$this->wpdb->get_row_queue = array( null );

		$this->runner->process_chunk( array( 1 ) );
		$this->assertCount( 1, $this->wpdb->insert_log );
		$inserted = $this->wpdb->insert_log[0]['data'];
		$this->assertSame( 1, $inserted['post_id'] );
		$this->assertSame( 'normal', $inserted['status'] );
		// metrics est sérialisé en JSON.
		$this->assertIsString( $inserted['metrics'] );
		$decoded = json_decode( $inserted['metrics'], true );
		$this->assertArrayHasKey( 'paragraphs', $decoded );
	}

	// =========================================================================
	//  start_batch — filtres SQL + builder + exclude_normalized (post-rc4)
	// =========================================================================
	//
	// Note testabilité : le stub `get_posts()` du bootstrap ne filtre que sur
	// post_type / post_status — les filtres SQL natifs WP (`cat`, `date_query`,
	// `s`) sont propagés à `get_posts()` mais leur effet n'est pas vérifiable
	// en unit (il faudrait un WP_Query mock plus riche, hors scope V1.0). Les
	// tests suivants vérifient :
	//   (a) la rétro-compat — params absents → comportement identique à V1.0 ;
	//   (b) le post-filtre PHP via BuilderClassifier (builder + exclude_normalized) ;
	//   (c) la robustesse — payload de filtres invalide → ignoré silencieusement.

	private function make_classifier_runner(
		BuilderClassifier $classifier
	): DiagnosticBatchRunner {
		$registry = new class extends PresetRegistry {
			public function __construct() {
				parent::__construct( new SettingsRepository() );
			}
			public function get_enabled_rules(): array {
				return array();
			}
		};
		$engine = new DiagnosticEngine( $registry, new MetricsCalculator() );
		return new DiagnosticBatchRunner(
			$engine,
			$this->repo,
			$this->settings,
			$classifier
		);
	}

	public function test_start_batch_without_filters_keeps_legacy_behavior(): void {
		// Rétro-compat : appel sans les nouveaux params (V1.0 d'origine).
		$this->seed_post( 1, '<p>a</p>' );
		$this->seed_post( 2, '<p>b</p>' );

		$batch = $this->runner->start_batch( null, null, array(), false );
		$this->assertSame( array( 1, 2 ), $batch['post_ids'] );
	}

	public function test_start_batch_accepts_sql_filters_without_crashing(): void {
		// On ne peut pas vérifier l'effet du filtre (stub get_posts limité)
		// mais on garantit qu'un payload de filtres valide ne crashe pas
		// et que les autres mécanismes (chunk_size, UUID) fonctionnent toujours.
		$this->seed_post( 1, '<p>a</p>' );
		$filters = array(
			'search' => 'whatever',
			'cat_id' => 7,
			'year'   => 2024,
			'month'  => 5,
		);
		$batch = $this->runner->start_batch( null, null, $filters, false );
		$this->assertArrayHasKey( 'batch_id', $batch );
		$this->assertArrayHasKey( 'post_ids', $batch );
	}

	public function test_start_batch_exclude_normalized_removes_already_ok_posts(): void {
		// Articles « normalisés » au sens du user = statut diagnostic = 'normal'
		// (et non périmé). On simule le retour de la requête SQL
		// `find_post_ids_with_status` via la queue stub `get_col_queue`.
		$this->seed_post( 1, '<p>a</p>' );
		$this->seed_post( 2, '<p>b</p>' );
		$this->seed_post( 3, '<p>c</p>' );

		// Simule : posts 1 et 2 ont déjà un diagnostic 'normal' fresh ;
		// post 3 n'a jamais été diagnostiqué (= absent du résultat SQL).
		$this->wpdb->get_col_queue = array( array( 1, 2 ) );

		$batch = $this->runner->start_batch( null, null, array(), true );

		// Les 2 posts déjà OK sont exclus → seul le post jamais diagnostiqué reste.
		$this->assertSame( array( 3 ), $batch['post_ids'] );
		$this->assertSame( 1, $batch['total_articles'] );
	}

	public function test_start_batch_exclude_normalized_keeps_never_diagnosed_posts(): void {
		// Cas dégénéré : aucun post n'a de row dans `son100_htmln_diagnostics`
		// → la requête `find_post_ids_with_status` retourne tableau vide
		// → aucun post n'est exclu → tous restent dans le scope.
		$this->seed_post( 1, '<p>a</p>' );
		$this->seed_post( 2, '<p>b</p>' );

		$this->wpdb->get_col_queue = array( array() );

		$batch = $this->runner->start_batch( null, null, array(), true );

		$this->assertSame( array( 1, 2 ), $batch['post_ids'] );
	}

	public function test_start_batch_builder_filter_keeps_only_matching_type(): void {
		// 1 SiteOrigin, 1 Gutenberg, 1 other.
		$this->seed_post(
			1,
			'<div class="panel-layout">SO content</div>'
		);
		$this->seed_post(
			2,
			'<!-- wp:paragraph --><p>gut</p><!-- /wp:paragraph -->'
		);
		$this->seed_post( 3, '<p>libre</p>' );

		$runner = $this->make_classifier_runner( new BuilderClassifier() );
		$batch  = $runner->start_batch(
			null,
			null,
			array( 'builder' => 'siteorigin_flat' ),
			false
		);

		// Seul l'article SiteOrigin aplati passe le filtre `builder`.
		$this->assertSame( array( 1 ), $batch['post_ids'] );
	}

	public function test_start_batch_combines_builder_and_exclude_normalized(): void {
		// Cumul : filtre builder=siteorigin_flat (PHP, classifier) +
		// exclude_normalized=true (SQL, find_post_ids_with_status).
		// Ordre interne : builder filtre PHP **avant** exclude_normalized SQL,
		// donc le repo reçoit déjà la liste réduite [1, 3] (les 2 SO_flat
		// après filtrage du Gutenberg).
		$this->seed_post( 1, '<div class="panel-layout">SO 1</div>' );
		$this->seed_post(
			2,
			'<!-- wp:paragraph --><p>gut</p><!-- /wp:paragraph -->'
		);
		$this->seed_post( 3, '<div class="panel-layout">SO 2</div>' );

		// Simule : parmi [1, 3] (résultat post-filtre builder), le post 1
		// est déjà OK (status 'normal' fresh) → il doit être exclu.
		$this->wpdb->get_col_queue = array( array( 1 ) );

		$runner = $this->make_classifier_runner( new BuilderClassifier() );
		$batch  = $runner->start_batch(
			null,
			null,
			array( 'builder' => 'siteorigin_flat' ),
			true
		);

		// Post 2 exclu par filtre builder (Gutenberg ≠ siteorigin_flat).
		// Post 1 exclu par exclude_normalized (déjà OK).
		// Post 3 conservé (SO_flat ET jamais diagnostiqué).
		$this->assertSame( array( 3 ), $batch['post_ids'] );
	}

	public function test_start_batch_invalid_builder_filter_is_ignored(): void {
		// Builder inconnu (typo, valeur frauduleuse) → filtre ignoré
		// silencieusement, tous les articles reviennent.
		$this->seed_post( 1, '<p>a</p>' );
		$this->seed_post( 2, '<p>b</p>' );

		$runner = $this->make_classifier_runner( new BuilderClassifier() );
		$batch  = $runner->start_batch(
			null,
			null,
			array( 'builder' => 'pas_un_type_valide' ),
			false
		);

		$this->assertSame( array( 1, 2 ), $batch['post_ids'] );
	}

	// Note : `BuilderClassifier` est `final`, on ne peut pas l'étendre via
	// classe anonyme pour vérifier que la boucle de classification est
	// court-circuitée quand aucun filtre pertinent n'est posé. L'optimisation
	// (`if ( $exclude_normalized || $builder_filter_valid )` dans `start_batch`)
	// reste vérifiée par le code lui-même + le test de rétro-compat ci-dessus
	// qui couvre le cas « pas de filtres → comportement V1.0 inchangé ».
}
