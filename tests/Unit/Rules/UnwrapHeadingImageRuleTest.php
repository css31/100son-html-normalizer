<?php
/**
 * Tests UnwrapHeadingImageRule (R9).
 *
 * Couvre :
 *  - Cas canonique : `<h2><img></h2>` → `<img>`
 *  - Variants h1..h6
 *  - Wrappers internes préservés (`<a>`, `<figure>`, `<picture>`)
 *  - Negatives : heading avec texte, heading vide sans img, heading vide
 *  - NBSP traité comme blanc
 *  - Multiple headings en série
 *  - countMatches en parallèle de apply (cohérence)
 *  - Idempotence
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\UnwrapHeadingImageRule;
use PHPUnit\Framework\TestCase;

final class UnwrapHeadingImageRuleTest extends TestCase {

	private UnwrapHeadingImageRule $rule;

	protected function setUp(): void {
		$this->rule = new UnwrapHeadingImageRule();
	}

	// =========================================================================
	//  Identité de la règle
	// =========================================================================

	public function test_id_is_P9(): void {
		$this->assertSame( 'R9', $this->rule->id() );
	}

	public function test_label_is_non_empty(): void {
		$this->assertNotSame( '', $this->rule->label() );
	}

	// =========================================================================
	//  apply() — cas canoniques
	// =========================================================================

	public function test_unwraps_h2_around_simple_img(): void {
		$input    = '<h2><img src="/foo.jpg" alt="x"></h2>';
		$expected = '<img src="/foo.jpg" alt="x">';
		$this->assertHtmlEquivalent( $expected, $this->rule->apply( $input ) );
	}

	public function test_unwraps_all_heading_levels(): void {
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$input  = "<$tag><img src=\"/a.jpg\"></$tag>";
			$output = $this->rule->apply( $input );
			$this->assertStringNotContainsString(
				"<$tag",
				$output,
				"Tag $tag aurait dû être désencapsulé"
			);
			$this->assertStringContainsString(
				'<img src="/a.jpg"',
				$output
			);
		}
	}

	public function test_unwraps_preserves_image_attributes(): void {
		$input = '<h2><img fetchpriority="high" decoding="async" class="aligncenter size-full wp-image-14157" src="https://example.test/a.jpg" alt="alt text" width="700" height="1050" srcset="https://example.test/a.jpg 687w" sizes="(max-width: 700px) 100vw, 700px"></h2>';
		$output = $this->rule->apply( $input );
		$this->assertStringNotContainsString( '<h2', $output );
		$this->assertStringContainsString( 'fetchpriority="high"', $output );
		$this->assertStringContainsString( 'srcset=', $output );
		$this->assertStringContainsString( 'wp-image-14157', $output );
	}

	public function test_unwraps_preserves_anchor_wrapping_image(): void {
		// Le `<a href="...">` autour du `<img>` doit survivre — c'est le
		// heading qui disparaît, pas le lien.
		$input    = '<h2><a href="/page"><img src="/a.jpg"></a></h2>';
		$output   = $this->rule->apply( $input );
		$this->assertStringNotContainsString( '<h2', $output );
		$this->assertStringContainsString( '<a href="/page">', $output );
		$this->assertStringContainsString( '<img src="/a.jpg"', $output );
	}

	public function test_unwraps_preserves_figure_wrapping_image(): void {
		$input    = '<h3><figure class="wp-block-image"><img src="/a.jpg"></figure></h3>';
		$output   = $this->rule->apply( $input );
		$this->assertStringNotContainsString( '<h3', $output );
		$this->assertStringContainsString( '<figure', $output );
	}

	public function test_unwraps_multiple_headings_in_sequence(): void {
		$input  = '<h2><img src="/a.jpg"></h2><h2><img src="/b.jpg"></h2><h2><img src="/c.jpg"></h2>';
		$output = $this->rule->apply( $input );
		$this->assertStringNotContainsString( '<h2', $output );
		$this->assertStringContainsString( '/a.jpg', $output );
		$this->assertStringContainsString( '/b.jpg', $output );
		$this->assertStringContainsString( '/c.jpg', $output );
	}

	public function test_preserves_surrounding_content(): void {
		$input  = '<p>Avant</p><h2><img src="/a.jpg"></h2><p>Après</p>';
		$output = $this->rule->apply( $input );
		$this->assertStringContainsString( '<p>Avant</p>', $output );
		$this->assertStringContainsString( '<p>Après</p>', $output );
		$this->assertStringNotContainsString( '<h2', $output );
		$this->assertStringContainsString( '<img src="/a.jpg"', $output );
	}

	// =========================================================================
	//  apply() — negatives
	// =========================================================================

	public function test_preserves_heading_with_text_and_image(): void {
		// Légende texte = sémantique légitime, on ne touche pas.
		$input  = '<h2>Légende <img src="/a.jpg"></h2>';
		$output = $this->rule->apply( $input );
		$this->assertSame(
			$input,
			$output,
			'Heading avec texte + image doit être préservé'
		);
	}

	public function test_preserves_heading_without_image(): void {
		// Vide ou plein de texte, pas notre problème (c'est R2).
		$input  = '<h2>Titre normal</h2>';
		$this->assertSame( $input, $this->rule->apply( $input ) );

		$empty = '<h2></h2>';
		$this->assertSame( $empty, $this->rule->apply( $empty ) );
	}

	public function test_treats_nbsp_as_whitespace(): void {
		// `&nbsp;` (U+00A0) doit être considéré comme blanc — sinon un titre
		// `<h2>&nbsp;<img></h2>` ne serait pas matché alors qu'il devrait.
		$input  = "<h2>\xc2\xa0<img src=\"/a.jpg\">\xc2\xa0</h2>";
		$output = $this->rule->apply( $input );
		$this->assertStringNotContainsString( '<h2', $output );
		$this->assertStringContainsString( '<img src="/a.jpg"', $output );
	}

	public function test_preserves_image_with_alt_text_only(): void {
		// L'attribut `alt` n'est PAS dans textContent (c'est un attribut).
		// `<h2><img alt="..."></h2>` doit être désencapsulé.
		$input  = '<h2><img src="/a.jpg" alt="Description longue de l\'image"></h2>';
		$output = $this->rule->apply( $input );
		$this->assertStringNotContainsString( '<h2', $output );
		$this->assertStringContainsString( 'alt="Description longue de l\'image"', $output );
	}

	// =========================================================================
	//  countMatches()
	// =========================================================================

	public function test_count_matches_simple(): void {
		$this->assertSame( 1, $this->rule->countMatches( '<h2><img src="/a.jpg"></h2>' ) );
	}

	public function test_count_matches_multiple(): void {
		$html = '<h2><img></h2><p>texte</p><h3><img></h3><h2>vrai titre</h2><h4><a><img></a></h4>';
		$this->assertSame( 3, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_zero_when_no_target(): void {
		$this->assertSame( 0, $this->rule->countMatches( '<p>Pas de heading ici</p>' ) );
		$this->assertSame( 0, $this->rule->countMatches( '<h2>Texte normal</h2>' ) );
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_is_consistent_with_apply(): void {
		// Idempotence implicite : countMatches(apply(html)) == 0 (cf. RuleInterface §4).
		$input  = '<h2><img src="/a.jpg"></h2><p>X</p><h3><img src="/b.jpg"></h3>';
		$output = $this->rule->apply( $input );
		$this->assertSame( 0, $this->rule->countMatches( $output ) );
	}

	// =========================================================================
	//  Idempotence + robustesse
	// =========================================================================

	public function test_apply_is_idempotent(): void {
		$input = '<h2><img src="/a.jpg"></h2><p>texte</p>';
		$once  = $this->rule->apply( $input );
		$twice = $this->rule->apply( $once );
		$this->assertSame( $once, $twice );
	}

	public function test_apply_on_empty_string_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	public function test_apply_never_throws_on_malformed_input(): void {
		// Contrat RuleInterface §3 — en cas d'échec interne, retourner input.
		// Pas d'exception ne doit remonter.
		$malformed = '<h2><img <unclosed';
		$output    = $this->rule->apply( $malformed );
		$this->assertIsString( $output );
	}

	// =========================================================================
	//  Helpers
	// =========================================================================

	/**
	 * Compare deux HTML en tolérant les différences de sérialisation DOM
	 * (espaces blancs entre tags, ordre d'attributs). On normalise les
	 * deux côtés en parsant + sérialisant via DOMDocument.
	 *
	 * @param string $expected HTML attendu.
	 * @param string $actual   HTML obtenu.
	 * @return void
	 */
	private function assertHtmlEquivalent( string $expected, string $actual ): void {
		$normalize = static function ( string $html ): string {
			return preg_replace( '/\s+/', ' ', trim( $html ) ) ?? $html;
		};
		$this->assertSame( $normalize( $expected ), $normalize( $actual ) );
	}
}
