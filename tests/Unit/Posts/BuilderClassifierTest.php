<?php
/**
 * Tests BuilderClassifier — classification 5 types.
 *
 * Couvre :
 *  - Détection siteorigin via panels_data meta
 *  - Détection siteorigin via bloc <!-- wp:siteorigin-panels
 *  - Détection siteorigin_flat via classes panel-layout / so-panel
 *  - Détection gutenberg via <!-- wp:
 *  - Détection other (aucun marqueur)
 *  - Override `out` via _son100_htmln_builder_override
 *  - Priorité override sur détection auto
 *  - Ordre de précédence : meta > bloc > flat > gutenberg > other
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Posts;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use WP_Post;

final class BuilderClassifierTest extends TestCase {

	private BuilderClassifier $classifier;

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$this->classifier = new BuilderClassifier();
	}

	private function register_post( int $id, string $content = '', array $meta = array() ): void {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_content = $content;
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $post;
		Son100_Htmln_Test_Posts_Registry::$meta[ $id ] = $meta;
	}

	// =========================================================================
	//  TYPE_SITEORIGIN
	// =========================================================================

	public function test_classifies_panels_data_meta_as_siteorigin(): void {
		$this->register_post(
			10,
			'<div>contenu rendu</div>',
			array( 'panels_data' => array( 'widgets' => array( array( 'class' => 'SiteOrigin_Widget' ) ) ) )
		);
		$this->assertSame(
			BuilderClassifier::TYPE_SITEORIGIN,
			$this->classifier->classify( 10 )
		);
	}

	public function test_classifies_siteorigin_block_as_siteorigin(): void {
		$this->register_post(
			11,
			'<!-- wp:siteorigin-panels/layout-block {"panelsData":{}} -->...<!-- /wp:siteorigin-panels/layout-block -->'
		);
		$this->assertSame(
			BuilderClassifier::TYPE_SITEORIGIN,
			$this->classifier->classify( 11 )
		);
	}

	public function test_empty_panels_data_array_does_not_match(): void {
		// panels_data = []  → considéré comme absent.
		$this->register_post(
			12,
			'',
			array( 'panels_data' => array() )
		);
		$this->assertSame(
			BuilderClassifier::TYPE_OTHER,
			$this->classifier->classify( 12 )
		);
	}

	// =========================================================================
	//  TYPE_SITEORIGIN_FLAT
	// =========================================================================

	public function test_classifies_panel_layout_class_as_siteorigin_flat(): void {
		$this->register_post(
			20,
			'<div class="panel-layout"><div class="so-panel">contenu</div></div>'
		);
		$this->assertSame(
			BuilderClassifier::TYPE_SITEORIGIN_FLAT,
			$this->classifier->classify( 20 )
		);
	}

	public function test_classifies_so_panel_only_as_siteorigin_flat(): void {
		// `so-panel` seul suffit, sans `panel-layout` parent.
		$this->register_post(
			21,
			'<div class="so-panel"><p>contenu</p></div>'
		);
		$this->assertSame(
			BuilderClassifier::TYPE_SITEORIGIN_FLAT,
			$this->classifier->classify( 21 )
		);
	}

	// =========================================================================
	//  TYPE_GUTENBERG
	// =========================================================================

	public function test_classifies_gutenberg_block_as_gutenberg(): void {
		$this->register_post(
			30,
			'<!-- wp:paragraph --><p>contenu Gutenberg</p><!-- /wp:paragraph -->'
		);
		$this->assertSame(
			BuilderClassifier::TYPE_GUTENBERG,
			$this->classifier->classify( 30 )
		);
	}

	public function test_so_block_prevails_over_gutenberg_marker(): void {
		// Article mixte : bloc SO + bloc Gutenberg paragraphe.
		// La détection SO prévaut (le rendu est piloté par le bloc SO).
		$this->register_post(
			31,
			'<!-- wp:siteorigin-panels/layout-block --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:siteorigin-panels/layout-block -->'
		);
		$this->assertSame(
			BuilderClassifier::TYPE_SITEORIGIN,
			$this->classifier->classify( 31 )
		);
	}

	// =========================================================================
	//  TYPE_OTHER
	// =========================================================================

	public function test_classifies_plain_html_as_other(): void {
		$this->register_post(
			40,
			'<p>HTML classique sans marqueur.</p><h2>Titre</h2>'
		);
		$this->assertSame(
			BuilderClassifier::TYPE_OTHER,
			$this->classifier->classify( 40 )
		);
	}

	public function test_classifies_empty_content_as_other(): void {
		$this->register_post( 41, '' );
		$this->assertSame(
			BuilderClassifier::TYPE_OTHER,
			$this->classifier->classify( 41 )
		);
	}

	// =========================================================================
	//  TYPE_OUT (override manuel)
	// =========================================================================

	public function test_override_out_prevails_over_auto_detection(): void {
		// L'article serait sinon classé `siteorigin` (panels_data présent),
		// mais l'override manuel `out` doit prendre la priorité absolue.
		$this->register_post(
			50,
			'<div>x</div>',
			array(
				'panels_data'                      => array( 'widgets' => array( 'x' ) ),
				BuilderClassifier::META_OVERRIDE   => BuilderClassifier::TYPE_OUT,
			)
		);
		$this->assertSame(
			BuilderClassifier::TYPE_OUT,
			$this->classifier->classify( 50 )
		);
	}

	public function test_override_with_other_value_is_ignored(): void {
		// Seule la valeur `out` est respectée — toute autre valeur sur la
		// post-meta override est ignorée et on retombe sur la détection auto.
		$this->register_post(
			51,
			'<p>texte simple</p>',
			array( BuilderClassifier::META_OVERRIDE => 'autre_valeur_inattendue' )
		);
		$this->assertSame(
			BuilderClassifier::TYPE_OTHER,
			$this->classifier->classify( 51 )
		);
	}

	// =========================================================================
	//  Helpers (is_out_of_scope, ALL_TYPES)
	// =========================================================================

	public function test_is_out_of_scope_only_for_out(): void {
		$this->assertTrue( $this->classifier->is_out_of_scope( BuilderClassifier::TYPE_OUT ) );
		$this->assertFalse( $this->classifier->is_out_of_scope( BuilderClassifier::TYPE_SITEORIGIN ) );
		$this->assertFalse( $this->classifier->is_out_of_scope( BuilderClassifier::TYPE_OTHER ) );
	}

	public function test_all_types_constants_consistency(): void {
		$this->assertCount( 5, BuilderClassifier::ALL_TYPES );
		$this->assertContains( BuilderClassifier::TYPE_SITEORIGIN, BuilderClassifier::ALL_TYPES );
		$this->assertContains( BuilderClassifier::TYPE_SITEORIGIN_FLAT, BuilderClassifier::ALL_TYPES );
		$this->assertContains( BuilderClassifier::TYPE_GUTENBERG, BuilderClassifier::ALL_TYPES );
		$this->assertContains( BuilderClassifier::TYPE_OTHER, BuilderClassifier::ALL_TYPES );
		$this->assertContains( BuilderClassifier::TYPE_OUT, BuilderClassifier::ALL_TYPES );
	}

	// =========================================================================
	//  rc4 — `panels_data` fossile : un article migré vers Gutenberg garde
	//  parfois son ancien `panels_data` en post-meta. Le `post_content`
	//  effectif (Gutenberg pur) doit primer sur le vestige meta.
	// =========================================================================

	public function test_gutenberg_content_overrides_fossil_panels_data(): void {
		// Cas réel observé sur le corpus MMM-2 (post #19785) : article
		// migré vers Gut dont l'ancien `panels_data` SO est resté en
		// post-meta. Le rendu effectif est Gutenberg → la classification
		// doit l'être aussi.
		$this->register_post(
			60,
			'<!-- wp:image {"id":1,"align":"center"} --><figure class="wp-block-image"><img src="x.jpg"/></figure><!-- /wp:image -->'
				. '<!-- wp:paragraph --><p>Contenu réel Gutenberg.</p><!-- /wp:paragraph -->',
			array(
				'panels_data' => array(
					'widgets'    => array( array( 'class' => 'SiteOrigin_Widget' ) ),
					'grids'      => array(),
					'grid_cells' => array(),
				),
			)
		);
		$this->assertSame(
			BuilderClassifier::TYPE_GUTENBERG,
			$this->classifier->classify( 60 )
		);
	}

	public function test_so_classes_with_panels_data_is_siteorigin(): void {
		// Cas typique SO actif natif : `panels_data` en meta + rendu HTML
		// aplati dans `post_content` (classes `panel-layout`/`so-panel`).
		// Distingue du flat (qui n'a pas le meta).
		$this->register_post(
			61,
			'<div class="panel-layout"><div class="so-panel"><p>x</p></div></div>',
			array( 'panels_data' => array( 'widgets' => array( 'x' ) ) )
		);
		$this->assertSame(
			BuilderClassifier::TYPE_SITEORIGIN,
			$this->classifier->classify( 61 )
		);
	}

	public function test_panels_data_alone_without_content_marker_is_siteorigin(): void {
		// Cas dégénéré : `panels_data` présent mais `post_content` vide
		// ou sans aucun marqueur (ni SO ni Gut). Probablement un article
		// SO dont la mise en page n'a jamais été régénérée en post_content.
		// On respecte la présence du meta.
		$this->register_post(
			62,
			'<p>Texte brut sans marqueur.</p>',
			array( 'panels_data' => array( 'widgets' => array( 'x' ) ) )
		);
		$this->assertSame(
			BuilderClassifier::TYPE_SITEORIGIN,
			$this->classifier->classify( 62 )
		);
	}

	public function test_so_block_marker_wins_over_fossil_panels_data(): void {
		// `<!-- wp:siteorigin-panels` dans le contenu prime sur tout —
		// inutile de regarder le meta. Filet de sécurité du nouvel ordre.
		$this->register_post(
			63,
			'<!-- wp:siteorigin-panels/layout-block --><p>x</p><!-- /wp:siteorigin-panels/layout-block -->',
			array( 'panels_data' => array() ) // meta vide, contenu SO 2.10+
		);
		$this->assertSame(
			BuilderClassifier::TYPE_SITEORIGIN,
			$this->classifier->classify( 63 )
		);
	}
}
