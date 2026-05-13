<?php
/**
 * Tests P6 — RemoveInlineStylesRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\RemoveInlineStylesRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class RemoveInlineStylesRuleTest extends TestCase {

	use HtmlAssertions;

	public function test_id_and_label(): void {
		$rule = new RemoveInlineStylesRule();
		$this->assertSame( 'P6', $rule->id() );
		$this->assertNotEmpty( $rule->label() );
	}

	// ====== Mode keep_text_align = true (defaut) ====== //

	public function test_keep_align_strips_other_declarations(): void {
		$rule = new RemoveInlineStylesRule( true );
		$this->assertHtmlEquals(
			'<p style="text-align: center;">x</p>',
			$rule->apply( '<p style="text-align: center; color: red;">x</p>' )
		);
	}

	public function test_keep_align_only_text_align_unchanged(): void {
		$rule = new RemoveInlineStylesRule( true );
		$this->assertHtmlEquals(
			'<p style="text-align: left;">x</p>',
			$rule->apply( '<p style="text-align: left;">x</p>' )
		);
	}

	public function test_keep_align_no_text_align_strips_attribute(): void {
		$rule = new RemoveInlineStylesRule( true );
		$this->assertHtmlEquals(
			'<p>x</p>',
			$rule->apply( '<p style="font-weight: bold; color: blue;">x</p>' )
		);
	}

	public function test_keep_align_normalizes_whitespace_around_colon(): void {
		$rule = new RemoveInlineStylesRule( true );
		$this->assertHtmlEquals(
			'<p style="text-align: center;">x</p>',
			$rule->apply( '<p style="text-align:center;color:red">x</p>' )
		);
	}

	public function test_keep_align_case_insensitive_property(): void {
		$rule = new RemoveInlineStylesRule( true );
		$this->assertHtmlEquals(
			'<p style="text-align: CENTER;">x</p>',
			$rule->apply( '<p style="TEXT-ALIGN: CENTER;">x</p>' )
		);
	}

	// ====== Mode keep_text_align = false (strict) ====== //

	public function test_strict_strips_text_align_too(): void {
		$rule = new RemoveInlineStylesRule( false );
		$this->assertHtmlEquals(
			'<p>x</p>',
			$rule->apply( '<p style="text-align: center;">x</p>' )
		);
	}

	public function test_strict_strips_all_styles(): void {
		$rule = new RemoveInlineStylesRule( false );
		$this->assertHtmlEquals(
			'<p>x</p>',
			$rule->apply( '<p style="text-align: center; color: red; font-weight: bold;">x</p>' )
		);
	}

	// ====== Cas communs ====== //

	public function test_element_without_style_unchanged(): void {
		$rule  = new RemoveInlineStylesRule( true );
		$input = '<p>x</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_descendant_styles_also_processed(): void {
		$rule = new RemoveInlineStylesRule( true );
		$this->assertHtmlEquals(
			'<p style="text-align: left;"><span>nested</span></p>',
			$rule->apply( '<p style="text-align: left; color: blue;"><span style="font-weight: bold;">nested</span></p>' )
		);
	}

	public function test_empty_input_returns_empty(): void {
		$rule = new RemoveInlineStylesRule();
		$this->assertSame( '', $rule->apply( '' ) );
	}

	public function test_keep_align_fixture(): void {
		$rule     = new RemoveInlineStylesRule( true );
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/inline-styles-keep-align-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/inline-styles-keep-align-expected.html' );
		$this->assertHtmlEquals( $expected, $rule->apply( $input ) );
	}

	public function test_strict_fixture(): void {
		$rule     = new RemoveInlineStylesRule( false );
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/inline-styles-strict-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/inline-styles-strict-expected.html' );
		$this->assertHtmlEquals( $expected, $rule->apply( $input ) );
	}

	public function test_default_constructor_keeps_text_align(): void {
		// Defaut = true : doit conserver text-align.
		$rule = new RemoveInlineStylesRule();
		$this->assertHtmlEquals(
			'<p style="text-align: center;">x</p>',
			$rule->apply( '<p style="text-align: center; color: red;">x</p>' )
		);
	}

	// =========================================================================
	// countMatches() — Phase 1 V1.0
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$rule = new RemoveInlineStylesRule();
		$this->assertSame( 0, $rule->countMatches( '' ) );
	}

	public function test_count_matches_keep_text_align_skips_pure_text_align(): void {
		// keep_text_align = true : un style="text-align:..." pur reste tel quel,
		// donc apply() ne le modifierait pas → pas compte.
		$rule = new RemoveInlineStylesRule( true );
		$this->assertSame( 0, $rule->countMatches( '<p style="text-align: center;">x</p>' ) );
	}

	public function test_count_matches_keep_text_align_counts_when_other_declarations(): void {
		// 2 elements avec declarations autres que text-align => 2.
		$rule = new RemoveInlineStylesRule( true );
		$html = '<p style="color: red;">a</p>'
			. '<span style="text-align:left; font-size: 12px;">b</span>'
			. '<p style="text-align: center;">c</p>';
		$this->assertSame( 2, $rule->countMatches( $html ) );
	}

	public function test_count_matches_strict_mode_counts_all_styled(): void {
		// keep_text_align = false : strip total, tout @style compte.
		$rule = new RemoveInlineStylesRule( false );
		$html = '<p style="text-align: center;">a</p><p style="color: red;">b</p>';
		$this->assertSame( 2, $rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_apply_idempotence(): void {
		$rule  = new RemoveInlineStylesRule( true );
		$html  = '<p style="color: red; text-align: center;">x</p><span style="font-size:12px">y</span>';
		$after = $rule->apply( $html );
		$this->assertSame( 0, $rule->countMatches( $after ) );
	}

	// =========================================================================
	//  Exception bloc Gutenberg `core/image` : le `style` du `<img>` est
	//  synchronise avec le JSON `<!-- wp:image {width, height, aspectRatio,
	//  scale} -->` en amont et la classe `is-resized` du `<figure>` parent.
	//  Le retirer isolement = bloc "contenu invalide" a la reouverture
	//  dans l'editeur Gutenberg. Cf. CLAUDE.md §6.
	// =========================================================================

	/**
	 * Fragment representatif du bloc `core/image` du corpus MMM (article 22922).
	 *
	 * @return string
	 */
	private function gutenberg_image_block(): string {
		return '<figure class="wp-block-image aligncenter size-full is-resized">'
			. '<img src="https://example.com/img.jpg" alt="x" class="wp-image-22912"'
			. ' style="aspect-ratio:1.3333333333333333;object-fit:contain;width:700px;height:auto"/>'
			. '<figcaption class="wp-element-caption">Légende</figcaption>'
			. '</figure>';
	}

	public function test_preserves_img_style_inside_wp_block_image_keep_align_mode(): void {
		$rule  = new RemoveInlineStylesRule( true );
		$input = $this->gutenberg_image_block();
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_preserves_img_style_inside_wp_block_image_strict_mode(): void {
		// Meme exception en mode strict (keep_text_align=false) : le bloc
		// Gutenberg prime sur le strip generique.
		$rule  = new RemoveInlineStylesRule( false );
		$input = $this->gutenberg_image_block();
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_count_matches_skips_img_inside_wp_block_image(): void {
		$rule  = new RemoveInlineStylesRule( true );
		$input = $this->gutenberg_image_block();
		$this->assertSame( 0, $rule->countMatches( $input ) );
	}

	public function test_count_matches_strict_mode_also_skips_img_inside_wp_block_image(): void {
		$rule  = new RemoveInlineStylesRule( false );
		$input = $this->gutenberg_image_block();
		$this->assertSame( 0, $rule->countMatches( $input ) );
	}

	public function test_still_strips_img_outside_wp_block_image(): void {
		// Un `<img style="...">` qui n'est PAS enfant d'un `<figure class*=
		// 'wp-block-image'>` doit toujours etre nettoye normalement (cas
		// frequent sur les articles SiteOrigin et le HTML "libre").
		$rule  = new RemoveInlineStylesRule( true );
		$input = '<p><img src="a.jpg" style="width:100px; color:red"/></p>';
		$this->assertHtmlEquals(
			'<p><img src="a.jpg"/></p>',
			$rule->apply( $input )
		);
	}

	public function test_still_strips_img_inside_figure_without_wp_block_image_class(): void {
		// Un `<figure>` sans la classe `wp-block-image` (figure HTML "libre")
		// : le `<img>` enfant est traite normalement.
		$rule  = new RemoveInlineStylesRule( true );
		$input = '<figure class="something-else"><img src="a.jpg" style="width:100px"/></figure>';
		$this->assertHtmlEquals(
			'<figure class="something-else"><img src="a.jpg"/></figure>',
			$rule->apply( $input )
		);
	}

	public function test_does_not_match_lookalike_class_wp_block_image_foo(): void {
		// Test de la frontiere de mot dans la regex de la classe. Un
		// `<figure class="wp-block-image-foo">` ne doit PAS declencher
		// l'exception — la classe est differente.
		$rule  = new RemoveInlineStylesRule( false );
		$input = '<figure class="wp-block-image-foo"><img src="a.jpg" style="width:100px"/></figure>';
		$this->assertHtmlEquals(
			'<figure class="wp-block-image-foo"><img src="a.jpg"/></figure>',
			$rule->apply( $input )
		);
	}

	public function test_preserves_style_on_img_with_multiple_classes_on_figure(): void {
		// La classe `wp-block-image` peut etre entouree d'autres classes
		// (typique : `aligncenter size-full is-resized`). Match correct
		// par la regex de frontiere de mot.
		$rule  = new RemoveInlineStylesRule( true );
		$input = '<figure class="foo wp-block-image bar"><img src="a.jpg" style="width:100px"/></figure>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_still_strips_non_img_styled_inside_wp_block_image(): void {
		// L'exception est restrictive : seul le `<img>` enfant direct est
		// preserve. Une `<figcaption style="...">` ou autre element style
		// dans le meme `<figure>` est nettoye normalement.
		$rule  = new RemoveInlineStylesRule( false );
		$input = '<figure class="wp-block-image">'
			. '<img src="a.jpg" style="width:100px"/>'
			. '<figcaption style="color:red">Légende</figcaption>'
			. '</figure>';
		$this->assertHtmlEquals(
			'<figure class="wp-block-image">'
			. '<img src="a.jpg" style="width:100px"/>'
			. '<figcaption>Légende</figcaption>'
			. '</figure>',
			$rule->apply( $input )
		);
	}
}
