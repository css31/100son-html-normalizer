<?php
/**
 * Tests HeadingCaptionToFigcaptionRule (R11).
 *
 * Calqué sur UnwrapParagraphImageRuleTest (R10) et UnwrapHeadingImageRuleTest (R9).
 * Couvre :
 *  - Cas canonique : `<p><img></p><h4>cap</h4>` → `<figure><img><figcaption>cap</figcaption></figure>`
 *    (article 491 du corpus MMM-2).
 *  - Wrappers internes préservés (`<a>`, `<figure>`).
 *  - Inlines du `<h4>` (`<a>`, `<em>`, `<strong>`, `<br>`) préservés dans `<figcaption>`.
 *  - NBSP / whitespace / `<br>` autour de l'image tolérés.
 *  - Negatives : texte autour de l'image, multi-image, h2/h3/h5/h6, h4 sans `<p><img>` précédent.
 *  - countMatches en parallèle de apply (cohérence).
 *  - Idempotence.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rules;

use Cent_Son\Html_Normalizer\Core\Rules\HeadingCaptionToFigcaptionRule;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class HeadingCaptionToFigcaptionRuleTest extends TestCase {

	use HtmlAssertions;

	private HeadingCaptionToFigcaptionRule $rule;

	protected function setUp(): void {
		$this->rule = new HeadingCaptionToFigcaptionRule();
	}

	// =========================================================================
	//  Métadonnées
	// =========================================================================

	public function test_id_is_P11(): void {
		$this->assertSame( 'R11', $this->rule->id() );
	}

	public function test_label_is_not_empty(): void {
		$this->assertNotEmpty( $this->rule->label() );
	}

	// =========================================================================
	//  apply — cas canoniques
	// =========================================================================

	public function test_basic_image_then_h4_caption(): void {
		$input    = '<p><img src="thumb.jpg" alt="x"></p><h4>Légende simple</h4>';
		$expected = '<figure><img src="thumb.jpg" alt="x"><figcaption>Légende simple</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_image_wrapped_in_anchor(): void {
		// Cas typique du corpus MMM-2 : SiteOrigin enrobe systématiquement
		// l'image dans un <a href="..."> pour la lightbox.
		$input    = '<p><a href="big.jpg"><img src="thumb.jpg" alt="x"></a></p><h4>Légende</h4>';
		$expected = '<figure><a href="big.jpg"><img src="thumb.jpg" alt="x"></a><figcaption>Légende</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_image_wrapped_in_inner_figure(): void {
		// Cas exotique mais possible : <p><figure><img></figure></p><h4>cap</h4>.
		// La <figure> interne reste préservée (les enfants du <p> sont déplacés
		// en bloc), seule la <figcaption> est ajoutée par-dessus.
		$input    = '<p><figure><img src="x.jpg" alt="x"></figure></p><h4>cap</h4>';
		$expected = '<figure><figure><img src="x.jpg" alt="x"></figure><figcaption>cap</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_h4_with_inline_tags_preserved(): void {
		// Les inlines du <h4> doivent être copiés tels quels dans <figcaption>.
		$input    = '<p><img src="x.jpg" alt="x"></p><h4>Texte <em>italique</em> et <a href="#">lien</a></h4>';
		$expected = '<figure><img src="x.jpg" alt="x"><figcaption>Texte <em>italique</em> et <a href="#">lien</a></figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_h4_with_br_preserved(): void {
		$input    = '<p><img src="x.jpg" alt="x"></p><h4>Ligne 1<br>Ligne 2</h4>';
		$expected = '<figure><img src="x.jpg" alt="x"><figcaption>Ligne 1<br>Ligne 2</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_h4_with_strong_preserved(): void {
		$input    = '<p><img src="x.jpg" alt="x"></p><h4><strong>Mot fort</strong> et reste</h4>';
		$expected = '<figure><img src="x.jpg" alt="x"><figcaption><strong>Mot fort</strong> et reste</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_nbsp_around_image_tolerated(): void {
		// NBSP de remplissage produits par l'éditeur classique WP.
		$input    = '<p>&nbsp;<img src="x.jpg" alt="x">&nbsp;</p><h4>cap</h4>';
		$actual   = $this->rule->apply( $input );
		// Les NBSP sont déplacés tels quels dans <figure> (préservation du
		// contenu inerte) — l'élément <figcaption> arrive après.
		$this->assertStringContainsString( '<figure>', $actual );
		$this->assertStringContainsString( '<img src="x.jpg" alt="x">', $actual );
		$this->assertStringContainsString( '<figcaption>cap</figcaption>', $actual );
		$this->assertStringNotContainsString( '<h4>', $actual );
		$this->assertStringNotContainsString( '</p>', $actual );
	}

	public function test_br_around_image_tolerated(): void {
		$input    = '<p><br><img src="x.jpg" alt="x"><br></p><h4>cap</h4>';
		$actual   = $this->rule->apply( $input );
		$this->assertStringContainsString( '<figure>', $actual );
		$this->assertStringContainsString( '<img src="x.jpg" alt="x">', $actual );
		$this->assertStringContainsString( '<figcaption>cap</figcaption>', $actual );
		$this->assertStringNotContainsString( '<h4>', $actual );
	}

	public function test_whitespace_between_p_and_h4_tolerated(): void {
		// Saut de ligne entre </p> et <h4> (typique du HTML formatté
		// produit par SiteOrigin Editor).
		$input    = "<p><img src=\"x.jpg\" alt=\"x\"></p>\n\n<h4>cap</h4>";
		$expected = '<figure><img src="x.jpg" alt="x"><figcaption>cap</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_multiple_pairs_in_sequence(): void {
		// Deux paires consécutives : les deux doivent être transformées
		// indépendamment.
		$input    = '<p><img src="a.jpg" alt="a"></p><h4>Légende A</h4>'
			. '<p><img src="b.jpg" alt="b"></p><h4>Légende B</h4>';
		$expected = '<figure><img src="a.jpg" alt="a"><figcaption>Légende A</figcaption></figure>'
			. '<figure><img src="b.jpg" alt="b"><figcaption>Légende B</figcaption></figure>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_mixed_content_around_pair(): void {
		// Texte d'intro avant, paragraphe après : seule la paire est
		// transformée, le reste est intact.
		$input    = '<p>Intro.</p><p><img src="x.jpg" alt="x"></p><h4>cap</h4><p>Suite.</p>';
		$expected = '<p>Intro.</p><figure><img src="x.jpg" alt="x"><figcaption>cap</figcaption></figure><p>Suite.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  apply — negatives (cas préservés tels quels)
	// =========================================================================

	public function test_p_text_around_image_then_h4_demotes_orphan(): void {
		// Le <p> a du texte → pas un cas figure-caption. Le h4 est
		// orphelin (pas d'image-p valide avant) → démoté en p-strong.
		$input    = '<p>Avant <img src="x.jpg" alt="x"> après</p><h4>cap</h4>';
		$expected = '<p>Avant <img src="x.jpg" alt="x"> après</p><p><strong>cap</strong></p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_multi_image_p_then_h4_demotes_orphan(): void {
		// Limite assumée : 2 images dans le <p> = pas de mapping
		// légende→image trivial. Le h4 est traité comme orphelin et
		// démoté en p-strong.
		$input    = '<p><img src="a.jpg" alt="a"><img src="b.jpg" alt="b"></p><h4>cap</h4>';
		$expected = '<p><img src="a.jpg" alt="a"><img src="b.jpg" alt="b"></p><p><strong>cap</strong></p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_preserves_h2_after_image_paragraph(): void {
		$input = '<p><img src="x.jpg" alt="x"></p><h2>Vraie section</h2>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h3_after_image_paragraph(): void {
		$input = '<p><img src="x.jpg" alt="x"></p><h3>Vraie section</h3>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h5_after_image_paragraph(): void {
		$input = '<p><img src="x.jpg" alt="x"></p><h5>Vrai sous-titre</h5>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h6_after_image_paragraph(): void {
		$input = '<p><img src="x.jpg" alt="x"></p><h6>Vrai sous-titre</h6>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_p_with_image_no_h4_after(): void {
		// Image seule sans h4 derrière : R10 s'en occupera, pas R11.
		$input = '<p><img src="x.jpg" alt="x"></p>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_demotes_to_p_strong(): void {
		// Convention MMM : un <h4> sans image-p avant est un détournement
		// typographique (= « p gras »). Il est démoté en <p><strong>.
		$input    = '<p>Texte normal</p><h4>Sous-titre</h4>';
		$expected = '<p>Texte normal</p><p><strong>Sous-titre</strong></p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_preserves_empty_h4(): void {
		// <h4> vide = R2 s'en occupera, pas R11.
		$input = '<p><img src="x.jpg" alt="x"></p><h4></h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_preserves_h4_with_only_whitespace(): void {
		$input = '<p><img src="x.jpg" alt="x"></p><h4>&nbsp;  </h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_non_p_before_h4_demotes_orphan(): void {
		// <div><img></div> n'est pas un <p>, donc pas de fusion
		// figure-caption. Le h4 reste orphelin → p-strong.
		$input    = '<div><img src="x.jpg" alt="x"></div><h4>cap</h4>';
		$expected = '<div><img src="x.jpg" alt="x"></div><p><strong>cap</strong></p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_comment_between_p_and_h4_demotes_orphan(): void {
		// Commentaire entre </p> et <h4> bloque l'adjacence figure-
		// caption. Le h4 devient orphelin → p-strong.
		$input    = '<p><img src="x.jpg" alt="x"></p><!-- séparateur --><h4>cap</h4>';
		$expected = '<p><img src="x.jpg" alt="x"></p><!-- séparateur --><p><strong>cap</strong></p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->rule->apply( '' ) );
	}

	// =========================================================================
	//  countMatches
	// =========================================================================

	public function test_count_matches_zero_on_empty(): void {
		$this->assertSame( 0, $this->rule->countMatches( '' ) );
	}

	public function test_count_matches_counts_pairs_and_orphans(): void {
		// 2 paires figure-caption + 1 orphelin (le h4 « multi » après
		// le p multi-image qui ne fusionne pas) = 3 transformations.
		// Le h3 ne compte pas (R11 ne touche que h4).
		$html = '<p><img src="a.jpg" alt="a"></p><h4>légende A</h4>'  // figure-caption
			. '<p>texte simple</p>'                                    // pas un h4 candidat
			. '<p><img src="b.jpg" alt="b"></p><h4>légende B</h4>'    // figure-caption
			. '<p><img src="c.jpg" alt="c"></p><h3>section</h3>'      // h3 ignoré
			. '<p><img src="d.jpg" alt="d"><img src="e.jpg" alt="e"></p><h4>multi</h4>'; // orphelin → p-strong
		$this->assertSame( 3, $this->rule->countMatches( $html ) );
	}

	public function test_count_matches_consistent_with_idempotence(): void {
		$html  = '<p><img src="x.jpg" alt="x"></p><h4>cap</h4>';
		$after = $this->rule->apply( $html );
		// Après apply, plus aucune paire à matcher.
		$this->assertSame( 0, $this->rule->countMatches( $after ) );
	}

	public function test_apply_is_idempotent(): void {
		$html   = '<p><a href="big.jpg"><img src="thumb.jpg" alt="x"></a></p><h4>cap</h4>';
		$once   = $this->rule->apply( $html );
		$twice  = $this->rule->apply( $once );
		$this->assertHtmlEquals( $once, $twice );
	}

	// =========================================================================
	//  apply — orphan h4 disposition (extension R11 post-rc4)
	// =========================================================================

	public function test_orphan_h4_after_chapo_lead_becomes_credit(): void {
		// h4 orphelin (sans image-p avant) juste après <p class="chapo">
		// → promu en chapô-crédit, gras strippé par ChapoFormatter.
		$input    = '<p class="chapo">Chapô longue avec ponctuation.</p><h4>Cyrille Martin</h4><p>Corps.</p>';
		$expected = '<p class="chapo">Chapô longue avec ponctuation.</p><p class="chapo">Cyrille Martin</p><p>Corps.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_after_chapo_with_existing_credit_demotes_to_strong(): void {
		// Le chapô a déjà 2 p (lead + crédit) → le h4 suivant ne devient
		// PAS un crédit (l'utilisateur veut « chapô n'a qu'un p »).
		$input    = '<p class="chapo">Chapô.</p><p class="chapo">Premier crédit</p><h4>Photos : Untel</h4><p>Corps.</p>';
		$expected = '<p class="chapo">Chapô.</p><p class="chapo">Premier crédit</p><p><strong>Photos : Untel</strong></p><p>Corps.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_with_strong_inside_becomes_clean_credit(): void {
		// h4 portant déjà du <strong> → promu en chapô-crédit, le
		// <strong> est strippé par ChapoFormatter.
		$input    = '<p class="chapo">Chapô longue avec ponctuation.</p><h4><strong>Cyrille Martin</strong></h4>';
		$expected = '<p class="chapo">Chapô longue avec ponctuation.</p><p class="chapo">Cyrille Martin</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_body_section_becomes_p_strong(): void {
		// h4 quelque part dans le corps (pas adjacent à un chapô) → p-strong.
		$input    = '<p class="chapo">Chapô.</p><p>Corps 1.</p><h4>Sous-titre</h4><p>Corps 2.</p>';
		$expected = '<p class="chapo">Chapô.</p><p>Corps 1.</p><p><strong>Sous-titre</strong></p><p>Corps 2.</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_after_chapo_skips_intermediate_empty_p(): void {
		// <p>&nbsp;</p> entre chapô et h4 → skip, h4 toujours considéré
		// comme premier crédit.
		$input    = '<p class="chapo">Chapô.</p><p>&nbsp;</p><h4>Cyrille Martin</h4>';
		$expected = '<p class="chapo">Chapô.</p><p>&nbsp;</p><p class="chapo">Cyrille Martin</p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_with_link_preserves_link_in_credit(): void {
		// h4 avec <a> → ChapoFormatter préserve <a>.
		$input    = '<p class="chapo">Chapô.</p><h4>Photos <a href="https://x.test">Cyrille Martin</a></h4>';
		$expected = '<p class="chapo">Chapô.</p><p class="chapo">Photos <a href="https://x.test">Cyrille Martin</a></p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_with_link_preserves_link_in_p_strong(): void {
		// h4 sans contexte chapô → p-strong, <a> conservé.
		$input    = '<p>Body.</p><h4>Photos <a href="#">Untel</a></h4>';
		$expected = '<p>Body.</p><p><strong>Photos <a href="#">Untel</a></strong></p>';
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}

	public function test_orphan_empty_h4_preserved(): void {
		// h4 vide → R2 territory, R11 ne touche pas.
		$input = '<p>Body.</p><h4></h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_with_image_only_preserved(): void {
		// h4 contenant uniquement une image → R9 territory, R11 skip.
		$input = '<p>Body.</p><h4><img src="x.jpg" alt="x"></h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	public function test_orphan_h4_with_image_and_text_preserved(): void {
		// h4 contenant texte + image → R12 territory.
		$input = '<p>Body.</p><h4><img src="x.jpg" alt="x"> légende</h4>';
		$this->assertHtmlEquals( $input, $this->rule->apply( $input ) );
	}

	// =========================================================================
	//  Fixture intégrale (corpus MMM-2)
	// =========================================================================

	public function test_full_fixture_matches_expected(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../../fixtures/html/heading-caption-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../../fixtures/html/heading-caption-expected.html' );
		$this->assertHtmlEquals( $expected, $this->rule->apply( $input ) );
	}
}
