<?php
/**
 * Tests H2ChapoToParagraphRule (R13).
 *
 * Promotion du premier <h2> du fragment en <p class="chapo"> quand il
 * porte une phrase-chapô (≥ 5 mots + ponctuation). Conservative :
 * seul le premier h2 du document est candidat — les h2 ultérieurs
 * restent intacts.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\H2ChapoToParagraphRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class H2ChapoToParagraphRuleTest extends TestCase {

	use HtmlAssertions;

	private H2ChapoToParagraphRule $rule;

	protected function setUp(): void {
		$this->rule = new H2ChapoToParagraphRule();
	}

	// =========================================================================
	//  Métadonnées
	// =========================================================================

	public function test_id_is_R13(): void {
		$this->assertSame( 'R13', $this->rule->id() );
	}

	public function test_label_is_not_empty(): void {
		$this->assertNotEmpty( $this->rule->label() );
	}

	// =========================================================================
	//  apply — cas canoniques
	// =========================================================================

	public function test_promotes_basic_chapo(): void {
		$input    = '<h2>Il est rare de rénover sa maison en une seule fois.</h2><p>Suite de l\'article.</p>';
		$expected = '<p class="chapo">Il est rare de rénover sa maison en une seule fois.</p><p>Suite de l\'article.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_promotes_multi_sentence_chapo(): void {
		$input    = '<h2>Première phrase du chapô. Deuxième phrase qui complète. Troisième !</h2><p>Suite.</p>';
		$expected = '<p class="chapo">Première phrase du chapô. Deuxième phrase qui complète. Troisième !</p><p>Suite.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_promotes_chapo_with_question_mark(): void {
		$input    = '<h2>Envie de plus de surface habitable ? Pas envie d\'empiéter sur le jardin ?</h2>';
		$expected = '<p class="chapo">Envie de plus de surface habitable ? Pas envie d\'empiéter sur le jardin ?</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_promotes_chapo_strips_inline_tags_keeps_link(): void {
		// Strong/em unwrappés (texte conservé), <a> préservé intact.
		$input    = '<h2>Découvrez le <strong>chantier en vidéos</strong> de la surélévation d\'une maison <em>réalisée à Toulouse</em>.</h2>';
		$expected = '<p class="chapo">Découvrez le chantier en vidéos de la surélévation d\'une maison réalisée à Toulouse.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_promotes_chapo_with_link(): void {
		$input    = '<h2>Voir le détail sur <a href="https://example.test">notre dossier complet</a> pour aller plus loin.</h2>';
		$expected = '<p class="chapo">Voir le détail sur <a href="https://example.test">notre dossier complet</a> pour aller plus loin.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_promotes_chapo_strips_br(): void {
		// <br> retiré entièrement (formatage visuel, pas de contenu).
		$input    = '<h2>Première ligne du chapô.<br>Deuxième ligne complémentaire.</h2>';
		$expected = '<p class="chapo">Première ligne du chapô.Deuxième ligne complémentaire.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_drops_h2_attributes(): void {
		// Les attributs du h2 (style, id, class) sont abandonnés.
		// Le <p> produit n'a que class="chapo".
		$input    = '<h2 style="text-align:center" id="lead" class="legacy">Phrase de chapô assez longue pour matcher.</h2>';
		$expected = '<p class="chapo">Phrase de chapô assez longue pour matcher.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — only first h2 matches
	// =========================================================================

	public function test_promotes_only_first_h2_when_others_present(): void {
		// Le premier h2 chapô est promu, les autres h2 restent.
		$input    = '<h2>Première phrase du chapô qui ouvre l\'article.</h2>'
			. '<p>Texte intermédiaire.</p>'
			. '<h2>Section 1.</h2>'
			. '<p>Contenu.</p>'
			. '<h2>Section 2.</h2>';
		$expected = '<p class="chapo">Première phrase du chapô qui ouvre l\'article.</p>'
			. '<p>Texte intermédiaire.</p>'
			. '<h2>Section 1.</h2>'
			. '<p>Contenu.</p>'
			. '<h2>Section 2.</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_preserves_first_h2_when_not_chapo_skips_others(): void {
		// Si le premier h2 n'est PAS chapô (court titre), on renonce
		// sans aller chercher plus loin.
		$input = '<h2>Titre court.</h2><h2>Phrase plus longue avec ponctuation qui ressemble à un chapô.</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — negatives (cas préservés tels quels)
	// =========================================================================

	public function test_preserves_short_h2(): void {
		// < 5 mots → pas chapô.
		$input = '<h2>Surélévation à Toulouse.</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h2_without_punctuation(): void {
		// Pas de ponctuation `.`/`!`/`?` → pas chapô (titre de section).
		$input = '<h2>Rénovation d\'une maison de campagne en région toulousaine</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_empty_h2(): void {
		$input = '<h2></h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_whitespace_only_h2(): void {
		$input = '<h2>&nbsp; </h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h1_h3_h4_h5_h6(): void {
		$input = '<h1>Phrase de chapô longue avec ponctuation.</h1>'
			. '<h3>Phrase de chapô longue avec ponctuation.</h3>'
			. '<h4>Phrase de chapô longue avec ponctuation.</h4>'
			. '<h5>Phrase de chapô longue avec ponctuation.</h5>'
			. '<h6>Phrase de chapô longue avec ponctuation.</h6>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_p_phrase_at_start(): void {
		// Un <p> en tête (même phrase) n'est pas touché par R13.
		$input = '<p>Première phrase de l\'article qui pourrait être un chapô.</p><h2>Section.</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_chapo_only_4_words(): void {
		// Exactement 4 mots avec ponctuation → sous le seuil 5.
		$input = '<h2>Trois mots et fin.</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_promotes_chapo_exactly_5_words(): void {
		// Seuil inclusif à 5 mots.
		$input    = '<h2>Cinq mots tout pile fin.</h2>';
		$expected = '<p class="chapo">Cinq mots tout pile fin.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	// =========================================================================
	//  countMatches
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_one_when_chapo_present(): void {
		$html = '<h2>Phrase de chapô qui ouvre l\'article correctement.</h2><h2>Section ultérieure.</h2>';
		$this->assertSame( 1, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_zero_when_no_chapo(): void {
		$html = '<h2>Titre court.</h2><p>Texte.</p>';
		$this->assertSame( 0, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_idempotence(): void {
		$html  = '<h2>Phrase de chapô assez longue pour matcher.</h2>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}

	public function test_apply_is_idempotent(): void {
		$html  = '<h2>Phrase de chapô assez longue pour matcher.</h2>';
		$once  = $this->rule->apply( $html );
		$twice = $this->rule->apply( $once );
		$this->assertHtmlEquals( $once, $twice );
	}

	// =========================================================================
	//  Fixture intégrale (corpus MMM-2)
	// =========================================================================

	public function test_full_fixture_matches_expected(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/h2-chapo-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/h2-chapo-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}
}
