<?php
/**
 * Tests P8 — RecoverSemanticStylesRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\RecoverSemanticStylesRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class RecoverSemanticStylesRuleTest extends TestCase {

	use HtmlAssertions;

	public function test_id_and_label(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertSame( 'P8', $rule->id() );
		$this->assertNotEmpty( $rule->label() );
	}

	// ====== font-weight: bold -> <strong> ====== //

	public function test_font_weight_bold_wraps_in_strong(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><strong>x</strong></p>',
			$rule->apply( '<p style="font-weight: bold;">x</p>' )
		);
	}

	public function test_font_weight_bold_is_case_insensitive(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><strong>x</strong></p>',
			$rule->apply( '<p style="font-weight: BOLD;">x</p>' )
		);
		$this->assertHtmlEquals(
			'<p><strong>x</strong></p>',
			$rule->apply( '<p style="font-weight: Bold;">x</p>' )
		);
	}

	public function test_font_weight_bolder_wraps_in_strong(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><strong>x</strong></p>',
			$rule->apply( '<p style="font-weight: bolder;">x</p>' )
		);
	}

	public function test_font_weight_700_wraps_in_strong(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><strong>x</strong></p>',
			$rule->apply( '<p style="font-weight: 700;">x</p>' )
		);
	}

	public function test_font_weight_900_wraps_in_strong(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><strong>x</strong></p>',
			$rule->apply( '<p style="font-weight: 900;">x</p>' )
		);
	}

	public function test_font_weight_400_does_not_wrap(): void {
		$rule  = new RecoverSemanticStylesRule();
		$input = '<p style="font-weight: 400;">x</p>';
		// Style preserve, pas d'enrobage (la decision spec est : 400 = normal, pas bold).
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	// ====== font-style: italic -> <em> ====== //

	public function test_font_style_italic_wraps_in_em(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><em>x</em></p>',
			$rule->apply( '<p style="font-style: italic;">x</p>' )
		);
	}

	public function test_font_style_italic_is_case_insensitive(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><em>x</em></p>',
			$rule->apply( '<p style="font-style: ITALIC;">x</p>' )
		);
	}

	// ====== Combinaisons ====== //

	public function test_bold_and_italic_combined_strong_outer_em_inner(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<span><strong><em>x</em></strong></span>',
			$rule->apply( '<span style="font-weight: bold; font-style: italic;">x</span>' )
		);
	}

	public function test_combined_order_independent_of_declaration_order(): void {
		// Meme si italic est declare AVANT bold dans le style, l'ordre fige reste strong-outer/em-inner.
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<span><strong><em>x</em></strong></span>',
			$rule->apply( '<span style="font-style: italic; font-weight: bold;">x</span>' )
		);
	}

	// ====== Comportement chirurgical ====== //

	public function test_text_align_is_preserved_when_bold_is_recovered(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p style="text-align: center;"><strong>x</strong></p>',
			$rule->apply( '<p style="text-align: center; font-weight: bold;">x</p>' )
		);
	}

	public function test_color_is_preserved_when_italic_is_recovered(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p style="color: red;"><em>x</em></p>',
			$rule->apply( '<p style="color: red; font-style: italic;">x</p>' )
		);
	}

	public function test_only_semantic_declarations_means_style_attr_removed(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><strong><em>x</em></strong></p>',
			$rule->apply( '<p style="font-weight: bold; font-style: italic;">x</p>' )
		);
	}

	// ====== Configurations ====== //

	public function test_disabled_bold_preserves_font_weight(): void {
		$rule  = new RecoverSemanticStylesRule( false, true );
		$input = '<p style="font-weight: bold;">x</p>';
		// Bold mapping desactive -> aucune transformation, style preserve.
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_disabled_italic_preserves_font_style(): void {
		$rule  = new RecoverSemanticStylesRule( true, false );
		$input = '<p style="font-style: italic;">x</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_both_disabled_passes_through(): void {
		$rule  = new RecoverSemanticStylesRule( false, false );
		$input = '<p style="font-weight: bold; font-style: italic;">x</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	// ====== Cas avec contenu enfant ====== //

	public function test_wraps_around_nested_elements(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertHtmlEquals(
			'<p><strong>texte <span>nested</span> suite</strong></p>',
			$rule->apply( '<p style="font-weight: bold;">texte <span>nested</span> suite</p>' )
		);
	}

	// ====== Cas limites ====== //

	public function test_empty_input_returns_empty(): void {
		$rule = new RecoverSemanticStylesRule();
		$this->assertSame( '', $rule->apply( '' ) );
	}

	public function test_no_style_attribute_unchanged(): void {
		$rule  = new RecoverSemanticStylesRule();
		$input = '<p>x</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_style_without_semantic_declaration_unchanged(): void {
		$rule  = new RecoverSemanticStylesRule();
		$input = '<p style="color: red; font-size: 14px;">x</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_font_shorthand_not_parsed_v1_limitation(): void {
		// Limitation explicite cf. cahier section 14 hyp. 23 :
		// 'font: bold 11px Arial' n'est PAS parse en V1.
		$rule  = new RecoverSemanticStylesRule();
		$input = '<p style="font: bold 11px Arial;">x</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}
}
