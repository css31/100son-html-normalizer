<?php
/**
 * Tests d'integration HtmlNormalizer + Pipeline + PresetRegistry.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit;

use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;
use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Test d'integration de la pipeline complete avec tous les presets actives.
 *
 * Stub minimal de SettingsRepository (pour ne pas dependre de l'option WP) :
 * tous les presets sont actives avec leur config par defaut.
 */
final class HtmlNormalizerTest extends TestCase {

	use HtmlAssertions;

	private HtmlNormalizer $normalizer;

	protected function setUp(): void {
		$settings = new class extends SettingsRepository {
			public function is_preset_enabled( string $preset_id ): bool {
				return true;
			}
			public function get_preset_config( string $preset_id ): array {
				switch ( $preset_id ) {
					case 'R5':
						return [ 'enabled' => true, 'threshold' => 2 ];
					case 'R6':
						return [ 'enabled' => true, 'keep_text_align' => true ];
					case 'R7':
						return [
							'enabled'        => true,
							'threshold'      => 2,
							'markers'        => [
								'dash' => true, 'emdash' => true, 'asterix' => true,
								'bullet' => true, 'numeric' => true,
							],
							'custom_markers' => [],
						];
					case 'R8':
						return [
							'enabled'  => true,
							'mappings' => [ 'bold' => true, 'italic' => true ],
						];
					default:
						return [ 'enabled' => true ];
				}
			}
		};

		$this->normalizer = new HtmlNormalizer( new PresetRegistry( $settings ), new Pipeline() );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', $this->normalizer->normalize( '' ) );
	}

	public function test_simple_combined_case(): void {
		// Test mini : <p style> + shareaholic + <p>&nbsp;</p>
		$input = '<p style="color:red"></p><p>OK</p>[shareaholic id="123"]';
		$out   = $this->normalizer->normalize( $input );
		// Apres pipeline : le 1er <p> vide R1, le shareaholic vire R3, reste <p>OK</p>.
		$this->assertHtmlEquals( '<p>OK</p>', $out );
	}

	public function test_full_pipeline_fixture_matches_expected(): void {
		$input    = (string) file_get_contents( __DIR__ . '/../fixtures/html/full-pipeline-input.html' );
		$expected = (string) file_get_contents( __DIR__ . '/../fixtures/html/full-pipeline-expected.html' );
		$out      = $this->normalizer->normalize( $input );
		$this->assertHtmlEquals( $expected, $out );
	}

	public function test_pipeline_is_idempotent_on_clean_html(): void {
		// Du HTML deja "propre" doit etre invariant.
		$clean = '<p>Texte normal.</p><h2>Titre</h2><ul><li>Item</li><li>Autre</li></ul>';
		$this->assertHtmlEquals( $clean, $this->normalizer->normalize( $clean ) );
	}

	public function test_pipeline_handles_garbage_gracefully(): void {
		// Input malforme : doit retourner string (jamais null/false/throw).
		$result = $this->normalizer->normalize( '<<<>>>not really html' );
		$this->assertIsString( $result );
	}
}
