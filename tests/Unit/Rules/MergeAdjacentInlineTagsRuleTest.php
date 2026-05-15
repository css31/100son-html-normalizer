<?php
/**
 * Tests MergeAdjacentInlineTagsRule (R15).
 *
 * Fusion de balises inline adjacentes (mêmes tag + mêmes attributs).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\MergeAdjacentInlineTagsRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class MergeAdjacentInlineTagsRuleTest extends TestCase {

	use HtmlAssertions;

	private MergeAdjacentInlineTagsRule $rule;

	protected function setUp(): void {
		$this->rule = new MergeAdjacentInlineTagsRule();
	}

	// =========================================================================
	//  Métadonnées
	// =========================================================================

	public function test_id_is_R15(): void {
		$this->assertSame( 'R15', $this->rule->id() );
	}

	public function test_label_is_not_empty(): void {
		$this->assertNotEmpty( $this->rule->label() );
	}

	// =========================================================================
	//  apply — cas canoniques (bare tags)
	// =========================================================================

	public function test_merges_adjacent_em_without_whitespace(): void {
		$input    = '<em>foo</em><em>bar</em>';
		$expected = '<em>foobar</em>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_merges_adjacent_em_with_whitespace(): void {
		// L'espace entre est préservé à l'intérieur du merge.
		$input  = '<em>foo</em> <em>bar</em>';
		$actual = $this->rule->apply( $input );
		$this->assertStringContainsString( '<em>foo bar</em>', $actual );
		$this->assertSame( 1, substr_count( $actual, '<em>' ) );
	}

	public function test_merges_adjacent_strong(): void {
		$input    = '<strong>A</strong><strong>B</strong>';
		$expected = '<strong>AB</strong>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_merges_adjacent_span_with_same_style(): void {
		// Cas 19204 : deux spans avec exactement le même style.
		$input    = '<span style="font-size:14pt;">A</span><span style="font-size:14pt;">B</span>';
		$expected = '<span style="font-size:14pt;">AB</span>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_merges_chain_of_three(): void {
		// Trois éléments enchaînés → un seul après multi-passes.
		$input    = '<em>A</em><em>B</em><em>C</em>';
		$expected = '<em>ABC</em>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_merges_chain_with_whitespace_between(): void {
		$input  = '<em>A</em> <em>B</em> <em>C</em>';
		$actual = $this->rule->apply( $input );
		$this->assertStringContainsString( '<em>A B C</em>', $actual );
		$this->assertSame( 1, substr_count( $actual, '<em>' ) );
	}

	// =========================================================================
	//  apply — negatives : différences d'attributs
	// =========================================================================

	public function test_preserves_em_with_different_class(): void {
		$input = '<em class="foo">A</em><em class="bar">B</em>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_span_with_different_styles(): void {
		// Styles différents → pas de fusion.
		$input = '<span style="color:red">A</span><span style="color:blue">B</span>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_one_has_extra_attribute(): void {
		// 1er a `id`, 2nd ne l'a pas → asymétrie, pas de fusion.
		$input = '<em id="foo">A</em><em>B</em>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — negatives : tags exclus
	// =========================================================================

	public function test_preserves_adjacent_paragraphs(): void {
		// Spec utilisateur : </p><p> jamais touché.
		$input = '<p>Premier paragraphe.</p><p>Deuxième paragraphe.</p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_adjacent_divs(): void {
		$input = '<div>A</div><div>B</div>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_adjacent_headings(): void {
		$input = '<h2>Section 1</h2><h2>Section 2</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_adjacent_anchors_same_href(): void {
		// Même <a> deux fois — exclu volontairement (deux zones cliquables
		// intentionnellement distinctes).
		$input = '<a href="#">A</a><a href="#">B</a>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_list_items(): void {
		$input = '<ul><li>A</li><li>B</li></ul>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — negatives : non-adjacent
	// =========================================================================

	public function test_preserves_when_text_between(): void {
		$input = '<em>A</em> mot <em>B</em>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_other_element_between(): void {
		$input = '<em>A</em><strong>X</strong><em>B</em>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_when_comment_between(): void {
		$input = '<em>A</em><!-- séparateur --><em>B</em>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — cas mélangés
	// =========================================================================

	public function test_merges_inside_paragraph(): void {
		$input    = '<p>Texte <em>foo</em> <em>bar</em> et la suite.</p>';
		$expected = '<p>Texte <em>foo bar</em> et la suite.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_merges_only_same_tags_in_mixed_sequence(): void {
		// <em><em><strong><strong> → <em><strong> (chaque famille fusionnée).
		$input    = '<em>A</em><em>B</em><strong>C</strong><strong>D</strong>';
		$expected = '<em>AB</em><strong>CD</strong>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_preserves_nested_content_in_merge(): void {
		// Le contenu interne (y compris inlines distincts) reste intact.
		$input    = '<em>A <strong>gras</strong></em><em> et <a href="#">lien</a></em>';
		$expected = '<em>A <strong>gras</strong> et <a href="#">lien</a></em>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — bords
	// =========================================================================

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	public function test_single_em_unchanged(): void {
		$input = '<em>seul</em>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_apply_is_idempotent(): void {
		$html  = '<em>A</em><em>B</em><em>C</em>';
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

	public function test_count_matches_counts_fusions(): void {
		// 3 elements → 2 fusions (A+B puis AB+C).
		$html = '<em>A</em><em>B</em><em>C</em>';
		$this->assertSame( 2, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_zero_when_no_doubling(): void {
		$html = '<em>A</em><strong>B</strong><em>C</em>';
		$this->assertSame( 0, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_idempotence(): void {
		$html  = '<em>A</em><em>B</em>';
		$after = $this->rule->apply( $html );
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}
}
