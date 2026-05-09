<?php
/**
 * Tests MetricsCalculator — Phase 2.3 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Metrics;

use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Metrics\MetricsSnapshot;
use PHPUnit\Framework\TestCase;

final class MetricsCalculatorTest extends TestCase {

	private MetricsCalculator $calc;

	protected function setUp(): void {
		$this->calc = new MetricsCalculator();
	}

	// =========================================================================
	//  Cas vides / nuls
	// =========================================================================

	public function test_empty_html_returns_zero_snapshot(): void {
		$snap = $this->calc->compute( '' );
		$this->assertSame( 0, $snap->chars );
		$this->assertSame( 0, $snap->words );
		$this->assertSame( 0, $snap->paragraphs );
	}

	public function test_whitespace_only_returns_zero(): void {
		$snap = $this->calc->compute( "   \n  \t  " );
		$this->assertSame( 0, $snap->chars );
		$this->assertSame( 0, $snap->words );
	}

	// =========================================================================
	//  Texte / mots / chars
	// =========================================================================

	public function test_simple_text_chars_and_words(): void {
		$snap = $this->calc->compute( '<p>Bonjour le monde</p>' );
		// "Bonjour le monde" = 16 chars, 3 mots.
		$this->assertSame( 16, $snap->chars );
		$this->assertSame( 3, $snap->words );
	}

	public function test_html_entities_are_decoded(): void {
		$snap = $this->calc->compute( '<p>Caf&eacute;</p>' );
		// "Café" = 4 chars (e accent compte 1 en multibyte UTF-8).
		$this->assertSame( 4, $snap->chars );
		$this->assertSame( 1, $snap->words );
	}

	public function test_nbsp_treated_as_space(): void {
		// "a\xc2\xa0b" (a, nbsp, b) doit etre lu comme 2 mots, 3 chars.
		$snap = $this->calc->compute( '<p>a&nbsp;b</p>' );
		$this->assertSame( 3, $snap->chars );
		$this->assertSame( 2, $snap->words );
	}

	public function test_text_with_unicode_words(): void {
		$snap = $this->calc->compute( '<p>Élise écrit</p>' );
		$this->assertSame( 2, $snap->words );
	}

	// =========================================================================
	//  Comptage structurel
	// =========================================================================

	public function test_counts_paragraphs(): void {
		$snap = $this->calc->compute( '<p>a</p><p>b</p><p>c</p>' );
		$this->assertSame( 3, $snap->paragraphs );
	}

	public function test_counts_headings_per_level(): void {
		$html = '<h1>A</h1><h2>B</h2><h2>C</h2><h3>D</h3>';
		$snap = $this->calc->compute( $html );
		$this->assertSame( 1, $snap->headings['h1'] );
		$this->assertSame( 2, $snap->headings['h2'] );
		$this->assertSame( 1, $snap->headings['h3'] );
		$this->assertSame( 0, $snap->headings['h4'] );
		$this->assertSame( 0, $snap->headings['h5'] );
		$this->assertSame( 0, $snap->headings['h6'] );
		$this->assertSame( 4, $snap->totalHeadings() );
	}

	public function test_counts_images(): void {
		$snap = $this->calc->compute( '<p><img src="a.jpg"/><img src="b.png"/></p><img src="c.gif"/>' );
		$this->assertSame( 3, $snap->images );
	}

	public function test_counts_links_with_href(): void {
		$html = '<a href="https://x.test">x</a><a>no href</a><a href="">empty href</a><a href="https://y.test">y</a>';
		$snap = $this->calc->compute( $html );
		// Seuls les <a href="..."> non vides comptent.
		$this->assertSame( 2, $snap->links );
	}

	public function test_counts_lists_summed(): void {
		// 1 ul + 1 ol + 5 li (3+2) = 7.
		$html = '<ul><li>a</li><li>b</li><li>c</li></ul><ol><li>1</li><li>2</li></ol>';
		$snap = $this->calc->compute( $html );
		$this->assertSame( 7, $snap->lists );
	}

	// =========================================================================
	//  Snapshot complet sur un cas réaliste
	// =========================================================================

	public function test_full_snapshot_on_realistic_article(): void {
		$html = '<h2>Titre</h2>'
			. '<p>Premier paragraphe avec <a href="https://x.test">un lien</a>.</p>'
			. '<p><img src="image.jpg" alt="x"/></p>'
			. '<ul><li>Item 1</li><li>Item 2</li></ul>'
			. '<p>Conclusion.</p>';
		$snap = $this->calc->compute( $html );

		// 3 <p> dans le fixture (le bloc image est dans un <p>, donc compte).
		$this->assertSame( 3, $snap->paragraphs );
		$this->assertSame( 1, $snap->headings['h2'] );
		$this->assertSame( 1, $snap->totalHeadings() );
		$this->assertSame( 1, $snap->images );
		$this->assertSame( 1, $snap->links );
		// 1 ul + 2 li = 3.
		$this->assertSame( 3, $snap->lists );
		// Texte effectif : "Titre" + "Premier paragraphe avec un lien." + "" (image) + "Item 1" + "Item 2" + "Conclusion."
		// On verifie surtout les bornes : > 50 chars et 5+ mots.
		$this->assertGreaterThan( 50, $snap->chars );
		$this->assertGreaterThan( 5, $snap->words );
	}

	public function test_idempotent(): void {
		$html = '<p>Texte stable</p><h2>Titre</h2><img src="a.jpg"/>';
		$first  = $this->calc->compute( $html );
		$second = $this->calc->compute( $html );
		$this->assertSame( $first->toArray(), $second->toArray() );
	}

	public function test_returns_metrics_snapshot_instance(): void {
		$this->assertInstanceOf( MetricsSnapshot::class, $this->calc->compute( '<p>x</p>' ) );
	}

	public function test_handles_malformed_html_gracefully(): void {
		// Pas de throw, retourne un snapshot (peu importe les valeurs exactes).
		$snap = $this->calc->compute( '<<<>>><<not really html<<<' );
		$this->assertInstanceOf( MetricsSnapshot::class, $snap );
	}
}
