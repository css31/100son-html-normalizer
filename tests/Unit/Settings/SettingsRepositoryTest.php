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

	// ============================================================
	//  setRegressionThresholds (Phase 6.7)
	// ============================================================

	public function test_set_regression_thresholds_persists_normalized_values(): void {
		$written = $this->repo->setRegressionThresholds(
			array(
				'text_loss_pct'       => 3,
				'words_loss_pct'      => 5,
				'paragraphs_loss_pct' => 10,
				'headings_loss'       => 1,
				'images_loss'         => 2,
				'links_loss'          => 4,
				'lists_loss'          => 0,
			)
		);
		$this->assertSame(
			array(
				'text_loss_pct'       => 3,
				'words_loss_pct'      => 5,
				'paragraphs_loss_pct' => 10,
				'headings_loss'       => 1,
				'images_loss'         => 2,
				'links_loss'          => 4,
				'lists_loss'          => 0,
			),
			$written
		);
		// Roundtrip : ce qu'on a écrit doit se relire à l'identique.
		$this->assertSame( $written, $this->repo->getRegressionThresholds() );
	}

	public function test_set_regression_thresholds_falls_back_on_invalid_values(): void {
		$written = $this->repo->setRegressionThresholds(
			array(
				'text_loss_pct'       => 'oops',
				'words_loss_pct'      => -5,
				'paragraphs_loss_pct' => null,
				'headings_loss'       => 2,
			)
		);
		// Invalides → defauts.
		$this->assertSame( 0, $written['text_loss_pct'] );
		$this->assertSame( 0, $written['words_loss_pct'] );
		$this->assertSame( 5, $written['paragraphs_loss_pct'] );
		// Valide → conservé.
		$this->assertSame( 2, $written['headings_loss'] );
		// Clés manquantes → defauts.
		$this->assertSame( 0, $written['images_loss'] );
		$this->assertSame( 0, $written['links_loss'] );
		$this->assertSame( 0, $written['lists_loss'] );
	}

	public function test_set_regression_thresholds_ignores_unknown_keys(): void {
		$written = $this->repo->setRegressionThresholds(
			array(
				'text_loss_pct'    => 4,
				'unknown_key'      => 42,
				'malicious_option' => 'dropme',
			)
		);
		$this->assertArrayNotHasKey( 'unknown_key', $written );
		$this->assertArrayNotHasKey( 'malicious_option', $written );
		$this->assertCount( 7, $written );
		$this->assertSame( 4, $written['text_loss_pct'] );
	}

	public function test_set_regression_thresholds_preserves_other_settings(): void {
		update_option(
			'son100_htmln_settings',
			array(
				'f8_per_page' => 50,
				'arbitrary'   => 'value',
			)
		);
		$this->repo->setRegressionThresholds( array( 'text_loss_pct' => 3 ) );
		$settings = get_option( 'son100_htmln_settings', array() );
		// Les autres clés du tableau settings doivent être préservées.
		$this->assertSame( 50, $settings['f8_per_page'] );
		$this->assertSame( 'value', $settings['arbitrary'] );
		// Le sous-tableau a bien été écrit.
		$this->assertSame( 3, $settings['regression_thresholds']['text_loss_pct'] );
	}

	// ============================================================
	//  Sites externes (Old / Prod) — utilisés par l'onglet Normaliser.
	// ============================================================

	public function test_external_sites_returns_defaults(): void {
		$sites = $this->repo->getExternalSites();
		$this->assertSame(
			array(
				'old_url'  => 'https://old.ma-maison-mag.fr',
				'prod_url' => 'https://ma-maison-mag.fr',
			),
			$sites
		);
	}

	public function test_external_sites_respect_user_override(): void {
		update_option(
			'son100_htmln_settings',
			array(
				'external_sites' => array(
					'old_url'  => 'https://staging.example.com',
					'prod_url' => 'https://www.example.com',
				),
			)
		);
		$sites = $this->repo->getExternalSites();
		$this->assertSame( 'https://staging.example.com', $sites['old_url'] );
		$this->assertSame( 'https://www.example.com', $sites['prod_url'] );
	}

	public function test_external_sites_strip_trailing_slash(): void {
		$sites = $this->repo->setExternalSites(
			array(
				'old_url'  => 'https://old.ma-maison-mag.fr/',
				'prod_url' => 'https://ma-maison-mag.fr///',
			)
		);
		$this->assertSame( 'https://old.ma-maison-mag.fr', $sites['old_url'] );
		$this->assertSame( 'https://ma-maison-mag.fr', $sites['prod_url'] );
	}

	public function test_external_sites_fall_back_on_invalid_urls(): void {
		$sites = $this->repo->setExternalSites(
			array(
				'old_url'  => 'javascript:alert(1)',
				'prod_url' => 'not a url',
			)
		);
		// Mauvais schéma / non-URL → defauts.
		$this->assertSame( 'https://old.ma-maison-mag.fr', $sites['old_url'] );
		$this->assertSame( 'https://ma-maison-mag.fr', $sites['prod_url'] );
	}

	public function test_external_sites_fall_back_on_non_string_values(): void {
		$sites = $this->repo->setExternalSites(
			array(
				'old_url'  => null,
				'prod_url' => 42,
			)
		);
		$this->assertSame( 'https://old.ma-maison-mag.fr', $sites['old_url'] );
		$this->assertSame( 'https://ma-maison-mag.fr', $sites['prod_url'] );
	}

	public function test_external_sites_ignores_unknown_keys(): void {
		$sites = $this->repo->setExternalSites(
			array(
				'old_url'   => 'https://old.example.com',
				'evil_url'  => 'https://malicious.example.com',
			)
		);
		$this->assertArrayNotHasKey( 'evil_url', $sites );
		$this->assertCount( 2, $sites );
		$this->assertSame( 'https://old.example.com', $sites['old_url'] );
	}

	public function test_external_sites_preserve_other_settings(): void {
		update_option(
			'son100_htmln_settings',
			array(
				'f8_per_page'           => 100,
				'regression_thresholds' => array( 'text_loss_pct' => 9 ),
			)
		);
		$this->repo->setExternalSites( array( 'old_url' => 'https://o.example.com' ) );
		$settings = get_option( 'son100_htmln_settings', array() );
		$this->assertSame( 100, $settings['f8_per_page'] );
		$this->assertSame( 9, $settings['regression_thresholds']['text_loss_pct'] );
		$this->assertSame( 'https://o.example.com', $settings['external_sites']['old_url'] );
	}

	public function test_external_sites_falls_back_on_non_array_option(): void {
		update_option(
			'son100_htmln_settings',
			array( 'external_sites' => 'broken' )
		);
		$sites = $this->repo->getExternalSites();
		$this->assertSame( 'https://old.ma-maison-mag.fr', $sites['old_url'] );
		$this->assertSame( 'https://ma-maison-mag.fr', $sites['prod_url'] );
	}

	public function test_external_sites_accepts_http_not_only_https(): void {
		$sites = $this->repo->setExternalSites(
			array(
				'old_url'  => 'http://old.local',
				'prod_url' => 'http://prod.local',
			)
		);
		$this->assertSame( 'http://old.local', $sites['old_url'] );
		$this->assertSame( 'http://prod.local', $sites['prod_url'] );
	}
}
