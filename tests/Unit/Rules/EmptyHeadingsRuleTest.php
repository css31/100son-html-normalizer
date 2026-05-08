<?php
/**
 * Tests P2 — EmptyHeadingsRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\EmptyHeadingsRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class EmptyHeadingsRuleTest extends TestCase {

	use HtmlAssertions;

	private EmptyHeadingsRule $rule;

	protected function setUp(): void {
		$this->rule = new EmptyHeadingsRule();
	}

	public function test_id_and_label(): void {
		$this->assertSame( 'P2', $this->rule->id() );
		$this->assertNotEmpty( $this->rule->label() );
	}

	/**
	 * @dataProvider provide_levels
	 */
	public function test_each_heading_level_when_empty_is_removed( string $tag ): void {
		$this->assertHtmlEquals( '', $this->rule->apply( "<{$tag}></{$tag}>" ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function provide_levels(): array {
		return [
			'h1' => [ 'h1' ],
			'h2' => [ 'h2' ],
			'h3' => [ 'h3' ],
			'h4' => [ 'h4' ],
			'h5' => [ 'h5' ],
			'h6' => [ 'h6' ],
		];
	}

	public function test_heading_with_nbsp_only_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<h2>&nbsp;</h2>' ) );
	}

	public function test_heading_with_whitespace_only_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( "<h3>  \n  </h3>" ) );
	}

	public function test_non_empty_heading_is_preserved(): void {
		$this->assertHtmlEquals( '<h2>Titre</h2>', $this->rule->apply( '<h2>Titre</h2>' ) );
	}

	public function test_heading_with_inline_empty_is_removed(): void {
		$this->assertHtmlEquals( '', $this->rule->apply( '<h3><strong></strong></h3>' ) );
	}

	public function test_heading_with_image_is_preserved(): void {
		$html = '<h4><img src="x.jpg" alt="x"></h4>';
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}

	public function test_paragraphs_are_not_touched(): void {
		$html = '<p></p><h2>OK</h2><p>texte</p>';
		// P1 fera le ménage des <p> vides ; P2 ne touche pas aux <p>.
		$this->assertHtmlEquals( $html, $this->rule->apply( $html ) );
	}

	public function test_mixed_input_only_empties_removed(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/empty-headings-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/empty-headings-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}
}
