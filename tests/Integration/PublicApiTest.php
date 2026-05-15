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
					'R5' => [ 'enabled' => true, 'threshold' => 2 ],
					'R6' => [ 'enabled' => true, 'keep_text_align' => true ],
					'R7' => [
						'enabled'        => true,
						'threshold'      => 2,
						'markers'        => [
							'dash' => true, 'emdash' => true, 'asterix' => true,
							'bullet' => true, 'numeric' => true,
						],
						'custom_markers' => [],
					],
					'R8' => [ 'enabled' => true, 'mappings' => [ 'bold' => true, 'italic' => true ] ],
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

	public function test_filter_converts_h4_caption_to_figcaption(): void {
		// R11 — pattern typique corpus MMM-2 (article 491).
		$dirty  = '<p><a href="https://example.test/big.jpg"><img src="https://example.test/thumb.jpg" alt="x"></a></p>'
			. '<h4>Ma légende détournée</h4>';
		$result = $this->api->on_filter_normalize( $dirty, [ 'source' => 'test' ] );
		$this->assertHtmlEquals(
			'<figure><a href="https://example.test/big.jpg"><img src="https://example.test/thumb.jpg" alt="x"></a><figcaption>Ma légende détournée</figcaption></figure>',
			$result
		);
	}

	public function test_filter_converts_h4_mixed_image_caption_to_figure(): void {
		// R12 — pattern intra-h4 (post 756).
		$dirty  = '<h4><a href="https://example.test/big.jpg"><img src="https://example.test/thumb.jpg" alt="x"></a> Meuble Ikea.</h4>';
		$result = $this->api->on_filter_normalize( $dirty, [ 'source' => 'test' ] );
		$this->assertHtmlEquals(
			'<figure><a href="https://example.test/big.jpg"><img src="https://example.test/thumb.jpg" alt="x"></a><figcaption>Meuble Ikea.</figcaption></figure>',
			$result
		);
	}

	public function test_filter_converts_h4_multi_image_to_figure(): void {
		// R12 mode tolérant : 2 imgs partageant une figcaption unique.
		$dirty  = '<h4><a href="a.jpg"><img src="a-t.jpg" alt="a"></a> <a href="b.jpg"><img src="b-t.jpg" alt="b"></a> Avant / après.</h4>';
		$result = $this->api->on_filter_normalize( $dirty, [ 'source' => 'test' ] );
		$this->assertHtmlEquals(
			'<figure><a href="a.jpg"><img src="a-t.jpg" alt="a"></a><a href="b.jpg"><img src="b-t.jpg" alt="b"></a><figcaption>Avant / après.</figcaption></figure>',
			$result
		);
	}

	public function test_filter_promotes_h2_chapo_to_paragraph(): void {
		// R13 — pattern typique chapô SiteOrigin (post 491).
		$dirty  = '<h2>Il est rare de rénover sa maison en une unique session. La plupart du temps, ils s\'échelonnent par tranches sur plusieurs années.</h2><p>Suite.</p>';
		$result = $this->api->on_filter_normalize( $dirty, [ 'source' => 'test' ] );
		$this->assertHtmlEquals(
			'<p class="chapo">Il est rare de rénover sa maison en une unique session. La plupart du temps, ils s\'échelonnent par tranches sur plusieurs années.</p><p>Suite.</p>',
			$result
		);
	}

	public function test_filter_marks_first_p_chapo(): void {
		// R14 — chapô déjà en <p>, simplement marqué.
		$dirty  = '<p>Basée sur la région toulousaine, Laetitia Moreau décline le verre en aménagement intérieur.</p><p>Suite.</p>';
		$result = $this->api->on_filter_normalize( $dirty, [ 'source' => 'test' ] );
		$this->assertHtmlEquals(
			'<p class="chapo">Basée sur la région toulousaine, Laetitia Moreau décline le verre en aménagement intérieur.</p><p>Suite.</p>',
			$result
		);
	}
}
