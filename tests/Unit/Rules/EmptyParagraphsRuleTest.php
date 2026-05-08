<?php
/**
 * Tests P1 — EmptyParagraphsRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\EmptyParagraphsRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class EmptyParagraphsRuleTest extends TestCase {

	use HtmlAssertions;

	private EmptyParagraphsRule $rule;

	protected function setUp(): void {
		$this->rule = new EmptyParagraphsRule();
	}

	public function test_id_and_label(): void {
		$this->assertSame( 'P1', $this->rule->id() );
		$this->assertNotEmpty( $this->rule->label() );
	}

	public function test_strictly_empty_paragraph_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<p></p>' ) );
	}

	public function test_paragraph_with_nbsp_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<p>&nbsp;</p>' ) );
	}

	public function test_paragraph_with_single_space_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<p> </p>' ) );
	}

	public function test_paragraph_with_only_whitespace_and_nbsp_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( "<p>\n   \n&nbsp;\n</p>" ) );
	}

	public function test_paragraph_with_inline_empty_tag_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<p><strong></strong></p>' ) );
	}

	public function test_non_empty_paragraph_is_preserved(): void {
		$this->assertHtmlEquals( '<p>texte</p>', $this->rule->apply( '<p>texte</p>' ) );
	}

	public function test_paragraph_with_image_is_preserved(): void {
		$html = '<p><img src="x.jpg" alt="x"/></p>';
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}

	public function test_paragraph_with_br_only_is_preserved(): void {
		// Un <br> est un élément structurel — on ne supprime pas, ce sera P5
		// (ExcessiveBr) qui s'occupera du <br> isolé/résiduel selon son seuil.
		$html = '<p><br/></p>';
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}

	public function test_mixed_input_only_empties_removed(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/empty-paragraphs-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/empty-paragraphs-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	public function test_no_paragraphs_input_passes_through(): void {
		$html = '<h2>Titre</h2><div>Contenu</div>';
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}
}
