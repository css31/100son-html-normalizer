<?php
/**
 * Tests P4 — PinterestArtifactsRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\PinterestArtifactsRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class PinterestArtifactsRuleTest extends TestCase {

	use HtmlAssertions;

	private PinterestArtifactsRule $rule;

	protected function setUp(): void {
		$this->rule = new PinterestArtifactsRule();
	}

	public function test_id_and_label(): void {
		$this->assertSame( 'P4', $this->rule->id() );
		$this->assertNotEmpty( $this->rule->label() );
	}

	// ====== Forme A : data-pin-* ====== //

	public function test_form_a_data_pin_do_is_removed(): void {
		$input = '<p><span data-pin-do="buttonPin">Pin</span></p>';
		$this->assertHtmlEquals( '<p></p>', $this->rule->apply( $input ) );
	}

	public function test_form_a_data_pin_id_is_removed(): void {
		$input = '<p><span data-pin-id="abc">contenu</span></p>';
		$this->assertHtmlEquals( '<p></p>', $this->rule->apply( $input ) );
	}

	public function test_form_a_arbitrary_data_pin_attr_is_removed(): void {
		$input = '<p><span data-pin-config="above" data-pin-color="red">x</span></p>';
		$this->assertHtmlEquals( '<p></p>', $this->rule->apply( $input ) );
	}

	// ====== Forme B : signature z-index ====== //

	public function test_form_b_zindex_signature_is_removed(): void {
		$input = '<p><span style="z-index: 8675309; opacity: 0.85;">Enregistrer</span></p>';
		$this->assertHtmlEquals( '<p></p>', $this->rule->apply( $input ) );
	}

	public function test_form_b_real_corpus_signature_is_removed(): void {
		$input = '<p><span style="border-radius: 2px; opacity: 1; z-index: 8675309; cursor: pointer;">Enregistrer</span></p>';
		$this->assertHtmlEquals( '<p></p>', $this->rule->apply( $input ) );
	}

	public function test_form_b_zindex_with_extra_spaces_is_removed(): void {
		// Tolérance des espaces autour du `:`.
		$input = '<p><span style="z-index :  8675309;">x</span></p>';
		$this->assertHtmlEquals( '<p></p>', $this->rule->apply( $input ) );
	}

	public function test_form_b_uppercase_zindex_property_is_removed(): void {
		// Comparaison insensible à la casse pour la propriété.
		$input = '<p><span style="Z-INDEX: 8675309;">x</span></p>';
		$this->assertHtmlEquals( '<p></p>', $this->rule->apply( $input ) );
	}

	// ====== Faux positifs : à NE PAS supprimer ====== //

	public function test_legitimate_span_with_color_is_preserved(): void {
		$input = '<p><span class="x" style="color: red;">texte</span></p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_span_with_other_high_zindex_is_preserved(): void {
		// Tout z-index sauf 8675309 doit être préservé.
		$input = '<p><span style="z-index: 9999; color: red;">x</span></p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_paragraph_without_span_passes_through(): void {
		$input = '<p>Texte simple sans span.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// ====== Cas limites ====== //

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	public function test_mixed_input(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/pinterest-spans-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/pinterest-spans-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_non_span_element_with_data_pin_is_not_touched(): void {
		// La règle cible spécifiquement les <span>. Un <div data-pin-x> n'est pas dans le scope V1.
		$input = '<div data-pin-do="button">x</div>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	// countMatches() — Phase 1 V1.0
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_zero_when_no_pinterest(): void {
		$this->assertSame( 0, $this->rule->countMatches( '<p>texte<span>x</span></p>' ) );
	}

	public function test_count_matches_counts_form_a_data_pin(): void {
		$html = '<span data-pin-do="button">a</span><span data-pin-id="42">b</span><span>c</span>';
		$this->assertSame( 2, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_counts_form_b_z_index_signature(): void {
		$html = '<span style="z-index: 8675309; color: red;">save</span><span style="color:red">other</span>';
		$this->assertSame( 1, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_does_not_count_div_with_data_pin(): void {
		// Forme A : la règle cible exclusivement <span>, pas <div>.
		$this->assertSame( 0, $this->rule->countMatches( '<div data-pin-do="button">x</div>' ) );
	}

	public function test_count_matches_consistent_with_apply_idempotence(): void {
		$html  = '<span data-pin-do="button">a</span><span style="z-index: 8675309;">b</span><span>c</span>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}
}
