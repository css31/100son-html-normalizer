<?php
/**
 * Tests P3 — ShareaholicShortcodeRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\ShareaholicShortcodeRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class ShareaholicShortcodeRuleTest extends TestCase {

	use HtmlAssertions;

	private ShareaholicShortcodeRule $rule;

	protected function setUp(): void {
		$this->rule = new ShareaholicShortcodeRule();
	}

	public function test_id_and_label(): void {
		$this->assertSame( 'P3', $this->rule->id() );
		$this->assertNotEmpty( $this->rule->label() );
	}

	public function test_basic_shortcode_is_removed(): void {
		$this->assertSame( '', $this->rule->apply( '[shareaholic id="123"]' ) );
	}

	public function test_multi_attribute_shortcode_is_removed(): void {
		$this->assertSame( '', $this->rule->apply( '[shareaholic app="share_buttons" id="abc-123" class="x"]' ) );
	}

	public function test_uppercase_shortcode_is_removed(): void {
		$this->assertSame( '', $this->rule->apply( '[SHAREAHOLIC id="X"]' ) );
	}

	public function test_block_form_is_not_explicitly_handled(): void {
		// Forme bloc absente du corpus MMM ; rule volontairement limitée à self-closed (cf. PHPDoc).
		$input  = '[shareaholic id="block"]contenu[/shareaholic]';
		$result = $this->rule->apply( $input );
		// Le tag d'ouverture self-closed est retiré, le tag fermant `[/shareaholic]` reste tel quel
		// (interprété comme texte par WP) — comportement documenté.
		$this->assertSame( 'contenu[/shareaholic]', $result );
	}

	public function test_other_shortcodes_are_preserved(): void {
		$input = '[gallery ids="1,2,3"][custom foo="bar"]';
		$this->assertSame( $input, $this->rule->apply( $input ) );
	}

	public function test_plain_text_is_preserved(): void {
		$input = 'Texte sans aucun shortcode.';
		$this->assertSame( $input, $this->rule->apply( $input ) );
	}

	public function test_shortcode_in_paragraph_leaves_empty_p(): void {
		// P3 supprime le shortcode mais laisse le <p> vide ; P1 le ramasse en pipeline complète.
		$this->assertHtmlEquals( '<p></p>', $this->rule->apply( '<p>[shareaholic id="x"]</p>' ) );
	}

	public function test_mixed_input(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/shareaholic-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/shareaholic-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	public function test_text_with_brackets_not_a_shortcode(): void {
		// Le mot "shareaholic" entre crochets sans le pattern complet ne doit pas matcher.
		$input = '[shareaholic-not-a-shortcode without brackets';
		$this->assertSame( $input, $this->rule->apply( $input ) );
	}
}
