<?php
/**
 * Tests UnwrapParagraphImageRule (R10).
 *
 * Symétrique de UnwrapHeadingImageRuleTest (R9) appliqué aux `<p>`.
 * Couvre :
 *  - Cas canonique : `<p><img></p>` → `<img>` (article 19087 du corpus MMM).
 *  - Wrappers internes préservés (`<a>`, `<figure>`, `<picture>`).
 *  - Negatives : `<p>` avec texte autour de l'image, `<p>` vide sans img,
 *    `<p>` avec uniquement du texte.
 *  - NBSP traité comme blanc.
 *  - Plusieurs `<p><img></p>` en série.
 *  - countMatches en parallèle de apply (cohérence).
 *  - Idempotence.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\UnwrapParagraphImageRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class UnwrapParagraphImageRuleTest extends TestCase {

	use HtmlAssertions;

	private UnwrapParagraphImageRule $rule;

	protected function setUp(): void {
		$this->rule = new UnwrapParagraphImageRule();
	}

	public function test_id_is_P10(): void {
		$this->assertSame( 'R10', $this->rule->id() );
	}

	public function test_label_is_not_empty(): void {
		$this->assertNotEmpty( $this->rule->label() );
	}

	// =========================================================================
	//  apply — cas canonique
	// =========================================================================

	public function test_unwraps_p_with_just_an_image(): void {
		// Cas issu de l'article 19087 du corpus MMM.
		$input    = '<p><img class="aligncenter size-full wp-image-19036" src="https://example.com/x.jpg" alt="x" width="700" height="485"></p>';
		$expected = '<img class="aligncenter size-full wp-image-19036" src="https://example.com/x.jpg" alt="x" width="700" height="485">';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_unwraps_p_with_image_inside_anchor(): void {
		$input    = '<p><a href="https://example.com/full.jpg"><img src="https://example.com/thumb.jpg" alt="x"></a></p>';
		$expected = '<a href="https://example.com/full.jpg"><img src="https://example.com/thumb.jpg" alt="x"></a>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_unwraps_p_with_image_inside_figure(): void {
		$input    = '<p><figure><img src="x.jpg" alt="x"></figure></p>';
		$expected = '<figure><img src="x.jpg" alt="x"></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_unwraps_p_with_image_inside_picture(): void {
		// DOMDocument re-sérialise `<source>` avec un closing tag explicite —
		// le tag est void en HTML5 mais c'est juste un détail de sortie,
		// sémantiquement identique. On asserte donc sur la forme produite.
		$input    = '<p><picture><source srcset="x.webp"><img src="x.jpg" alt="x"></picture></p>';
		$expected = '<picture><source srcset="x.webp"><img src="x.jpg" alt="x"></source></picture>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_unwraps_p_with_nbsp_around_image(): void {
		// NBSP autour du `<img>` : le matching de "pseudo-vide" les ignore
		// (traités comme blancs), donc le `<p>` est bien désencapsulé. En
		// revanche l'unwrap **préserve tous les enfants** y compris les
		// NBSP — cohérent avec R9. Le cleanup éventuel des NBSP orphelins
		// est hors scope de cette règle (à la charge d'une règle dédiée ou
		// d'un nettoyage manuel post-pipeline).
		$input    = '<p>&nbsp;<img src="x.jpg" alt="x">&nbsp;</p>';
		$expected = '&nbsp;<img src="x.jpg" alt="x">&nbsp;';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — negatives (cas préservés)
	// =========================================================================

	public function test_preserves_p_with_text_around_image(): void {
		// Un `<p>` qui a du texte autour de l'image est une légende
		// légitime — pas désencapsulé.
		$input = '<p>Avant <img src="x.jpg" alt="x"> après</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_p_with_text_only_no_image(): void {
		$input = '<p>Texte simple sans image.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_empty_p_without_image(): void {
		// `<p>` vide sans image — c'est R1 qui s'en occupe, pas R10.
		$input = '<p></p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_p_with_text_inside_anchor_around_image(): void {
		// `<p><a>texte <img></a></p>` — l'anchor a du texte ET l'image.
		// Le `<p>` n'est pas "pseudo-vide" car textContent contient "texte".
		$input = '<p><a href="x.com">légende <img src="x.jpg" alt="x"></a></p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — cas multiples
	// =========================================================================

	public function test_unwraps_multiple_p_with_images_in_sequence(): void {
		$input    = '<p><img src="a.jpg" alt="a"></p><p><img src="b.jpg" alt="b"></p>';
		$expected = '<img src="a.jpg" alt="a"><img src="b.jpg" alt="b">';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_mixed_paragraphs_only_image_only_unwrapped(): void {
		$input    = '<p>texte 1</p><p><img src="x.jpg" alt="x"></p><p>texte 2</p>';
		$expected = '<p>texte 1</p><img src="x.jpg" alt="x"><p>texte 2</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — edge cases
	// =========================================================================

	public function test_does_not_unwrap_heading_with_image(): void {
		// R10 ne traite que les `<p>` — les `<h*>` sont la responsabilité de R9.
		$input = '<h2><img src="x.jpg" alt="x"></h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_does_not_unwrap_div_with_image(): void {
		// `<div>` ≠ `<p>` ; R10 limite stricte.
		$input = '<div><img src="x.jpg" alt="x"></div>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
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

	public function test_count_matches_counts_only_pseudo_empty_p_with_image(): void {
		$html = '<p>texte</p><p><img src="x.jpg" alt="x"></p><p><img src="y.jpg" alt="y"></p><p>autre</p>';
		$this->assertSame( 2, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_does_not_count_p_with_caption(): void {
		$html = '<p>Légende <img src="x.jpg" alt="x"></p>';
		$this->assertSame( 0, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_does_not_count_headings(): void {
		// R9 traite les `<h*>`, pas R10.
		$html = '<h2><img src="x.jpg" alt="x"></h2><p><img src="y.jpg" alt="y"></p>';
		$this->assertSame( 1, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_apply_idempotence(): void {
		$html  = '<p><img src="x.jpg" alt="x"></p>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}
}
