<?php
/**
 * Tests HeadingMixedToFigureRule (R12).
 *
 * Variante inline de R11 : `<h4>image + texte</h4>` → `<figure>image
 * <figcaption>texte</figcaption></figure>`. Mode tolérant multi-images
 * (forme HTML5 normative pour groupe d'images partageant une caption
 * unique).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\HeadingMixedToFigureRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class HeadingMixedToFigureRuleTest extends TestCase {

	use HtmlAssertions;

	private HeadingMixedToFigureRule $rule;

	protected function setUp(): void {
		$this->rule = new HeadingMixedToFigureRule();
	}

	// =========================================================================
	//  Métadonnées
	// =========================================================================

	public function test_id_is_R12(): void {
		$this->assertSame( 'R12', $this->rule->id() );
	}

	public function test_label_is_not_empty(): void {
		$this->assertNotEmpty( $this->rule->label() );
	}

	// =========================================================================
	//  apply — cas canoniques mono-image
	// =========================================================================

	public function test_basic_single_image_then_text(): void {
		$input    = '<h4><img src="x.jpg" alt="x">Légende.</h4>';
		$expected = '<figure><img src="x.jpg" alt="x"><figcaption>Légende.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_image_wrapped_in_anchor_then_text(): void {
		// Cas typique du corpus MMM-2 : <a> lightbox.
		$input    = '<h4><a href="big.jpg"><img src="thumb.jpg" alt="x"></a> Texte de légende.</h4>';
		$expected = '<figure><a href="big.jpg"><img src="thumb.jpg" alt="x"></a><figcaption>Texte de légende.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_br_between_image_and_text_is_dropped(): void {
		// Séparateur visuel parasite : on retire le <br> en bordure.
		$input    = '<h4><a href="big.jpg"><img src="thumb.jpg" alt="x"></a><br>Légende.</h4>';
		$expected = '<figure><a href="big.jpg"><img src="thumb.jpg" alt="x"></a><figcaption>Légende.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_nbsp_between_image_and_text_is_dropped(): void {
		$input  = '<h4><img src="x.jpg" alt="x">&nbsp;&nbsp;Légende.</h4>';
		$actual = $this->rule->apply( $input );
		$this->assertStringContainsString( '<figure>', $actual );
		$this->assertStringContainsString( '<img src="x.jpg" alt="x">', $actual );
		$this->assertStringContainsString( '<figcaption>Légende.</figcaption>', $actual );
		$this->assertStringNotContainsString( '<h4>', $actual );
	}

	public function test_inline_tags_preserved_in_caption(): void {
		$input    = '<h4><img src="x.jpg" alt="x"> Texte <em>italique</em> et <a href="#">lien</a>.</h4>';
		$expected = '<figure><img src="x.jpg" alt="x"><figcaption>Texte <em>italique</em> et <a href="#">lien</a>.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strong_preserved_in_caption(): void {
		$input    = '<h4><img src="x.jpg" alt="x"> Texte <strong>important</strong>.</h4>';
		$expected = '<figure><img src="x.jpg" alt="x"><figcaption>Texte <strong>important</strong>.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_text_before_image_still_captured(): void {
		// L'ordre dans la source n'a pas d'importance : la <figure>
		// regroupe TOUTES les images au début, puis caption.
		$input    = '<h4>Texte avant.<img src="x.jpg" alt="x"></h4>';
		$expected = '<figure><img src="x.jpg" alt="x"><figcaption>Texte avant.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — cas tolérant multi-images
	// =========================================================================

	public function test_two_images_with_shared_caption(): void {
		// Forme HTML5 normative : figure multi-img avec une figcaption.
		$input    = '<h4><a href="a.jpg"><img src="a-thumb.jpg" alt="a"></a> <a href="b.jpg"><img src="b-thumb.jpg" alt="b"></a> Avant et après.</h4>';
		$expected = '<figure><a href="a.jpg"><img src="a-thumb.jpg" alt="a"></a><a href="b.jpg"><img src="b-thumb.jpg" alt="b"></a><figcaption>Avant et après.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_three_images_preserved_in_order(): void {
		$input    = '<h4><img src="1.jpg" alt="1"><img src="2.jpg" alt="2"><img src="3.jpg" alt="3"> Trois images.</h4>';
		$expected = '<figure><img src="1.jpg" alt="1"><img src="2.jpg" alt="2"><img src="3.jpg" alt="3"><figcaption>Trois images.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — negatives (cas préservés tels quels)
	// =========================================================================

	public function test_preserves_h4_without_image(): void {
		// h4 pur texte (vrai sous-titre) — pas touché.
		$input = '<h4>Vrai sous-titre.</h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h4_with_image_only_no_text(): void {
		// h4 contenant uniquement une image (sans texte) : c'est le boulot
		// de R9, pas de R12.
		$input = '<h4><img src="x.jpg" alt="x"></h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h4_with_anchored_image_only_no_text(): void {
		$input = '<h4><a href="big.jpg"><img src="thumb.jpg" alt="x"></a></h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h4_with_image_and_only_br(): void {
		// Texte vide après nettoyage des séparateurs visuels (br/whitespace)
		// → ne pas créer une figcaption vide. C'est un cas R9-like.
		$input = '<h4><img src="x.jpg" alt="x"><br></h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h2_with_image_and_text(): void {
		$input = '<h2><img src="x.jpg" alt="x"> Texte dans h2.</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h3_with_image_and_text(): void {
		$input = '<h3><img src="x.jpg" alt="x"> Texte dans h3.</h3>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h5_with_image_and_text(): void {
		$input = '<h5><img src="x.jpg" alt="x"> Texte dans h5.</h5>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h6_with_image_and_text(): void {
		$input = '<h6><img src="x.jpg" alt="x"> Texte dans h6.</h6>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_paragraph_with_image_and_text(): void {
		// p (pas h4) : R11/R10 s'en occupent dans leurs contextes
		// respectifs ; R12 ne touche pas aux <p>.
		$input = '<p><img src="x.jpg" alt="x"> Texte.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	// =========================================================================
	//  apply — séquences multiples
	// =========================================================================

	public function test_multiple_h4_in_sequence(): void {
		$input    = '<h4><img src="a.jpg" alt="a"> Lég. A.</h4><h4><img src="b.jpg" alt="b"> Lég. B.</h4>';
		$expected = '<figure><img src="a.jpg" alt="a"><figcaption>Lég. A.</figcaption></figure>'
			. '<figure><img src="b.jpg" alt="b"><figcaption>Lég. B.</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_mixed_h4_some_match_some_not(): void {
		$input    = '<h4>Vrai sous-titre.</h4>'
			. '<h4><img src="x.jpg" alt="x"> Légende.</h4>'
			. '<h4><img src="y.jpg" alt="y"></h4>';
		$expected = '<h4>Vrai sous-titre.</h4>'
			. '<figure><img src="x.jpg" alt="x"><figcaption>Légende.</figcaption></figure>'
			. '<h4><img src="y.jpg" alt="y"></h4>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  countMatches
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_counts_mixed_h4_only(): void {
		$html = '<h4>Vrai titre.</h4>'                            // pas matché
			. '<h4><img src="a.jpg" alt="a"> Lég. A.</h4>'         // match
			. '<h4><img src="b.jpg" alt="b"></h4>'                  // pas matché (R9)
			. '<h4><img src="c.jpg" alt="c"><img src="d.jpg" alt="d"> Lég. CD.</h4>' // match (multi)
			. '<h2><img src="e.jpg" alt="e"> Lég. E.</h2>'         // pas matché (h2)
			. '<p><img src="f.jpg" alt="f"> Lég. F.</p>';           // pas matché (p)
		$this->assertSame( 2, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_idempotence(): void {
		$html  = '<h4><img src="x.jpg" alt="x"> Légende.</h4>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}

	public function test_apply_is_idempotent(): void {
		$html  = '<h4><a href="big.jpg"><img src="thumb.jpg" alt="x"></a> Légende.</h4>';
		$once  = $this->rule->apply( $html );
		$twice = $this->rule->apply( $once );
		$this->assertHtmlEquals( $once, $twice );
	}

	// =========================================================================
	//  Fixture intégrale (corpus MMM-2)
	// =========================================================================

	public function test_full_fixture_matches_expected(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/heading-mixed-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/heading-mixed-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}
}
