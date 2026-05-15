<?php
/**
 * Tests RegressionThresholds — Phase 3.1 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Regression;

use Cent_Son\Html_Normalizer\Regression\RegressionThresholds;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class RegressionThresholdsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['son100_htmln_options'] = [];
	}

	public function test_defaults_match_settings_constants(): void {
		$th = RegressionThresholds::defaults();
		$this->assertSame( 0, $th->text_loss_pct );
		$this->assertSame( 0, $th->words_loss_pct );
		$this->assertSame( 5, $th->paragraphs_loss_pct );
		$this->assertSame( 0, $th->headings_loss );
		$this->assertSame( 0, $th->images_loss );
		$this->assertSame( 0, $th->links_loss );
		$this->assertSame( 0, $th->lists_loss );
	}

	public function test_from_array_merges_with_defaults(): void {
		$th = RegressionThresholds::from_array( array(
			'text_loss_pct' => 3,
			'images_loss'   => 2,
		) );
		$this->assertSame( 3, $th->text_loss_pct );
		$this->assertSame( 2, $th->images_loss );
		// Les autres clés tombent sur les defaults.
		$this->assertSame( 5, $th->paragraphs_loss_pct );
		$this->assertSame( 0, $th->lists_loss );
	}

	public function test_from_array_sanitizes_invalid_values(): void {
		$th = RegressionThresholds::from_array( array(
			'text_loss_pct' => -10,
			'images_loss'   => 'invalid',
			'links_loss'    => 4.7,
		) );
		$this->assertSame( 0, $th->text_loss_pct );  // négatif → 0
		$this->assertSame( 0, $th->images_loss );    // string non num → 0
		$this->assertSame( 4, $th->links_loss );     // float coercé en int
	}

	public function test_from_settings_reads_repository(): void {
		update_option( 'son100_htmln_settings', array(
			'regression_thresholds' => array(
				'text_loss_pct' => 7,
				'lists_loss'    => 3,
			),
		) );
		$th = RegressionThresholds::from_settings( new SettingsRepository() );
		$this->assertSame( 7, $th->text_loss_pct );
		$this->assertSame( 3, $th->lists_loss );
	}

	public function test_to_array_round_trip(): void {
		$original = new RegressionThresholds(
			text_loss_pct: 1,
			words_loss_pct: 2,
			paragraphs_loss_pct: 3,
			headings_loss: 4,
			images_loss: 5,
			links_loss: 6,
			lists_loss: 7,
		);
		$decoded = RegressionThresholds::from_array( $original->to_array() );
		$this->assertSame( $original->text_loss_pct, $decoded->text_loss_pct );
		$this->assertSame( $original->words_loss_pct, $decoded->words_loss_pct );
		$this->assertSame( $original->paragraphs_loss_pct, $decoded->paragraphs_loss_pct );
		$this->assertSame( $original->headings_loss, $decoded->headings_loss );
		$this->assertSame( $original->images_loss, $decoded->images_loss );
		$this->assertSame( $original->links_loss, $decoded->links_loss );
		$this->assertSame( $original->lists_loss, $decoded->lists_loss );
	}

	public function test_relax_text_checks_for_lossy_sets_text_and_words_to_100(): void {
		$strict = new RegressionThresholds(
			text_loss_pct: 0,
			words_loss_pct: 0,
			paragraphs_loss_pct: 5,
			headings_loss: 0,
			images_loss: 0,
			links_loss: 0,
			lists_loss: 0,
		);
		$relaxed = $strict->relax_text_checks_for_lossy();
		$this->assertSame( 100, $relaxed->text_loss_pct );
		$this->assertSame( 100, $relaxed->words_loss_pct );
		// Structurels conservés à l'identique.
		$this->assertSame( 5, $relaxed->paragraphs_loss_pct );
		$this->assertSame( 0, $relaxed->headings_loss );
		$this->assertSame( 0, $relaxed->images_loss );
		$this->assertSame( 0, $relaxed->links_loss );
		$this->assertSame( 0, $relaxed->lists_loss );
	}

	public function test_relax_text_checks_for_lossy_returns_new_instance(): void {
		$strict  = RegressionThresholds::defaults();
		$relaxed = $strict->relax_text_checks_for_lossy();
		$this->assertNotSame( $strict, $relaxed );
		$this->assertSame( 0, $strict->text_loss_pct, 'l\'instance d\'origine reste inchangée' );
	}
}
