<?php
/**
 * Tests RegressionDetector — Phase 3.1 V1.0.
 *
 * Couvre les 7 seuils γ + cas combinés + cas seuils modifiés (cf. cahier
 * v2.0 §11.21 et §8 F15).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Regression;

use Cent_Son\Html_Normalizer\Metrics\MetricsSnapshot;
use Cent_Son\Html_Normalizer\Regression\RegressionDetector;
use Cent_Son\Html_Normalizer\Regression\RegressionFailure;
use Cent_Son\Html_Normalizer\Regression\RegressionReport;
use Cent_Son\Html_Normalizer\Regression\RegressionThresholds;
use PHPUnit\Framework\TestCase;

final class RegressionDetectorTest extends TestCase {

	private RegressionDetector $detector;
	private RegressionThresholds $defaults;

	protected function setUp(): void {
		$this->detector = new RegressionDetector();
		$this->defaults = RegressionThresholds::defaults();
	}

	/**
	 * Helper : crée un MetricsSnapshot avec valeurs explicites par défaut.
	 *
	 * @param array<string, mixed> $overrides Surcharges.
	 * @return MetricsSnapshot
	 */
	private function snapshot( array $overrides = array() ): MetricsSnapshot {
		$base = array(
			'chars'      => 1000,
			'words'      => 200,
			'paragraphs' => 10,
			'headings'   => array( 'h1' => 1, 'h2' => 3, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ),
			'images'     => 5,
			'links'      => 4,
			'lists'      => 2,
		);
		return MetricsSnapshot::fromArray( array_merge( $base, $overrides ) );
	}

	// =========================================================================
	//  Pas de régression
	// =========================================================================

	public function test_returns_null_when_no_change(): void {
		$snap = $this->snapshot();
		$this->assertNull( $this->detector->analyze( $snap, $snap, $this->defaults ) );
	}

	public function test_returns_null_when_metrics_increase(): void {
		$before = $this->snapshot();
		$after  = $this->snapshot( array( 'chars' => 1500, 'images' => 6 ) );
		$this->assertNull( $this->detector->analyze( $before, $after, $this->defaults ) );
	}

	public function test_returns_null_when_before_is_zero(): void {
		// before = 0 → pas de division possible, on considère qu'il n'y a pas de perte.
		$before = $this->snapshot( array( 'chars' => 0, 'words' => 0, 'paragraphs' => 0 ) );
		$after  = $this->snapshot( array( 'chars' => 0, 'words' => 0, 'paragraphs' => 0 ) );
		$this->assertNull( $this->detector->analyze( $before, $after, $this->defaults ) );
	}

	// =========================================================================
	//  1 seuil par métrique : 6 tests + headings (1 par niveau)
	// =========================================================================

	public function test_chars_loss_above_pct_threshold_triggers(): void {
		// Default : text_loss_pct = 0 → toute perte de chars déclenche.
		$before = $this->snapshot( array( 'chars' => 1000 ) );
		$after  = $this->snapshot( array( 'chars' => 999 ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertInstanceOf( RegressionReport::class, $report );
		$this->assertTrue( $report->has_failure( 'chars' ) );
		$failure = $report->failure_for( 'chars' );
		$this->assertSame( RegressionFailure::UNIT_PCT, $failure->unit );
		$this->assertSame( 1, $failure->loss );
		$this->assertSame( 0.1, $failure->loss_pct );
	}

	public function test_words_loss_above_pct_threshold_triggers(): void {
		$before = $this->snapshot( array( 'words' => 100 ) );
		$after  = $this->snapshot( array( 'words' => 99 ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$this->assertTrue( $report->has_failure( 'words' ) );
	}

	public function test_paragraphs_loss_below_default_pct_threshold_does_not_trigger(): void {
		// Default : paragraphs_loss_pct = 5 → 4% de perte ne doit PAS déclencher.
		$before = $this->snapshot( array( 'paragraphs' => 100 ) );
		$after  = $this->snapshot( array( 'paragraphs' => 96 ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		// 4% de perte sur paragraphs (sous seuil 5%) → null si rien d'autre ne casse.
		$this->assertNull( $report );
	}

	public function test_paragraphs_loss_above_pct_threshold_triggers(): void {
		$before = $this->snapshot( array( 'paragraphs' => 100 ) );
		$after  = $this->snapshot( array( 'paragraphs' => 90 ) ); // 10% perte > 5%
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$this->assertTrue( $report->has_failure( 'paragraphs' ) );
		$failure = $report->failure_for( 'paragraphs' );
		$this->assertSame( 10, $failure->loss );
		$this->assertSame( 10.0, $failure->loss_pct );
	}

	public function test_images_loss_above_absolute_threshold_triggers(): void {
		// Default : images_loss = 0 → toute image perdue déclenche.
		$before = $this->snapshot( array( 'images' => 5 ) );
		$after  = $this->snapshot( array( 'images' => 4 ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$failure = $report->failure_for( 'images' );
		$this->assertSame( RegressionFailure::UNIT_ABSOLUTE, $failure->unit );
		$this->assertSame( 1, $failure->loss );
		$this->assertNull( $failure->loss_pct );
	}

	public function test_links_loss_above_absolute_threshold_triggers(): void {
		$before = $this->snapshot( array( 'links' => 5 ) );
		$after  = $this->snapshot( array( 'links' => 3 ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$this->assertTrue( $report->has_failure( 'links' ) );
		$this->assertSame( 2, $report->failure_for( 'links' )->loss );
	}

	public function test_lists_loss_above_absolute_threshold_triggers(): void {
		$before = $this->snapshot( array( 'lists' => 3 ) );
		$after  = $this->snapshot( array( 'lists' => 2 ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$this->assertTrue( $report->has_failure( 'lists' ) );
	}

	public function test_headings_loss_per_level_triggers(): void {
		// Default : headings_loss = 0 → toute perte par niveau déclenche.
		$before = $this->snapshot( array( 'headings' => array( 'h1' => 1, 'h2' => 5, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ) ) );
		$after  = $this->snapshot( array( 'headings' => array( 'h1' => 1, 'h2' => 3, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ) ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$this->assertFalse( $report->has_failure( 'headings.h1' ), 'h1 inchangé ne doit pas être listé' );
		$this->assertTrue( $report->has_failure( 'headings.h2' ) );
		$this->assertSame( 2, $report->failure_for( 'headings.h2' )->loss );
	}

	public function test_headings_loss_isolated_per_level(): void {
		// Vérifie que h1..h6 sont indépendants : perte sur h2 mais gain sur h3 → seul h2 est fautif.
		$before = $this->snapshot( array( 'headings' => array( 'h1' => 0, 'h2' => 5, 'h3' => 1, 'h4' => 0, 'h5' => 0, 'h6' => 0 ) ) );
		$after  = $this->snapshot( array( 'headings' => array( 'h1' => 0, 'h2' => 4, 'h3' => 2, 'h4' => 0, 'h5' => 0, 'h6' => 0 ) ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$this->assertSame( 1, $report->failure_count() );
		$this->assertTrue( $report->has_failure( 'headings.h2' ) );
	}

	// =========================================================================
	//  Cas combinés
	// =========================================================================

	public function test_multiple_failures_all_listed(): void {
		// Perte sur images (1 > 0) + perte sur links (3 > 0).
		$before = $this->snapshot( array( 'images' => 5, 'links' => 4 ) );
		$after  = $this->snapshot( array( 'images' => 4, 'links' => 1 ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$this->assertSame( 2, $report->failure_count() );
		$this->assertTrue( $report->has_failure( 'images' ) );
		$this->assertTrue( $report->has_failure( 'links' ) );
	}

	public function test_failure_at_threshold_boundary_does_not_trigger(): void {
		// Threshold strict : on déclenche QUAND la perte EXCÈDE le seuil, pas quand elle l'atteint.
		// Avec images_loss=2, perte de 2 ne déclenche pas, perte de 3 oui.
		$thresholds = new RegressionThresholds(
			text_loss_pct: 0,
			words_loss_pct: 0,
			paragraphs_loss_pct: 0,
			headings_loss: 0,
			images_loss: 2,
			links_loss: 0,
			lists_loss: 0,
		);
		$before = $this->snapshot( array( 'images' => 5, 'links' => 4, 'lists' => 2, 'headings' => array( 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ) ) );
		$after  = $this->snapshot( array( 'images' => 3, 'links' => 4, 'lists' => 2, 'headings' => array( 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ) ) );
		// Perte d'images = 2, threshold = 2 → pas de failure (loss <= threshold).
		$report = $this->detector->analyze( $before, $after, $thresholds );
		$this->assertNull( $report );

		$after  = $this->snapshot( array( 'images' => 2, 'links' => 4, 'lists' => 2, 'headings' => array( 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ) ) );
		// Perte d'images = 3, threshold = 2 → failure.
		$report = $this->detector->analyze( $before, $after, $thresholds );
		$this->assertNotNull( $report );
		$this->assertTrue( $report->has_failure( 'images' ) );
	}

	public function test_pct_threshold_at_boundary_does_not_trigger(): void {
		// paragraphs_loss_pct = 5 → 5% pile ne déclenche pas (loss_pct <= threshold).
		$before = $this->snapshot( array( 'paragraphs' => 100 ) );
		$after  = $this->snapshot( array( 'paragraphs' => 95 ) );  // 5% pile
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNull( $report );

		$after = $this->snapshot( array( 'paragraphs' => 94 ) );  // 6% > 5%
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
	}

	public function test_modified_thresholds_can_relax_detection(): void {
		// Si l'admin élève les seuils, des régressions précédemment déclenchantes deviennent silencieuses.
		$tolerant_thresholds = new RegressionThresholds(
			text_loss_pct: 50,
			words_loss_pct: 50,
			paragraphs_loss_pct: 50,
			headings_loss: 10,
			images_loss: 10,
			links_loss: 10,
			lists_loss: 10,
		);
		$before = $this->snapshot( array( 'images' => 5 ) );
		$after  = $this->snapshot( array( 'images' => 1 ) );  // perte 4, sous seuil 10
		$this->assertNull( $this->detector->analyze( $before, $after, $tolerant_thresholds ) );
	}

	public function test_report_serializes_to_array(): void {
		$before = $this->snapshot( array( 'images' => 5 ) );
		$after  = $this->snapshot( array( 'images' => 3 ) );
		$report = $this->detector->analyze( $before, $after, $this->defaults );
		$this->assertNotNull( $report );
		$arr = $report->to_array();
		$this->assertArrayHasKey( 'failures', $arr );
		$this->assertCount( 1, $arr['failures'] );
		$this->assertSame( 'images', $arr['failures'][0]['metric_key'] );
		$this->assertSame( 5, $arr['failures'][0]['before'] );
		$this->assertSame( 3, $arr['failures'][0]['after'] );
		$this->assertSame( 0, $arr['failures'][0]['threshold'] );
		$this->assertSame( 'absolute', $arr['failures'][0]['unit'] );
		$this->assertTrue( $arr['failures'][0]['exceeded'] );
	}
}
