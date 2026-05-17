<?php
/**
 * Tests HeadingPromotionRule (R17).
 *
 * Promotion en cascade des `<h3>`–`<h6>` lorsque le fragment ne
 * contient aucun `<h2>`. Cas typique : un chapô-h2 vient d'être démoté
 * en `<p class="chapo">` par R13, laissant une hiérarchie qui commence
 * à h3.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\HeadingPromotionRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class HeadingPromotionRuleTest extends TestCase {

	use HtmlAssertions;

	private HeadingPromotionRule $rule;

	protected function setUp(): void {
		$this->rule = new HeadingPromotionRule();
	}

	// =========================================================================
	//  Métadonnées
	// =========================================================================

	public function test_id_is_R17(): void {
		$this->assertSame( 'R17', $this->rule->id() );
	}

	public function test_label_is_not_empty(): void {
		$this->assertNotEmpty( $this->rule->label() );
	}

	// =========================================================================
	//  apply — cas canoniques (déclenchement)
	// =========================================================================

	public function test_promotes_single_h3_to_h2_when_no_h2(): void {
		$input    = '<p class="chapo">Chapô.</p><h3>Section</h3><p>Texte.</p>';
		$expected = '<p class="chapo">Chapô.</p><h2>Section</h2><p>Texte.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_promotes_multiple_h3_to_h2(): void {
		// Cas type article 374 (post R13) : N h3 deviennent N h2.
		$input    = '<p class="chapo">Chapô.</p>'
			. '<h3>Première</h3><p>A.</p>'
			. '<h3>Deuxième</h3><p>B.</p>'
			. '<h3>Troisième</h3><p>C.</p>';
		$expected = '<p class="chapo">Chapô.</p>'
			. '<h2>Première</h2><p>A.</p>'
			. '<h2>Deuxième</h2><p>B.</p>'
			. '<h2>Troisième</h2><p>C.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_promotes_cascade_h6_to_h5_h5_to_h4_h4_to_h3_h3_to_h2(): void {
		// Hiérarchie complète qui commence à h3 → tout monte d'un cran.
		$input    = '<h3>Section</h3>'
			. '<h4>Sous-section</h4>'
			. '<h5>Sous-sous</h5>'
			. '<h6>Plus bas</h6>';
		$expected = '<h2>Section</h2>'
			. '<h3>Sous-section</h3>'
			. '<h4>Sous-sous</h4>'
			. '<h5>Plus bas</h5>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_preserves_attributes_on_promoted_heading(): void {
		// id, class, style restent sur la balise renommée.
		$input    = '<h3 id="ancre-1" class="legacy" style="text-align:center">Sous-titre</h3>';
		$expected = '<h2 id="ancre-1" class="legacy" style="text-align:center">Sous-titre</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_preserves_inline_children_on_promoted_heading(): void {
		// strong/em/a/br à l'intérieur sont préservés tels quels.
		$input    = '<h3>Titre <strong>fort</strong> avec <a href="/x">lien</a> et <em>accent</em>.</h3>';
		$expected = '<h2>Titre <strong>fort</strong> avec <a href="/x">lien</a> et <em>accent</em>.</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — cas non-déclenchement (préservation)
	// =========================================================================

	public function test_preserves_when_h2_present(): void {
		// Au moins un h2 → R17 ne s'applique pas, même si beaucoup de h3.
		$input = '<h2>Section</h2><h3>Sous</h3><h3>Sous</h3>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_first_h2_then_h3(): void {
		// Cas article 374 SANS R13 préalable (le chapô h2 est toujours là).
		// R17 ne doit pas tirer tant que le h2 existe.
		$input = '<h2>Chapô non démoté.</h2><h3>Section</h3>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_no_h3(): void {
		// Pas de h3 → rien à promouvoir, même si des h4/h5/h6 existent
		// isolément. La règle exige h3 comme déclencheur (cf. doc).
		$input = '<p>Texte.</p><h4>Légende</h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_only_h2(): void {
		$input = '<h2>Section A.</h2><p>x</p><h2>Section B.</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_no_heading(): void {
		$input = '<p>Texte simple.</p><p>Suite.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h1(): void {
		// h1 n'est jamais promu (titre d'article, hors scope).
		$input    = '<h1>Titre.</h1><h3>Section</h3>';
		$expected = '<h1>Titre.</h1><h2>Section</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	public function test_whitespace_only_input_returns_unchanged(): void {
		$this->assertSame( '   ', $this->rule->apply( '   ' ) );
	}

	// =========================================================================
	//  apply — idempotence
	// =========================================================================

	public function test_apply_is_idempotent(): void {
		// Après une promotion, un h2 existe → le 2e apply est no-op.
		$html  = '<h3>Section A</h3><h3>Section B</h3>';
		$once  = $this->rule->apply( $html );
		$twice = $this->rule->apply( $once );
		$this->assertHtmlEquals( $once, $twice );
	}

	// =========================================================================
	//  countMatches
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_zero_when_h2_present(): void {
		$html = '<h2>Section</h2><h3>Sous</h3>';
		$this->assertSame( 0, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_zero_when_no_h3(): void {
		$html = '<p>Texte.</p>';
		$this->assertSame( 0, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_total_promotable_headings(): void {
		// 3 h3 + 2 h4 + 1 h5 + 1 h6 = 7 promotions à venir.
		$html = '<h3>a</h3><h3>b</h3><h3>c</h3>'
			. '<h4>d</h4><h4>e</h4>'
			. '<h5>f</h5>'
			. '<h6>g</h6>';
		$this->assertSame( 7, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_three_h3_only(): void {
		// 3 h3, pas d'autres niveaux promouvables → 3.
		$html = '<h3>a</h3><p>x</p><h3>b</h3><p>y</p><h3>c</h3>';
		$this->assertSame( 3, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_idempotence(): void {
		// Après apply(), countMatches() doit retourner 0 (h3→h2 créé un h2,
		// donc la condition « aucun h2 » ne tient plus).
		$html  = '<h3>Section</h3><h4>Sous</h4>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}

	// =========================================================================
	//  Composition pipeline — cas réel article 374 (R13 puis R17)
	// =========================================================================

	public function test_typical_article_374_pattern_after_R13(): void {
		// Reproduit l'état post-R13 d'un article 374-like : le chapô-h2
		// initial a été démoté en p.chapo, les 11 h3 restent et doivent
		// devenir 11 h2 après R17.
		$input  = '<p class="chapo">Envie de plus de surface habitable ? Pas envie d\'empiéter sur le jardin ?</p>'
			. '<h3>Le projet</h3><p>Texte 1.</p>'
			. '<h3>La conception</h3><p>Texte 2.</p>'
			. '<h3>Les matériaux</h3><p>Texte 3.</p>'
			. '<h3>Le résultat</h3><p>Texte 4.</p>';
		$output = $this->rule->apply( $input );
		// 0 h3 restants, 4 h2 nouvellement présents, p.chapo préservé.
		$this->assertStringNotContainsString( '<h3>', $output );
		$this->assertSame( 4, substr_count( $output, '<h2>' ) );
		$this->assertStringContainsString( '<p class="chapo">', $output );
	}
}
