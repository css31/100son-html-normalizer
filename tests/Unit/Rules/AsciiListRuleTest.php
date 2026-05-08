<?php
/**
 * Tests P7 — AsciiListRule.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\AsciiListRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class AsciiListRuleTest extends TestCase {

	use HtmlAssertions;

	public function test_id_and_label(): void {
		$rule = new AsciiListRule();
		$this->assertSame( 'P7', $rule->id() );
		$this->assertNotEmpty( $rule->label() );
	}

	// ====== Document-level (sequence de <p> consecutifs) ====== //

	public function test_dash_paragraphs_become_ul(): void {
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li><li>C</li></ul>',
			$rule->apply( '<p>- A</p><p>- B</p><p>- C</p>' )
		);
	}

	public function test_emdash_paragraphs_become_ul(): void {
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li></ul>',
			$rule->apply( '<p>– A</p><p>– B</p>' )
		);
	}

	public function test_asterisk_paragraphs_become_ul(): void {
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li></ul>',
			$rule->apply( '<p>* A</p><p>* B</p>' )
		);
	}

	public function test_bullet_paragraphs_become_ul(): void {
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li></ul>',
			$rule->apply( '<p>• A</p><p>• B</p>' )
		);
	}

	public function test_numeric_paragraphs_become_ol(): void {
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ol><li>Premier</li><li>Deuxieme</li><li>Troisieme</li></ol>',
			$rule->apply( '<p>1. Premier</p><p>2. Deuxieme</p><p>3. Troisieme</p>' )
		);
	}

	public function test_below_threshold_is_not_converted(): void {
		$rule  = new AsciiListRule();
		$input = '<p>- Tiret isolé.</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_threshold_3_requires_three_consecutive(): void {
		$rule = new AsciiListRule(
			[ 'dash' => true, 'emdash' => true, 'asterix' => true, 'bullet' => true, 'numeric' => true ],
			3
		);
		// 2 consecutifs + seuil 3 -> pas de conversion.
		$input = '<p>- A</p><p>- B</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
		// 3 consecutifs + seuil 3 -> conversion.
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li><li>C</li></ul>',
			$rule->apply( '<p>- A</p><p>- B</p><p>- C</p>' )
		);
	}

	public function test_mixed_marker_types_split_into_separate_lists(): void {
		$rule = new AsciiListRule();
		// Bullets puis numeros : 2 listes distinctes.
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li></ul><ol><li>One</li><li>Two</li></ol>',
			$rule->apply( '<p>- A</p><p>- B</p><p>1. One</p><p>2. Two</p>' )
		);
	}

	public function test_non_marker_paragraph_breaks_run(): void {
		$rule = new AsciiListRule();
		// Run de 2 -> ul. Puis paragraphe normal. Puis run de 2 -> autre ul.
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li></ul><p>Texte intercale.</p><ul><li>C</li><li>D</li></ul>',
			$rule->apply( '<p>- A</p><p>- B</p><p>Texte intercale.</p><p>- C</p><p>- D</p>' )
		);
	}

	// ====== Intra-<p> (split sur <br />) ====== //

	public function test_intra_p_with_brs_all_bullets(): void {
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li><li>C</li></ul>',
			$rule->apply( '<p>* A<br />* B<br />* C</p>' )
		);
	}

	public function test_intra_p_below_threshold_unchanged(): void {
		$rule  = new AsciiListRule();
		$input = '<p>Texte d\'introduction.<br />• Une seule bullet.<br />Conclusion sur autre ligne.</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	public function test_intra_p_mixed_text_and_bullets(): void {
		$rule = new AsciiListRule();
		// 1 fragment non-bullet (texte) + 3 fragments bullets -> split en <p> + <ul>.
		$this->assertHtmlEquals(
			'<p><strong>Horaires</strong></p><ul><li>Vendredi</li><li>Samedi</li><li>Dimanche</li></ul>',
			$rule->apply( '<p><strong>Horaires</strong><br />• Vendredi<br />• Samedi<br />• Dimanche</p>' )
		);
	}

	// ====== Detection au travers de balises inline ====== //

	public function test_marker_through_span_is_detected(): void {
		$rule = new AsciiListRule();
		// Le marqueur est dans le texte du <span>. Le <span> est desenrobe (pas d'attribut semantique).
		$this->assertHtmlEquals(
			'<ul><li>A</li><li>B</li></ul>',
			$rule->apply( '<p><span style="color:#000">- A</span></p><p><span style="color:#000">- B</span></p>' )
		);
	}

	public function test_strong_inside_li_is_preserved(): void {
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ul><li><strong>Important</strong> texte</li><li><strong>Autre</strong> ligne</li></ul>',
			$rule->apply( '<p>- <strong>Important</strong> texte</p><p>- <strong>Autre</strong> ligne</p>' )
		);
	}

	public function test_link_inside_li_is_preserved_with_href(): void {
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ul><li>Voir <a href="https://example.test">le site</a></li><li>Voir <a href="https://other.test">autre</a></li></ul>',
			$rule->apply( '<p>- Voir <a href="https://example.test">le site</a></p><p>- Voir <a href="https://other.test">autre</a></p>' )
		);
	}

	public function test_span_with_lang_attribute_is_preserved(): void {
		// Cas hypothetique : <span lang="en"> a un attribut semantique -> conserve.
		$rule = new AsciiListRule();
		$this->assertHtmlEquals(
			'<ul><li><span lang="en">English</span> texte</li><li><span lang="en">English</span> autre</li></ul>',
			$rule->apply( '<p>- <span lang="en">English</span> texte</p><p>- <span lang="en">English</span> autre</p>' )
		);
	}

	// ====== Marqueurs custom ====== //

	public function test_custom_marker_with_threshold(): void {
		$rule = new AsciiListRule(
			[ 'dash' => true, 'emdash' => true, 'asterix' => true, 'bullet' => true, 'numeric' => true ],
			2,
			[ '▸' ]
		);
		$this->assertHtmlEquals(
			'<ul><li>Custom 1</li><li>Custom 2</li></ul>',
			$rule->apply( '<p>▸ Custom 1</p><p>▸ Custom 2</p>' )
		);
	}

	// ====== Mappings desactives ====== //

	public function test_disabled_marker_is_not_converted(): void {
		// Numeric desactive -> les <p> 1./2./3. ne sont pas transformes.
		$rule  = new AsciiListRule(
			[ 'dash' => true, 'emdash' => true, 'asterix' => true, 'bullet' => true, 'numeric' => false ]
		);
		$input = '<p>1. A</p><p>2. B</p>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	// ====== Cas limites ====== //

	public function test_empty_input_returns_empty(): void {
		$rule = new AsciiListRule();
		$this->assertSame( '', $rule->apply( '' ) );
	}

	public function test_no_paragraphs_unchanged(): void {
		$rule  = new AsciiListRule();
		$input = '<h2>Titre</h2><div>Contenu</div>';
		$this->assertHtmlEquals( $input, $rule->apply( $input ) );
	}

	// ====== Fixture validee par Cyrille (source de verite) ====== //

	public function test_full_fixture_matches_expected(): void {
		$rule     = new AsciiListRule();
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/ascii-list-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/ascii-list-expected.html' );
		$this->assertHtmlEquals( $expected, $rule->apply( $input ) );
	}
}
