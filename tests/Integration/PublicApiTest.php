<?php
/**
 * Tests d'integration PublicApi — facade publique du plugin.
 *
 * Note : appelle directement `on_filter_normalize()` sans passer par
 * apply_filters() WP, car le bootstrap test actuel ne charge pas WP. Un
 * test plus complet via apply_filters() sera ajoute quand le bootstrap
 * d'integration WordPress sera mis en place (cf. cahier section 11 etape 7).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Integration;

use Cent_Son\Html_Normalizer\Api\PublicApi;
use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;
use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Tests\Unit\HtmlAssertions;
use PHPUnit\Framework\TestCase;

final class PublicApiTest extends TestCase {

	use HtmlAssertions;

	private PublicApi $api;

	protected function setUp(): void {
		$settings = new class extends SettingsRepository {
			public function is_preset_enabled( string $preset_id ): bool {
				return true;
			}
			public function get_preset_config( string $preset_id ): array {
				return match ( $preset_id ) {
					'P5' => [ 'enabled' => true, 'threshold' => 2 ],
					'P6' => [ 'enabled' => true, 'keep_text_align' => true ],
					'P7' => [
						'enabled'        => true,
						'threshold'      => 2,
						'markers'        => [
							'dash' => true, 'emdash' => true, 'asterix' => true,
							'bullet' => true, 'numeric' => true,
						],
						'custom_markers' => [],
					],
					'P8' => [ 'enabled' => true, 'mappings' => [ 'bold' => true, 'italic' => true ] ],
					default => [ 'enabled' => true ],
				};
			}
		};

		$normalizer = new HtmlNormalizer( new PresetRegistry( $settings ), new Pipeline() );
		$this->api  = new PublicApi( $normalizer );
	}

	public function test_filter_returns_string_for_string_input(): void {
		$result = $this->api->on_filter_normalize( '<p>OK</p>', [] );
		$this->assertIsString( $result );
	}

	public function test_filter_normalizes_simple_input(): void {
		// Cas du cahier section 8 F6.
		$result = $this->api->on_filter_normalize(
			'<p style="color:red"></p><p>OK</p>[shareaholic id="123"]',
			[ 'source' => 'test' ]
		);
		$this->assertHtmlEquals( '<p>OK</p>', $result );
	}

	public function test_filter_returns_empty_string_for_non_string_input(): void {
		// Defensive : null en entree -> '' (jamais null en sortie).
		$result = $this->api->on_filter_normalize( null, [] );
		$this->assertSame( '', $result );
	}

	public function test_filter_handles_null_context(): void {
		// Defensive : context invalide -> traite comme [].
		$result = $this->api->on_filter_normalize( '<p>OK</p>', null );
		$this->assertHtmlEquals( '<p>OK</p>', $result );
	}

	public function test_filter_never_throws_on_garbage_input(): void {
		// Garde-fou cahier section 13 : "toujours retourner string, jamais throw".
		$result = $this->api->on_filter_normalize( '<<<not really html>>>', [] );
		$this->assertIsString( $result );
	}
}
