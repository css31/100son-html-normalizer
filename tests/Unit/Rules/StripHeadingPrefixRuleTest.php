<?php
/**
 * Tests StripHeadingPrefixRule (R16).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\StripHeadingPrefixRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class StripHeadingPrefixRuleTest extends TestCase {

	use HtmlAssertions;

	private StripHeadingPrefixRule $rule;

	protected function setUp(): void {
		$this->rule = new StripHeadingPrefixRule();
	}

	// =========================================================================
	//  Métadonnées
	// =========================================================================

	public function test_id_is_R16(): void {
		$this->assertSame( 'R16', $this->rule->id() );
	}

	public function test_label_is_not_empty(): void {
		$this->assertNotEmpty( $this->rule->label() );
	}

	// =========================================================================
	//  apply — préfixes numériques
	// =========================================================================

	public function test_strips_simple_numeric_prefix(): void {
		$input    = '<h2>1. Pourquoi bioclimatique ?</h2>';
		$expected = '<h2>Pourquoi bioclimatique ?</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_two_digit_numeric_prefix(): void {
		$input    = '<h2>23. Section vingt-trois</h2>';
		$expected = '<h2>Section vingt-trois</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_numeric_with_parenthesis(): void {
		$input    = '<h2>1) Première section</h2>';
		$expected = '<h2>Première section</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_numeric_with_degree_sign(): void {
		$input    = '<h2>1° Premier point</h2>';
		$expected = '<h2>Premier point</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_numeric_with_extra_spaces(): void {
		$input    = '<h2>1.  Avec deux espaces</h2>';
		$actual   = $this->rule->apply( $input );
		$this->assertStringContainsString( '<h2>', $actual );
		$this->assertStringContainsString( 'Avec deux espaces', $actual );
		$this->assertStringNotContainsString( '1.', $actual );
	}

	// =========================================================================
	//  apply — préfixes puces
	// =========================================================================

	public function test_strips_bullet_prefix(): void {
		$input    = '<h2>• Spécialiste de la terrasse en bois</h2>';
		$expected = '<h2>Spécialiste de la terrasse en bois</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_arrow_bullet(): void {
		$input    = '<h2>► Point fort</h2>';
		$expected = '<h2>Point fort</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_asterisk_bullet(): void {
		$input    = '<h2>* Astérisque</h2>';
		$expected = '<h2>Astérisque</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — préfixes tirets
	// =========================================================================

	public function test_strips_hyphen_prefix(): void {
		$input    = '<h2>- Tiret ASCII</h2>';
		$expected = '<h2>Tiret ASCII</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_en_dash_prefix(): void {
		$input    = '<h2>– Demi-cadratin</h2>';
		$expected = '<h2>Demi-cadratin</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_em_dash_prefix(): void {
		$input    = '<h2>— Cadratin</h2>';
		$expected = '<h2>Cadratin</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — niveaux de heading
	// =========================================================================

	public function test_strips_on_all_heading_levels(): void {
		$input    = '<h1>1. Niveau 1</h1><h2>2. Niveau 2</h2><h3>3. Niveau 3</h3><h4>4. Niveau 4</h4><h5>5. Niveau 5</h5><h6>6. Niveau 6</h6>';
		$expected = '<h1>Niveau 1</h1><h2>Niveau 2</h2><h3>Niveau 3</h3><h4>Niveau 4</h4><h5>Niveau 5</h5><h6>Niveau 6</h6>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — préfixes dans des inlines
	// =========================================================================

	public function test_strips_prefix_wrapped_in_strong(): void {
		// Le préfixe est dans <strong>1.</strong>, le strong devient vide.
		$input    = '<h2><strong>1.</strong> Texte</h2>';
		$actual   = $this->rule->apply( $input );
		$this->assertStringContainsString( 'Texte', $actual );
		$this->assertStringNotContainsString( '>1.<', $actual );
	}

	public function test_strips_prefix_inside_span(): void {
		$input    = '<h2><span style="color:red">1. Section</span></h2>';
		$actual   = $this->rule->apply( $input );
		$this->assertStringContainsString( 'Section', $actual );
		$this->assertStringNotContainsString( '>1.', $actual );
	}

	// =========================================================================
	//  apply — leading whitespace / NBSP
	// =========================================================================

	public function test_strips_leading_whitespace_and_prefix(): void {
		$input    = '<h2>   1. Titre</h2>';
		$expected = '<h2>Titre</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_strips_with_leading_nbsp(): void {
		$input    = '<h2>&nbsp;1. Titre</h2>';
		$actual   = $this->rule->apply( $input );
		$this->assertStringContainsString( 'Titre', $actual );
		$this->assertStringNotContainsString( '1.', $actual );
	}

	// =========================================================================
	//  apply — multiple headings
	// =========================================================================

	public function test_strips_multiple_headings(): void {
		$input    = '<h2>1. Premier</h2><p>texte</p><h2>2. Deuxième</h2><h2>• Troisième</h2>';
		$expected = '<h2>Premier</h2><p>texte</p><h2>Deuxième</h2><h2>Troisième</h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — negatives (pas de strip)
	// =========================================================================

	public function test_preserves_heading_without_prefix(): void {
		$input = '<h2>Titre normal sans préfixe</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_three_digit_number(): void {
		// 100 = 3 chiffres, hors limite (1-2 chiffres).
		$input = '<h2>100. Titre référencé</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_numeric_without_space_after(): void {
		// Pas d'espace après : probablement un décimal ou une référence
		// volontaire (« 1.0 », « 2.5 »).
		$input = '<h2>1.5 m de hauteur</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_prefix_mid_string(): void {
		// Le préfixe n'est pas EN TÊTE → préservé.
		$input = '<h2>Section avec 1. dans le milieu</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_p_with_prefix(): void {
		// R16 ne touche pas les <p>.
		$input = '<p>1. Premier item</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_empty_heading(): void {
		$input = '<h2></h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_whitespace_only_heading(): void {
		$input = '<h2>   </h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	// =========================================================================
	//  apply — heading entièrement préfixe (devient vide)
	// =========================================================================

	public function test_heading_with_only_prefix_becomes_empty(): void {
		// Cas pathologique : le heading est juste « 1. » suivi d'un
		// espace. Le pattern matche, strip → heading vide. R2 le
		// ramassera plus tard dans le pipeline.
		$input    = '<h2>1. </h2>';
		$expected = '<h2></h2>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_preserves_heading_with_prefix_without_trailing_space(): void {
		// « 1. » SANS espace après → pas de match (le regex exige \s+).
		$input = '<h2>1.</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — idempotence
	// =========================================================================

	public function test_apply_is_idempotent(): void {
		$html  = '<h2>1. Section</h2><h2>• Autre</h2>';
		$once  = $this->rule->apply( $html );
		$twice = $this->rule->apply( $once );
		$this->assertHtmlEquals( $once, $twice );
	}

	// =========================================================================
	//  countMatches
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_counts_strippable_headings(): void {
		$html = '<h2>1. Premier</h2><h2>Sans préfixe</h2><h2>• Troisième</h2><h3>3) Sous-section</h3><p>1. dans p ignoré</p>';
		$this->assertSame( 3, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_idempotence(): void {
		$html  = '<h2>1. Section</h2>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}
}
