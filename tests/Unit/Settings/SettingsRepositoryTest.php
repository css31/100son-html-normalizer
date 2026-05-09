<?php
/**
 * Tests SettingsRepository::getRegressionThresholds() (Phase 1 V1.0).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Settings;

use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase {

	private SettingsRepository $repo;

	protected function setUp(): void {
		// Reset stub d'options globales (cf. tests/bootstrap.php).
		$GLOBALS['son100_htmln_options'] = array();
		$this->repo                      = new SettingsRepository();
	}

	public function test_regression_thresholds_returns_defaults(): void {
		$thresholds = $this->repo->getRegressionThresholds();
		$this->assertSame(
			array(
				'text_loss_pct'       => 0,
				'words_loss_pct'      => 0,
				'paragraphs_loss_pct' => 5,
				'headings_loss'       => 0,
				'images_loss'         => 0,
				'links_loss'          => 0,
				'lists_loss'          => 0,
			),
			$thresholds
		);
	}

	public function test_regression_thresholds_respect_user_override(): void {
		update_option(
			'son100_htmln_settings',
			array(
				'regression_thresholds' => array(
					'text_loss_pct'       => 2,
					'paragraphs_loss_pct' => 10,
					'images_loss'         => 1,
				),
			)
		);
		$thresholds = $this->repo->getRegressionThresholds();
		$this->assertSame( 2, $thresholds['text_loss_pct'] );
		$this->assertSame( 10, $thresholds['paragraphs_loss_pct'] );
		$this->assertSame( 1, $thresholds['images_loss'] );
		// Cles non overridees -> defauts.
		$this->assertSame( 0, $thresholds['words_loss_pct'] );
		$this->assertSame( 0, $thresholds['headings_loss'] );
		$this->assertSame( 0, $thresholds['links_loss'] );
		$this->assertSame( 0, $thresholds['lists_loss'] );
	}

	public function test_regression_thresholds_falls_back_on_invalid_values(): void {
		update_option(
			'son100_htmln_settings',
			array(
				'regression_thresholds' => array(
					'text_loss_pct' => 'not-a-number',
					'images_loss'   => -42,
					'links_loss'    => null,
				),
			)
		);
		$thresholds = $this->repo->getRegressionThresholds();
		// Invalides -> defauts (0).
		$this->assertSame( 0, $thresholds['text_loss_pct'] );
		$this->assertSame( 0, $thresholds['images_loss'] );
		$this->assertSame( 0, $thresholds['links_loss'] );
	}

	public function test_regression_thresholds_falls_back_on_non_array_option(): void {
		// Option mal formee : ne doit pas casser, retourne defauts.
		update_option(
			'son100_htmln_settings',
			array( 'regression_thresholds' => 'broken' )
		);
		$thresholds = $this->repo->getRegressionThresholds();
		$this->assertSame( 5, $thresholds['paragraphs_loss_pct'] );
		$this->assertSame( 0, $thresholds['text_loss_pct'] );
	}

	public function test_regression_thresholds_returns_int_types(): void {
		update_option(
			'son100_htmln_settings',
			array(
				'regression_thresholds' => array(
					'text_loss_pct'       => '7',
					'paragraphs_loss_pct' => 12.0,
				),
			)
		);
		$thresholds = $this->repo->getRegressionThresholds();
		$this->assertSame( 7, $thresholds['text_loss_pct'] );
		$this->assertSame( 12, $thresholds['paragraphs_loss_pct'] );
	}
}
