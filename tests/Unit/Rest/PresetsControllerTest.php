<?php
/**
 * Tests PresetsController — onglet Règles SPA (post-rc1).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Rest\PresetsController;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class PresetsControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;
		$GLOBALS['son100_htmln_options']          = array();
	}

	private function controller(): PresetsController {
		$settings = new SettingsRepository();
		$registry = new PresetRegistry( $settings );
		return new PresetsController( $settings, $registry );
	}

	// =========================================================================
	//  register_routes
	// =========================================================================

	public function test_register_routes_registers_list_and_update(): void {
		$this->controller()->register_routes();
		$routes = $GLOBALS['son100_htmln_test_rest_routes'];
		$this->assertCount( 2, $routes );

		$paths = array_map(
			static fn( array $entry ): string => $entry['route'],
			$routes
		);
		$this->assertContains( '/presets', $paths );
		$this->assertContains( '/presets/(?P<id>P(?:10|[1-9]))', $paths );
	}

	public function test_register_routes_uses_manage_options_permission(): void {
		$controller = $this->controller();
		$controller->register_routes();
		foreach ( $GLOBALS['son100_htmln_test_rest_routes'] as $entry ) {
			$this->assertIsArray( $entry['args']['permission_callback'] );
			$this->assertSame( $controller, $entry['args']['permission_callback'][0] );
		}
	}

	// =========================================================================
	//  GET /presets
	// =========================================================================

	public function test_list_returns_ten_presets_in_canonical_order(): void {
		$response = $this->controller()->list_presets( new WP_REST_Request() );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertCount( 10, $body['presets'] );
		$ids = array_map(
			static fn( array $p ): string => $p['id'],
			$body['presets']
		);
		// Ordre numérique P1..P10 (l'API trie par id pour l'UI, distinct du
		// pipeline order qui est P3→P4→P8→P6→P7→P5→P9→P10→P1→P2).
		$this->assertSame(
			array( 'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9', 'P10' ),
			$ids
		);
	}

	public function test_list_exposes_label_description_and_defaults_per_preset(): void {
		$body = $this->controller()->list_presets( new WP_REST_Request() )->get_data();
		$p5   = $this->find_preset( $body['presets'], 'P5' );
		$this->assertNotEmpty( $p5['label'] );
		$this->assertNotEmpty( $p5['description'] );
		$this->assertTrue( $p5['has_options'] );
		$this->assertSame( 2, $p5['defaults']['threshold'] );

		$p1 = $this->find_preset( $body['presets'], 'P1' );
		$this->assertFalse( $p1['has_options'] );
		$this->assertSame( array(), $p1['defaults'] );
	}

	public function test_list_reflects_persisted_overrides(): void {
		update_option(
			'son100_htmln_presets',
			array(
				'P5' => array(
					'enabled'   => true,
					'threshold' => 7,
				),
				'P6' => array(
					'enabled'         => false,
					'keep_text_align' => false,
				),
			)
		);
		$body = $this->controller()->list_presets( new WP_REST_Request() )->get_data();
		$p5   = $this->find_preset( $body['presets'], 'P5' );
		$this->assertTrue( $p5['enabled'] );
		$this->assertSame( 7, $p5['params']['threshold'] );

		$p6 = $this->find_preset( $body['presets'], 'P6' );
		$this->assertFalse( $p6['enabled'] );
		$this->assertFalse( $p6['params']['keep_text_align'] );
	}

	public function test_list_normalizes_p7_complex_params(): void {
		update_option(
			'son100_htmln_presets',
			array(
				'P7' => array(
					'enabled'        => true,
					'threshold'      => 3,
					'markers'        => array(
						'dash'    => true,
						'asterix' => true,
					),
					'custom_markers' => array( '▸', '►', '' ),
				),
			)
		);
		$p7 = $this->find_preset(
			$this->controller()->list_presets( new WP_REST_Request() )->get_data()['presets'],
			'P7'
		);
		$this->assertSame( 3, $p7['params']['threshold'] );
		$this->assertTrue( $p7['params']['markers']['dash'] );
		$this->assertTrue( $p7['params']['markers']['asterix'] );
		$this->assertFalse( $p7['params']['markers']['emdash'] );
		$this->assertSame( array( '▸', '►' ), $p7['params']['custom_markers'] );
	}

	// =========================================================================
	//  POST /presets/<id>
	// =========================================================================

	public function test_update_toggles_enabled_without_touching_params(): void {
		update_option(
			'son100_htmln_presets',
			array(
				'P5' => array(
					'enabled'   => true,
					'threshold' => 5,
				),
			)
		);
		$request = new WP_REST_Request();
		$request->set_param( 'id', 'P5' );
		$request->set_param( 'enabled', false );
		$response = $this->controller()->update_preset( $request );
		$this->assertSame( 200, $response->get_status() );
		$preset = $response->get_data()['preset'];
		$this->assertFalse( $preset['enabled'] );
		$this->assertSame( 5, $preset['params']['threshold'] );
	}

	public function test_update_sanitizes_p5_threshold_out_of_range(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'id', 'P5' );
		$request->set_param( 'params', array( 'threshold' => 50 ) ); // > max 20
		$response = $this->controller()->update_preset( $request );
		$preset   = $response->get_data()['preset'];
		// 50 > 20 → retombe sur le default 2.
		$this->assertSame( 2, $preset['params']['threshold'] );
	}

	public function test_update_writes_p7_markers_and_filters_empty_custom(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'id', 'P7' );
		$request->set_param(
			'params',
			array(
				'threshold'      => 4,
				'markers'        => array(
					'dash'    => true,
					'asterix' => 1,
					'emdash'  => false,
				),
				'custom_markers' => array( '  ▸  ', '', 'X', null ),
			)
		);
		$preset = $this->controller()->update_preset( $request )->get_data()['preset'];
		$this->assertSame( 4, $preset['params']['threshold'] );
		$this->assertTrue( $preset['params']['markers']['dash'] );
		$this->assertTrue( $preset['params']['markers']['asterix'] );
		$this->assertFalse( $preset['params']['markers']['emdash'] );
		$this->assertSame( array( '▸', 'X' ), $preset['params']['custom_markers'] );
	}

	public function test_update_p8_mappings_default_to_true_when_missing(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'id', 'P8' );
		$request->set_param( 'enabled', true );
		$preset = $this->controller()->update_preset( $request )->get_data()['preset'];
		// Pas de payload `mappings` → defaults true/true.
		$this->assertTrue( $preset['params']['mappings']['bold'] );
		$this->assertTrue( $preset['params']['mappings']['italic'] );
	}

	public function test_update_ignores_params_for_paramless_rules(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'id', 'P1' );
		$request->set_param( 'params', array( 'evil_param' => 'oops' ) );
		$preset = $this->controller()->update_preset( $request )->get_data()['preset'];
		$this->assertSame( array(), $preset['params'] );
	}

	public function test_update_404_on_unknown_id(): void {
		// La regex de la route empêche normalement d'arriver ici, mais le
		// filet doit fonctionner.
		$request = new WP_REST_Request();
		$request->set_param( 'id', 'P99' );
		$response = $this->controller()->update_preset( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'preset_not_found', $response->get_data()['code'] );
	}

	public function test_update_persists_to_option(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'id', 'P6' );
		$request->set_param( 'enabled', true );
		$request->set_param( 'params', array( 'keep_text_align' => false ) );
		$this->controller()->update_preset( $request );
		$presets = get_option( 'son100_htmln_presets', array() );
		$this->assertTrue( $presets['P6']['enabled'] );
		$this->assertFalse( $presets['P6']['keep_text_align'] );
	}

	// =========================================================================
	//  Permissions
	// =========================================================================

	public function test_permission_check_requires_manage_options(): void {
		$GLOBALS['son100_htmln_test_can_default'] = false;
		$this->assertFalse(
			$this->controller()->permission_check_manage_options( new WP_REST_Request() )
		);
	}

	// =========================================================================
	//  Helpers de tests
	// =========================================================================

	/**
	 * @param array<int, array<string, mixed>> $presets
	 * @param string                           $id
	 * @return array<string, mixed>
	 */
	private function find_preset( array $presets, string $id ): array {
		foreach ( $presets as $preset ) {
			if ( $id === $preset['id'] ) {
				return $preset;
			}
		}
		$this->fail( "Preset $id not found in response." );
	}
}
