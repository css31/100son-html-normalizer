<?php
/**
 * Tests P5 — ExcessiveBrRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\ExcessiveBrRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class ExcessiveBrRuleTest extends TestCase {

	use HtmlAssertions;

	public function test_id_and_label(): void {
		$rule = new ExcessiveBrRule();
		$this->assertSame( 'P5', $rule->id() );
		$this->assertNotEmpty( $rule->label() );
	}

	public function test_default_threshold_is_2(): void {
		$rule = new ExcessiveBrRule();
		$this->assertSame( 2, $rule->get_threshold() );
	}

	public function test_threshold_minimum_is_2_even_if_lower_passed(): void {
		$rule = new ExcessiveBrRule( 1 );
		$this->assertSame( 2, $rule->get_threshold() );
	}

	public function test_threshold_2_collapses_pair_of_br(): void {
		$rule = new ExcessiveBrRule( 2 );
		$this->assertHtmlEquals(
			'<p>A</p><p>B</p>',
			$rule->apply( '<p>A<br /><br />B</p>' )
		);
	}

	public function test_three_consecutive_br_collapse_with_threshold_2(): void {
		$rule = new ExcessiveBrRule( 2 );
		$this->assertHtmlEquals(
			'<p>A</p><p>B</p>',
			$rule->apply( '<p>A<br><br><br>B</p>' )
		);
	}

	public function test_threshold_3_does_not_collapse_pair(): void {
		$rule = new ExcessiveBrRule( 3 );
		$input = '<p>A<br /><br />B</p>';
		// Pas de collapse — pair sous seuil 3.
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_isolated_br_is_preserved(): void {
		$rule = new ExcessiveBrRule( 2 );
		$input = '<p>Avant<br>Après</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_br_with_whitespace_between_them_collapse(): void {
		$rule = new ExcessiveBrRule( 2 );
		$this->assertHtmlEquals(
			'<p>A</p><p>B</p>',
			$rule->apply( "<p>A<br />\n  <br />B</p>" )
		);
	}

	public function test_xhtml_self_closing_form_collapses(): void {
		$rule = new ExcessiveBrRule( 2 );
		$this->assertHtmlEquals(
			'<p>A</p><p>B</p>',
			$rule->apply( '<p>A<br/><br/>B</p>' )
		);
	}

	public function test_trailing_br_collapse_creates_empty_paragraph(): void {
		$rule = new ExcessiveBrRule( 2 );
		// Cas du post 374 : `<p>texte.<br /><br /></p>` → `<p>texte.</p><p></p>`.
		// P1 ramassera le <p></p> en pipeline complète.
		$this->assertHtmlEquals(
			'<p>texte.</p><p></p>',
			$rule->apply( '<p>texte.<br /><br /></p>' )
		);
	}

	public function test_mixed_input(): void {
		$rule     = new ExcessiveBrRule( 2 );
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/excessive-br-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/excessive-br-expected.html' );
		$this->assertHtmlEquals( $expected, $rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$rule = new ExcessiveBrRule();
		$this->assertSame( '', $rule->apply( '' ) );
	}

	public function test_no_br_input_passes_through(): void {
		$rule = new ExcessiveBrRule();
		$input = '<p>Pas de br ici.</p><h2>Titre</h2>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}
}
