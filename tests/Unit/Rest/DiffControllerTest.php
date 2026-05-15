<?php
/**
 * Tests DiffController — Phase 5.4 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Rest\DiffController;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use WP_Post;
use WP_REST_Request;

final class DiffControllerTest extends TestCase {

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;
	}

	private function registry_with( array $rules ): PresetRegistry {
		return new class( $rules ) extends PresetRegistry {
			/** @param list<RuleInterface> $rules */
			public function __construct( private array $rules ) {
				parent::__construct( new SettingsRepository() );
			}
			public function get_enabled_rules(): array {
				return $this->rules;
			}
			public function get_rules_for_subset( array $rule_ids ): array {
				$wanted = array_flip( array_map( 'strval', $rule_ids ) );
				$subset = array();
				foreach ( $this->rules as $r ) {
					if ( isset( $wanted[ $r->id() ] ) ) {
						$subset[] = $r;
					}
				}
				return $subset;
			}
		};
	}

	private function fake_rule( string $id, callable $transform, int $match_count = 0 ): RuleInterface {
		return new class( $id, $transform, $match_count ) implements RuleInterface {
			public function __construct(
				private string $rule_id,
				private mixed $transform,
				private int $match_count
			) {}
			public function id(): string { return $this->rule_id; }
			public function label(): string { return $this->rule_id; }
			public function apply( string $html, array $context = array() ): string {
				return ( $this->transform )( $html );
			}
			public function countMatches( string $html, array $context = array() ): int {
				return $this->match_count;
			}
		};
	}

	private function seed_post(
		int $id,
		string $content,
		string $date = '',
		array $categories = array()
	): void {
		$p                = new WP_Post();
		$p->ID            = $id;
		$p->post_content  = $content;
		$p->post_type     = 'post';
		$p->post_status   = 'publish';
		if ( '' !== $date ) {
			$p->post_date = $date;
		}
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ]      = $p;
		Son100_Htmln_Test_Posts_Registry::$categories[ $id ] = $categories;
	}

	private function make_request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/posts/diff' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function make_controller( array $rules = array() ): DiffController {
		return new DiffController(
			$this->registry_with( $rules ),
			new MetricsCalculator(),
			new BuilderClassifier(),
		);
	}

	// =========================================================================
	//  register_routes
	// =========================================================================

	public function test_register_routes_creates_one_endpoint(): void {
		$this->make_controller()->register_routes();
		$this->assertCount( 1, $GLOBALS['son100_htmln_test_rest_routes'] );
		$this->assertSame( 'htmln/v1', $GLOBALS['son100_htmln_test_rest_routes'][0]['namespace'] );
		$this->assertSame( '/posts/(?P<id>\d+)/diff', $GLOBALS['son100_htmln_test_rest_routes'][0]['route'] );
	}

	// =========================================================================
	//  compute_diff
	// =========================================================================

	public function test_compute_diff_returns_before_after_and_metrics(): void {
		$this->seed_post( 100, '<p>Original</p>' );
		$rule = $this->fake_rule(
			'R1',
			static fn( string $html ): string => str_replace( 'Original', 'Modifié', $html )
		);

		$response = $this->make_controller( array( $rule ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( '<p>Original</p>', $body['html_before'] );
		$this->assertSame( '<p>Modifié</p>', $body['html_after'] );
		$this->assertArrayHasKey( 'paragraphs', $body['metrics_before'] );
		$this->assertArrayHasKey( 'paragraphs', $body['metrics_after'] );
		$this->assertFalse( $body['unchanged'] );
	}

	public function test_compute_diff_marks_unchanged_when_html_identical(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertTrue( $response->get_data()['unchanged'] );
	}

	public function test_compute_diff_does_not_create_revision_or_write(): void {
		// Garde-fou §13 : le diff est preview-only, pas d'effet sur post_content.
		$this->seed_post( 100, '<p>Original</p>' );
		$rule = $this->fake_rule( 'R1', static fn(): string => '<p>Tout effacé</p>' );

		$this->make_controller( array( $rule ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertSame(
			'<p>Original</p>',
			Son100_Htmln_Test_Posts_Registry::$posts[100]->post_content,
			'post_content doit rester intact (diff preview-only)'
		);
		$this->assertArrayNotHasKey( 100, Son100_Htmln_Test_Posts_Registry::$updates );
		$this->assertArrayNotHasKey( 100, Son100_Htmln_Test_Posts_Registry::$revisions_created );
	}

	public function test_compute_diff_400_for_empty_rule_ids(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$response = $this->make_controller()->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array() ) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_rule_ids', $response->get_data()['code'] );
	}

	public function test_compute_diff_404_for_unknown_post(): void {
		$response = $this->make_controller()->compute_diff(
			$this->make_request( array( 'id' => 999, 'rule_ids' => array( 'R1' ) ) )
		);
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'post_not_found', $response->get_data()['code'] );
	}

	public function test_compute_diff_ignores_unknown_rule_ids(): void {
		// applySubset ignore silencieusement les ids inconnus → pas d'erreur.
		$this->seed_post( 100, '<p>x</p>' );
		$response = $this->make_controller()->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'P_UNKNOWN' ) ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['unchanged'] );
	}

	// =========================================================================
	//  Métadonnées article — header modale Diff (post-rc4)
	// =========================================================================

	public function test_compute_diff_exposes_post_date(): void {
		$this->seed_post( 100, '<p>x</p>', '2024-03-12 14:30:00' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertSame( '2024-03-12 14:30:00', $response->get_data()['post_date'] );
	}

	public function test_compute_diff_exposes_categories(): void {
		$this->seed_post(
			100,
			'<p>x</p>',
			'2024-01-01 00:00:00',
			array( 'Animaux', 'Maison' )
		);
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertSame( array( 'Animaux', 'Maison' ), $response->get_data()['categories'] );
	}

	public function test_compute_diff_exposes_empty_categories_when_none(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertSame( array(), $response->get_data()['categories'] );
	}

	public function test_compute_diff_exposes_builder_type(): void {
		// Contenu Gutenberg → classifier doit retourner `gutenberg`.
		$this->seed_post( 100, '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertSame( 'gutenberg', $response->get_data()['builder_type'] );
	}

	public function test_compute_diff_flags_fossil_panels_data_on_gutenberg(): void {
		// Article classifié Gutenberg (via has_blocks) ET portant un vestige
		// `panels_data` en post-meta — la pastille SPA doit afficher l'état
		// orange « Gut + fossile ».
		$this->seed_post( 100, '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		Son100_Htmln_Test_Posts_Registry::$meta[100]['panels_data'] = array(
			'widgets' => array( array( 'panels_info' => array() ) ),
		);
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$body = $response->get_data();
		$this->assertSame( 'gutenberg', $body['builder_type'] );
		$this->assertTrue( $body['has_fossil_panels_data'] );
	}

	public function test_compute_diff_no_fossil_flag_on_pure_gutenberg(): void {
		// Article Gutenberg sans aucun vestige SO en meta — flag false.
		$this->seed_post( 100, '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertFalse( $response->get_data()['has_fossil_panels_data'] );
	}

	public function test_compute_diff_no_fossil_flag_on_siteorigin(): void {
		// `panels_data` non-vide sur un article SO classique ≠ fossile.
		// Le flag fossile est scope au type Gutenberg uniquement.
		$this->seed_post( 100, '<div class="panel-layout"><div class="so-panel">x</div></div>' );
		Son100_Htmln_Test_Posts_Registry::$meta[100]['panels_data'] = array(
			'widgets' => array( array( 'panels_info' => array() ) ),
		);
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$body = $response->get_data();
		$this->assertSame( 'siteorigin', $body['builder_type'] );
		$this->assertFalse( $body['has_fossil_panels_data'] );
	}

	// =========================================================================
	//  Normalisation pour l'affichage — supprime le bruit whitespace HTML
	//  qui sinon apparait comme un faux diff dans le surlignage stabylo.
	// =========================================================================

	public function test_compute_diff_normalizes_double_spaces_between_attributes(): void {
		$this->seed_post( 100, '<div id="x"  class="y">contenu</div>' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$body = $response->get_data();
		// Le double espace `"x"  class="y"` est ramene a un simple espace
		// dans `html_before` (cote affichage), pareil que `html_after`.
		$this->assertStringNotContainsString( '"x"  class', $body['html_before'] );
		$this->assertStringContainsString( '"x" class', $body['html_before'] );
	}

	public function test_compute_diff_normalizes_trailing_space_before_close_bracket(): void {
		$this->seed_post( 100, '<div class="y" >contenu</div>' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$body = $response->get_data();
		// L'espace avant le `>` final est retire.
		$this->assertStringNotContainsString( '"y" >', $body['html_before'] );
		$this->assertStringContainsString( '"y">', $body['html_before'] );
	}

	public function test_compute_diff_unchanged_true_when_only_whitespace_differs(): void {
		// Cas frequent : le post_content brut a du bruit whitespace mais
		// les regles n'auraient rien a faire dessus. Sans normalisation
		// pour l'affichage, le flag `unchanged` serait `false` (vrai
		// changement byte-a-byte par DOMDocument), ce qui afficherait a
		// tort la notice « Aucun changement » manquante. Avec normalisation
		// des deux cotes : `unchanged === true`.
		$this->seed_post( 100, '<div id="x"  class="y" >contenu</div>' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$body = $response->get_data();
		$this->assertTrue( $body['unchanged'] );
		// Les deux cotes sont rigoureusement identiques apres normalisation.
		$this->assertSame( $body['html_before'], $body['html_after'] );
	}

	public function test_compute_diff_normalization_is_idempotent_on_clean_html(): void {
		// Du HTML deja "propre" doit passer le round-trip sans alteration
		// inattendue (pas d'attribut reordonne, pas d'entite recodee
		// silencieusement, etc.).
		$clean = '<p class="x">contenu <strong>fort</strong> et liens</p>';
		$this->seed_post( 100, $clean );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertSame( $clean, $response->get_data()['html_before'] );
	}

	// =========================================================================
	//  applied_rules — sous-ensemble des rule_ids qui ont effectivement
	//  matché sur `html_before` (countMatches > 0).
	// =========================================================================

	public function test_compute_diff_applied_rules_lists_rules_with_matches(): void {
		$this->seed_post( 100, '<p>Texte</p>' );
		// Trois règles candidates : R1 a 3 occurrences, R2 a 1, R3 a 0
		// (donc R3 doit être exclu de `applied_rules`).
		$p1 = $this->fake_rule( 'R1', static fn( string $h ): string => $h, 3 );
		$p2 = $this->fake_rule( 'R2', static fn( string $h ): string => $h, 1 );
		$p3 = $this->fake_rule( 'R3', static fn( string $h ): string => $h, 0 );

		$response = $this->make_controller( array( $p1, $p2, $p3 ) )->compute_diff(
			$this->make_request( array(
				'id'       => 100,
				'rule_ids' => array( 'R1', 'R2', 'R3' ),
			) )
		);

		$body = $response->get_data();
		$this->assertArrayHasKey( 'applied_rules', $body );
		$this->assertCount( 2, $body['applied_rules'] );

		$by_id = array();
		foreach ( $body['applied_rules'] as $entry ) {
			$by_id[ $entry['rule_id'] ] = $entry['occurrences'];
		}
		$this->assertSame( 3, $by_id['R1'] );
		$this->assertSame( 1, $by_id['R2'] );
		$this->assertArrayNotHasKey( 'R3', $by_id );
	}

	public function test_compute_diff_applied_rules_empty_when_no_match(): void {
		$this->seed_post( 100, '<p>Texte</p>' );
		$noop = $this->fake_rule( 'R1', static fn( string $h ): string => $h, 0 );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$this->assertSame( array(), $response->get_data()['applied_rules'] );
	}

	public function test_compute_diff_applied_rules_excludes_rules_not_in_subset(): void {
		// Une règle (R9) **active dans le registre** mais NON demandée dans
		// `rule_ids` ne doit pas apparaître dans `applied_rules`, même si
		// son countMatches serait positif.
		$this->seed_post( 100, '<p>Texte</p>' );
		$p1 = $this->fake_rule( 'R1', static fn( string $h ): string => $h, 2 );
		$p9 = $this->fake_rule( 'R9', static fn( string $h ): string => $h, 5 );

		$response = $this->make_controller( array( $p1, $p9 ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'R1' ) ) )
		);

		$body = $response->get_data();
		$this->assertCount( 1, $body['applied_rules'] );
		$this->assertSame( 'R1', $body['applied_rules'][0]['rule_id'] );
	}
}
