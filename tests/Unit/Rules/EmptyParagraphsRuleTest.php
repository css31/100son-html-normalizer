<?php
/**
 * Tests R1 — EmptyParagraphsRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\EmptyParagraphsRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class EmptyParagraphsRuleTest extends TestCase {

	use HtmlAssertions;

	private EmptyParagraphsRule $rule;

	protected function setUp(): void {
		$this->rule = new EmptyParagraphsRule();
	}

	public function test_id_and_label(): void {
		$this->assertSame( 'R1', $this->rule->id() );
		$this->assertNotEmpty( $this->rule->label() );
	}

	public function test_strictly_empty_paragraph_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<p></p>' ) );
	}

	public function test_paragraph_with_nbsp_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<p>&nbsp;</p>' ) );
	}

	public function test_paragraph_with_single_space_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<p> </p>' ) );
	}

	public function test_paragraph_with_only_whitespace_and_nbsp_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( "<p>\n   \n&nbsp;\n</p>" ) );
	}

	public function test_paragraph_with_inline_empty_tag_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<p><strong></strong></p>' ) );
	}

	public function test_non_empty_paragraph_is_preserved(): void {
		$this->assertHtmlEquals( '<p>texte</p>', $this->rule->apply( '<p>texte</p>' ) );
	}

	public function test_paragraph_with_image_is_preserved(): void {
		$html = '<p><img src="x.jpg" alt="x"/></p>';
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}

	public function test_paragraph_with_br_only_is_preserved(): void {
		// Un <br> est un élément structurel — on ne supprime pas, ce sera R5
		// (ExcessiveBr) qui s'occupera du <br> isolé/résiduel selon son seuil.
		$html = '<p><br/></p>';
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}

	public function test_mixed_input_only_empties_removed(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/empty-paragraphs-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/empty-paragraphs-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	public function test_no_paragraphs_input_passes_through(): void {
		$html = '<h2>Titre</h2><div>Contenu</div>';
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}

	// =========================================================================
	// countMatches() — Phase 1 V1.0
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_zero_when_no_empty_paragraph(): void {
		$this->assertSame( 0, $this->rule->countMatches( '<p>texte</p><h2>Titre</h2>' ) );
	}

	public function test_count_matches_returns_count_of_removable_paragraphs(): void {
		$html = '<p></p><p>ok</p><p>&nbsp;</p><p><strong></strong></p>';
		$this->assertSame( 3, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_does_not_count_paragraph_with_image(): void {
		$html = '<p><img src="x.jpg" alt="x"/></p><p></p>';
		$this->assertSame( 1, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_apply_idempotence(): void {
		$html  = '<p></p><p>ok</p><p>&nbsp;</p>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}

	// =========================================================================
	// Blocs Gutenberg `wp:paragraph` — retrait du wrapper de bloc
	// =========================================================================

	public function test_gutenberg_paragraph_block_wrapping_empty_p_is_fully_removed(): void {
		$html = "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
		// Tout le bloc disparait : commentaires + <p> + whitespace residuel.
		$this->assertSame( '', trim( $this->rule->apply( $html ) ) );
	}

	public function test_gutenberg_paragraph_block_with_nbsp_p_is_fully_removed(): void {
		$html = "<!-- wp:paragraph -->\n<p>&nbsp;</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( '', trim( $this->rule->apply( $html ) ) );
	}

	public function test_gutenberg_paragraph_block_with_json_attrs_is_fully_removed(): void {
		// Variante avec attrs JSON sur le commentaire ouvrant (align, dropCap, etc.).
		$html = '<!-- wp:paragraph {"align":"center"} --><p></p><!-- /wp:paragraph -->';
		$this->assertSame( '', trim( $this->rule->apply( $html ) ) );
	}

	public function test_gutenberg_paragraph_block_with_non_empty_p_is_preserved(): void {
		// Bloc Gutenberg avec un <p> non-vide : rien a faire.
		$html = "<!-- wp:paragraph -->\n<p>texte</p>\n<!-- /wp:paragraph -->";
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}

	public function test_two_adjacent_empty_gutenberg_paragraph_blocks_both_removed(): void {
		$html = "<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->"
			. "<!-- wp:paragraph --><p>&nbsp;</p><!-- /wp:paragraph -->";
		$this->assertSame( '', trim( $this->rule->apply( $html ) ) );
	}

	public function test_mixed_empty_and_kept_gutenberg_paragraph_blocks(): void {
		$html = "<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->"
			. "<!-- wp:paragraph --><p>OK</p><!-- /wp:paragraph -->";
		// Premier bloc supprime, second preserve.
		$expected = "<!-- wp:paragraph --><p>OK</p><!-- /wp:paragraph -->";
		$this->assertHtmlEquals( $expected, $this->rule->apply( $html ) );
	}

	public function test_bare_empty_p_without_gutenberg_wrapper_still_removed(): void {
		// Comportement historique : un `<p></p>` qui n'est pas encadre par
		// les commentaires Gutenberg est supprime tel quel (sans toucher
		// au contexte autour).
		$html     = '<div><p></p><p>texte</p></div>';
		$expected = '<div><p>texte</p></div>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $html ) );
	}

	public function test_empty_p_inside_other_gutenberg_block_does_not_remove_outer_wrapper(): void {
		// Un `<p></p>` vide a l'interieur d'un bloc Gutenberg qui n'est
		// PAS un `wp:paragraph` (ex. `wp:column`) — on supprime juste le
		// `<p>`, le bloc englobant reste intact.
		$html     = '<!-- wp:column --><div class="wp-block-column"><p></p><p>texte</p></div><!-- /wp:column -->';
		$expected = '<!-- wp:column --><div class="wp-block-column"><p>texte</p></div><!-- /wp:column -->';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $html ) );
	}

	public function test_unmatched_opening_comment_does_not_trigger_full_removal(): void {
		// Commentaire ouvrant present mais pas de fermant correspondant
		// (cas degrade / corrompu) : on suppr juste le `<p>` et on laisse
		// le commentaire orphelin tel quel — pas de tentative de "deviner".
		$html     = '<!-- wp:paragraph --><p></p><div>texte</div>';
		$expected = '<!-- wp:paragraph --><div>texte</div>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $html ) );
	}

	public function test_unmatched_closing_comment_does_not_trigger_full_removal(): void {
		$html     = '<div>texte</div><p></p><!-- /wp:paragraph -->';
		$expected = '<div>texte</div><!-- /wp:paragraph -->';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $html ) );
	}

	public function test_count_matches_consistent_with_apply_on_gutenberg_blocks(): void {
		// Un bloc Gutenberg vide compte pour 1 (= un `<p>` vide), comme
		// avant le fix — la suppression des wrappers est un detail
		// d'implementation, l'unite metier reste « 1 paragraphe vide ».
		$html = "<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->";
		$this->assertSame( 1, $this->rule->countMatches( $html ) );
		// Et bien sur l'application est idempotente.
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}
}
