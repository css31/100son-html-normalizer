<?php
/**
 * Tests FirstParagraphChapoRule (R14).
 *
 * Complément de R13 : ajoute `class="chapo"` au premier <p> phrase
 * du fragment, à condition qu'il soit le premier élément significatif.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Rules\BuilderScopedRule;
use Cent_Son\Html_Normalizer\Core\Rules\FirstParagraphChapoRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class FirstParagraphChapoRuleTest extends TestCase {

	use HtmlAssertions;

	private FirstParagraphChapoRule $rule;

	protected function setUp(): void {
		$this->rule = new FirstParagraphChapoRule();
	}

	// =========================================================================
	//  Métadonnées
	// =========================================================================

	public function test_id_is_R14(): void {
		$this->assertSame( 'R14', $this->rule->id() );
	}

	public function test_label_is_not_empty(): void {
		$this->assertNotEmpty( $this->rule->label() );
	}

	public function test_is_builder_scoped_and_excludes_gutenberg(): void {
		$this->assertInstanceOf( BuilderScopedRule::class, $this->rule );
		$this->assertSame( array( BuilderClassifier::TYPE_GUTENBERG ), $this->rule->excluded_builder_types() );
	}

	// =========================================================================
	//  apply — cas canoniques
	// =========================================================================

	public function test_marks_first_paragraph_chapo(): void {
		$input    = '<p>Une famille s\'est lancée dans la rénovation écologique de sa maison.</p><p>Suite.</p>';
		$expected = '<p class="chapo">Une famille s\'est lancée dans la rénovation écologique de sa maison.</p><p>Suite.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_marks_multi_sentence_chapo(): void {
		$input    = '<p>Première phrase du chapô. Deuxième phrase. Troisième !</p><p>Suite.</p>';
		$expected = '<p class="chapo">Première phrase du chapô. Deuxième phrase. Troisième !</p><p>Suite.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_inline_formatting_keeps_links(): void {
		// Toute mise en forme inline supprimée sauf <a>.
		$input    = '<p>Texte <strong>en gras</strong> et <em>en italique</em> et <a href="#">un lien</a>.</p>';
		$expected = '<p class="chapo">Texte en gras et en italique et <a href="#">un lien</a>.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_span_keeps_text(): void {
		$input    = '<p><span style="font-size:12pt;">Phrase de chapô assez longue avec ponctuation.</span></p>';
		$expected = '<p class="chapo">Phrase de chapô assez longue avec ponctuation.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_nested_inline_tags(): void {
		$input    = '<p><span><em><strong>Phrase</strong> de chapô</em> assez longue</span> avec ponctuation.</p>';
		$expected = '<p class="chapo">Phrase de chapô assez longue avec ponctuation.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_br_in_chapo(): void {
		$input    = '<p>Phrase de chapô.<br>Deuxième ligne avec ponctuation finale.</p>';
		$expected = '<p class="chapo">Phrase de chapô.Deuxième ligne avec ponctuation finale.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_styled_a_keeps_link(): void {
		// <a> à l'intérieur d'un <span style="..."> : le span saute,
		// le <a> reste avec ses attributs.
		$input    = '<p><span style="color:red"><a href="https://x.test" target="_blank">lien préservé</a></span> et texte de chapô assez long.</p>';
		$expected = '<p class="chapo"><a href="https://x.test" target="_blank">lien préservé</a> et texte de chapô assez long.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_inline_inside_link(): void {
		// <a><strong>texte</strong></a> → <a>texte</a>.
		$input    = '<p><a href="#"><strong>lien gras</strong> et autres</a> mots du chapô assez longs.</p>';
		$expected = '<p class="chapo"><a href="#">lien gras et autres</a> mots du chapô assez longs.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_resets_p_class_to_chapo_only(): void {
		// Classes existantes droppées (sauf chapo si déjà présent),
		// résultat normalisé à class="chapo".
		$input    = '<p class="legacy text-large another">Phrase de chapô assez longue avec ponctuation.</p>';
		$expected = '<p class="chapo">Phrase de chapô assez longue avec ponctuation.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_drops_p_attributes_other_than_class(): void {
		// style, id, data-* sont droppés.
		$input    = '<p id="lead" style="text-align:center" data-foo="bar">Phrase de chapô longue avec ponctuation.</p>';
		$expected = '<p class="chapo">Phrase de chapô longue avec ponctuation.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_skips_leading_empty_paragraphs(): void {
		// SiteOrigin produit souvent des <p>&nbsp;</p> en tête.
		$input    = '<p>&nbsp;</p><p> </p><p>Vraie phrase de chapô longue avec ponctuation.</p>';
		$expected = '<p>&nbsp;</p><p> </p><p class="chapo">Vraie phrase de chapô longue avec ponctuation.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_skips_leading_whitespace_text_nodes(): void {
		$input    = "   \n\n<p>Phrase de chapô longue avec ponctuation.</p>";
		$actual   = $this->rule->apply( $input );
		$this->assertStringContainsString( '<p class="chapo">', $actual );
	}

	public function test_skips_leading_comments(): void {
		$input    = '<!-- header --><p>Phrase de chapô longue avec ponctuation.</p>';
		$actual   = $this->rule->apply( $input );
		$this->assertStringContainsString( '<p class="chapo">', $actual );
	}

	// =========================================================================
	//  apply — idempotence
	// =========================================================================

	public function test_idempotent_when_already_chapo(): void {
		// R14 ne re-marque pas un <p> qui a déjà la classe.
		$input = '<p class="chapo">Phrase de chapô longue avec ponctuation.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_idempotent_when_chapo_among_other_classes(): void {
		// Le clean réduit class à "chapo" seul, donc relancer sur la
		// sortie est idempotent (résultat = même p class="chapo").
		$input    = '<p class="legacy chapo other">Phrase longue avec ponctuation.</p>';
		$expected = '<p class="chapo">Phrase longue avec ponctuation.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_apply_is_idempotent(): void {
		$html  = '<p>Phrase de chapô assez longue pour matcher.</p>';
		$once  = $this->rule->apply( $html );
		$twice = $this->rule->apply( $once );
		$this->assertHtmlEquals( $once, $twice );
	}

	// =========================================================================
	//  apply — only first paragraph touched
	// =========================================================================

	public function test_marks_only_first_p_not_second(): void {
		$input    = '<p>Premier paragraphe chapô assez long avec ponctuation.</p>'
			. '<p>Deuxième paragraphe corps également long avec ponctuation.</p>';
		$expected = '<p class="chapo">Premier paragraphe chapô assez long avec ponctuation.</p>'
			. '<p>Deuxième paragraphe corps également long avec ponctuation.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — negatives (cas préservés tels quels)
	// =========================================================================

	public function test_preserves_short_first_p(): void {
		// < 5 mots → pas chapô.
		$input = '<p>Trois mots seulement.</p><p>Corps.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_first_p_without_punctuation(): void {
		// Pas de ponctuation `.`/`!`/`?` → pas chapô.
		$input = '<p>Suite de mots sans aucune ponctuation finale dans tout le segment</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_first_element_is_h2(): void {
		// Si le premier élément n'est pas un <p> (ici un h2 court de
		// section), R14 abandonne — le <p> qui suit n'est pas un chapô.
		$input = '<h2>Section</h2><p>Phrase qui ressemble à un chapô mais c\'est juste le corps.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_first_element_is_image(): void {
		$input = '<figure><img src="x.jpg" alt=""></figure><p>Phrase qui ressemble à un chapô longue.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_first_element_is_list(): void {
		$input = '<ul><li>item 1</li></ul><p>Phrase qui ressemble à un chapô longue.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_non_blank_text_before_p(): void {
		$input = 'Texte nu sans wrapper. <p>Phrase qui ressemble à un chapô longue.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_exactly_4_words(): void {
		// Sous le seuil 5.
		$input = '<p>Quatre mots avec point.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_marks_exactly_5_words(): void {
		// Seuil inclusif à 5.
		$input    = '<p>Cinq mots tout pile fin.</p>';
		$expected = '<p class="chapo">Cinq mots tout pile fin.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	public function test_no_paragraph_at_all(): void {
		$input = '<h2>Titre.</h2><h3>Sous-titre.</h3>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — wrappers transparents (cas SiteOrigin)
	// =========================================================================

	public function test_marks_chapo_inside_simple_div_wrapper(): void {
		// Cas le plus simple : un wrapper <div> autour du chapô.
		$input    = '<div class="container"><p>Phrase de chapô assez longue avec ponctuation.</p></div>';
		$expected = '<div class="container"><p class="chapo">Phrase de chapô assez longue avec ponctuation.</p></div>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_marks_chapo_inside_nested_siteorigin_panel_divs(): void {
		// Cas typique d'un article SiteOrigin avec panel-layout :
		// 5 niveaux de <div> imbriqués avant le chapô.
		$input = '<div id="pl-491" class="panel-layout">'
			. '<div class="panel-grid panel-no-style">'
			. '<div class="panel-grid-cell">'
			. '<div class="so-panel widget widget_sow-editor">'
			. '<div class="panel-widget-style">'
			. '<div class="so-widget-sow-editor so-widget-sow-editor-base">'
			. '<div class="siteorigin-widget-tinymce textwidget">'
			. '<p>Basée sur la région toulousaine, Laetitia Moreau décline le verre en aménagement intérieur.</p>'
			. '<p>Suite de l\'article.</p>'
			. '</div></div></div></div></div></div></div>';
		$actual = $this->rule->apply( $input );
		$this->assertStringContainsString(
			'<p class="chapo">Basée sur la région toulousaine, Laetitia Moreau décline le verre en aménagement intérieur.</p>',
			$actual
		);
		$this->assertStringContainsString( '<p>Suite de l\'article.</p>', $actual );
	}

	public function test_marks_chapo_inside_section_wrapper(): void {
		// Wrapper HTML5 <section>.
		$input    = '<section><p>Phrase de chapô assez longue avec ponctuation.</p></section>';
		$expected = '<section><p class="chapo">Phrase de chapô assez longue avec ponctuation.</p></section>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_marks_chapo_after_empty_wrapper(): void {
		// Wrapper précédent vide → continue dans le sibling suivant.
		$input    = '<div></div><p>Phrase de chapô assez longue avec ponctuation.</p>';
		$expected = '<div></div><p class="chapo">Phrase de chapô assez longue avec ponctuation.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_heading_inside_first_wrapper(): void {
		// Wrapper avec un heading non-vide en premier → propagation
		// de l'abandon, le <p> qui suit n'est pas marqué.
		$input = '<div><h2>Section.</h2></div><p>Phrase qui ressemble à un chapô.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_aside_contains_p(): void {
		// <aside> N'EST PAS un wrapper transparent : c'est du contenu
		// secondaire, pas le chapô.
		$input = '<aside><p>Phrase aside longue avec ponctuation.</p></aside><p>Vraie phrase de chapô longue.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — extension du chapô aux paragraphes de crédits
	// =========================================================================

	public function test_marks_la_redaction_credit_after_chapo(): void {
		// MMM : chapô + LA RÉDACTION en signature.
		$input    = '<p>Phrase de chapô assez longue avec ponctuation.</p><p>LA RÉDACTION</p><p>Premier paragraphe du corps.</p>';
		$expected = '<p class="chapo">Phrase de chapô assez longue avec ponctuation.</p><p class="chapo">LA RÉDACTION</p><p>Premier paragraphe du corps.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_marks_photos_credit_after_chapo(): void {
		// MMM : chapô + PHOTOS Cyrille Martin.
		$input    = '<p>Phrase de chapô assez longue avec ponctuation.</p><p>PHOTOS Cyrille Martin</p><p>Corps.</p>';
		$expected = '<p class="chapo">Phrase de chapô assez longue avec ponctuation.</p><p class="chapo">PHOTOS Cyrille Martin</p><p>Corps.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_marks_photographe_credit_after_chapo(): void {
		$input    = '<p>Phrase de chapô longue avec ponctuation.</p><p>Photographe : Cyrille Martin</p><p>Corps.</p>';
		$expected = '<p class="chapo">Phrase de chapô longue avec ponctuation.</p><p class="chapo">Photographe : Cyrille Martin</p><p>Corps.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_marks_la_redaction_then_photos_chain(): void {
		// MMM : chapô + LA RÉDACTION + PHOTOS (chaînage 2 crédits).
		$input = '<p>Phrase de chapô longue avec ponctuation.</p>'
			. '<p>LA RÉDACTION</p>'
			. '<p>PHOTOS Cyrille Martin</p>'
			. '<p>Premier vrai paragraphe.</p>';
		$expected = '<p class="chapo">Phrase de chapô longue avec ponctuation.</p>'
			. '<p class="chapo">LA RÉDACTION</p>'
			. '<p class="chapo">PHOTOS Cyrille Martin</p>'
			. '<p>Premier vrai paragraphe.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_marks_short_name_after_chapo(): void {
		// MMM : chapô + nom isolé (« Cyrille Martin »).
		$input    = '<p>Phrase de chapô longue avec ponctuation.</p><p>Cyrille Martin</p><p>Corps de l\'article.</p>';
		$expected = '<p class="chapo">Phrase de chapô longue avec ponctuation.</p><p class="chapo">Cyrille Martin</p><p>Corps de l\'article.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_stops_credits_at_first_body_paragraph(): void {
		// Crédit puis paragraphe corps : on stoppe avant le corps.
		$input    = '<p>Phrase de chapô longue assez avec ponctuation.</p><p>LA RÉDACTION</p><p>Une vraie phrase de corps avec ponctuation.</p><p>LA RÉDACTION</p>';
		$expected = '<p class="chapo">Phrase de chapô longue assez avec ponctuation.</p><p class="chapo">LA RÉDACTION</p><p>Une vraie phrase de corps avec ponctuation.</p><p>LA RÉDACTION</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_stops_at_non_p_element_after_chapo(): void {
		// Heading ou image après chapô = fin du bloc chapô.
		$input    = '<p>Phrase de chapô longue assez avec ponctuation.</p><h2>Section.</h2><p>LA RÉDACTION</p>';
		$expected = '<p class="chapo">Phrase de chapô longue assez avec ponctuation.</p><h2>Section.</h2><p>LA RÉDACTION</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_skips_empty_paragraphs_between_chapo_and_credit(): void {
		// `<p>&nbsp;</p>` intercalaire entre chapô et crédit ne bloque pas.
		$input    = '<p>Phrase de chapô longue assez avec ponctuation.</p><p>&nbsp;</p><p>LA RÉDACTION</p><p>Corps.</p>';
		$expected = '<p class="chapo">Phrase de chapô longue assez avec ponctuation.</p><p>&nbsp;</p><p class="chapo">LA RÉDACTION</p><p>Corps.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_long_paragraph_with_punctuation_is_not_credit(): void {
		// Un paragraphe long avec point n'est PAS un crédit, donc stop.
		$input = '<p>Phrase de chapô longue assez avec ponctuation.</p><p>Une suite de phrase relativement longue avec une vraie ponctuation finale.</p>';
		$expected = '<p class="chapo">Phrase de chapô longue assez avec ponctuation.</p><p>Une suite de phrase relativement longue avec une vraie ponctuation finale.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_caps_at_max_credit_paragraphs(): void {
		// Si 5 courts paragraphes consécutifs suivent le chapô, seuls
		// les 3 premiers sont marqués (MAX_CREDIT_PARAGRAPHS).
		$input = '<p>Phrase de chapô longue assez avec ponctuation.</p>'
			. '<p>Court 1</p><p>Court 2</p><p>Court 3</p><p>Court 4</p><p>Court 5</p>';
		$actual    = $this->rule->apply( $input );
		// Compte les <p class="chapo">
		$count = preg_match_all( '/class="chapo"/i', $actual );
		$this->assertSame( 4, $count, 'Chapô + 3 crédits = 4 marquages max' );
	}

	public function test_credits_strip_inline_tags(): void {
		// Inlines em/strong dans un crédit court → unwrappés, texte conservé.
		$input    = '<p>Phrase de chapô longue assez avec ponctuation.</p><p><em>Cyrille Martin</em></p><p>Corps.</p>';
		$expected = '<p class="chapo">Phrase de chapô longue assez avec ponctuation.</p><p class="chapo">Cyrille Martin</p><p>Corps.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_extends_when_first_p_already_chapo_marked_by_r13(): void {
		// Cas typique pipeline R13 → R14 : R13 a démoté un h2-chapô en
		// <p class="chapo">. R14 doit étendre aux crédits suivants.
		$input    = '<p class="chapo">Chapô longue déjà marqué par R13.</p><p>LA RÉDACTION</p><p>Corps.</p>';
		$expected = '<p class="chapo">Chapô longue déjà marqué par R13.</p><p class="chapo">LA RÉDACTION</p><p>Corps.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  countMatches
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_one_when_chapo_present(): void {
		$html = '<p>Phrase de chapô assez longue avec ponctuation.</p><p>Suite.</p>';
		$this->assertSame( 1, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_zero_when_already_marked(): void {
		$html = '<p class="chapo">Phrase déjà marquée.</p>';
		$this->assertSame( 0, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_zero_when_first_element_is_h2(): void {
		$html = '<h2>Section.</h2><p>Phrase de chapô longue avec ponctuation.</p>';
		$this->assertSame( 0, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_idempotence(): void {
		$html  = '<p>Phrase de chapô assez longue pour matcher.</p>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}

	// =========================================================================
	//  Fixture intégrale (corpus MMM-2)
	// =========================================================================

	public function test_full_fixture_matches_expected(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/first-p-chapo-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/first-p-chapo-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}
}
