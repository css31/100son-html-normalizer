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
}
