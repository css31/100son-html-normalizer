<?php
/**
 * Tests SettingsController — Phase 6.7.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Rest\SettingsController;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class SettingsControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;
		$GLOBALS['son100_htmln_options']          = array();
	}

	private function controller(): SettingsController {
		return new SettingsController( new SettingsRepository() );
	}

	// =========================================================================
	//  register_routes
	// =========================================================================

	public function test_register_routes_registers_both_endpoint_pairs(): void {
		$this->controller()->register_routes();
		$this->assertCount( 2, $GLOBALS['son100_htmln_test_rest_routes'] );
		$routes = array_column(
			$GLOBALS['son100_htmln_test_rest_routes'],
			null,
			'route'
		);
		$this->assertArrayHasKey( '/settings/regression-thresholds', $routes );
		$this->assertArrayHasKey( '/settings/external-sites', $routes );
		foreach ( $routes as $entry ) {
			$this->assertSame( 'htmln/v1', $entry['namespace'] );
			// `args` est une liste de méthodes (GET + POST) — pattern WP REST
			// multi-handler par route.
			$methods = array_map(
				static fn( array $row ): string => $row['methods'],
				$entry['args']
			);
			$this->assertContains( 'GET', $methods );
			$this->assertContains( 'POST', $methods );
		}
	}

	public function test_register_routes_uses_manage_options_permission(): void {
		$controller = $this->controller();
		$controller->register_routes();
		foreach ( $GLOBALS['son100_htmln_test_rest_routes'] as $entry ) {
			foreach ( $entry['args'] as $row ) {
				$this->assertIsArray( $row['permission_callback'] );
				$this->assertSame( $controller, $row['permission_callback'][0] );
				$this->assertSame(
					'permission_check_manage_options',
					$row['permission_callback'][1]
				);
			}
		}
	}

	// =========================================================================
	//  GET /settings/regression-thresholds
	// =========================================================================

	public function test_get_returns_defaults_when_option_empty(): void {
		$response = $this->controller()->get_regression_thresholds( new WP_REST_Request() );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame(
			SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS,
			$body['thresholds']
		);
		$this->assertSame(
			SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS,
			$body['defaults']
		);
	}

	public function test_get_returns_persisted_overrides(): void {
		update_option(
			'son100_htmln_settings',
			array(
				'regression_thresholds' => array(
					'text_loss_pct' => 7,
					'images_loss'   => 3,
				),
			)
		);
		$response = $this->controller()->get_regression_thresholds( new WP_REST_Request() );
		$body     = $response->get_data();
		$this->assertSame( 7, $body['thresholds']['text_loss_pct'] );
		$this->assertSame( 3, $body['thresholds']['images_loss'] );
		// Defaults toujours retournés tels quels (non écrasés par les overrides).
		$this->assertSame(
			SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS,
			$body['defaults']
		);
	}

	// =========================================================================
	//  POST /settings/regression-thresholds
	// =========================================================================

	public function test_post_writes_and_returns_normalized_payload(): void {
		$request = new WP_REST_Request();
		$request->set_param(
			'thresholds',
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
		$response = $this->controller()->update_regression_thresholds( $request );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 3, $body['thresholds']['text_loss_pct'] );
		$this->assertSame( 10, $body['thresholds']['paragraphs_loss_pct'] );
		// Persistance confirmée.
		$settings = get_option( 'son100_htmln_settings', array() );
		$this->assertSame(
			3,
			$settings['regression_thresholds']['text_loss_pct']
		);
	}

	public function test_post_400_when_thresholds_missing(): void {
		$response = $this->controller()->update_regression_thresholds( new WP_REST_Request() );
		$this->assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'invalid_thresholds', $body['code'] );
	}

	public function test_post_400_when_thresholds_not_array(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'thresholds', 'oops' );
		$response = $this->controller()->update_regression_thresholds( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_post_silently_normalizes_invalid_individual_values(): void {
		$request = new WP_REST_Request();
		$request->set_param(
			'thresholds',
			array(
				'text_loss_pct' => 'oops',
				'images_loss'   => -5,
				'unknown_key'   => 42,
			)
		);
		$response = $this->controller()->update_regression_thresholds( $request );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		// Invalides → defauts, unknown_key ignorée.
		$this->assertSame( 0, $body['thresholds']['text_loss_pct'] );
		$this->assertSame( 0, $body['thresholds']['images_loss'] );
		$this->assertArrayNotHasKey( 'unknown_key', $body['thresholds'] );
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
	//  GET /settings/external-sites
	// =========================================================================

	public function test_get_external_sites_returns_defaults_when_option_empty(): void {
		$response = $this->controller()->get_external_sites( new WP_REST_Request() );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame(
			SettingsRepository::EXTERNAL_SITES_DEFAULTS,
			$body['sites']
		);
		$this->assertSame(
			SettingsRepository::EXTERNAL_SITES_DEFAULTS,
			$body['defaults']
		);
	}

	public function test_get_external_sites_returns_persisted_overrides(): void {
		update_option(
			'son100_htmln_settings',
			array(
				'external_sites' => array(
					'old_url'  => 'https://staging.example.com',
					'prod_url' => 'https://www.example.com',
				),
			)
		);
		$response = $this->controller()->get_external_sites( new WP_REST_Request() );
		$body     = $response->get_data();
		$this->assertSame( 'https://staging.example.com', $body['sites']['old_url'] );
		$this->assertSame( 'https://www.example.com', $body['sites']['prod_url'] );
		// Defaults toujours retournés tels quels.
		$this->assertSame(
			SettingsRepository::EXTERNAL_SITES_DEFAULTS,
			$body['defaults']
		);
	}

	// =========================================================================
	//  POST /settings/external-sites
	// =========================================================================

	public function test_post_external_sites_writes_and_returns_normalized_payload(): void {
		$request = new WP_REST_Request();
		$request->set_param(
			'sites',
			array(
				'old_url'  => 'https://staging.example.com/',
				'prod_url' => 'https://www.example.com',
			)
		);
		$response = $this->controller()->update_external_sites( $request );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		// Le slash final a été retiré.
		$this->assertSame( 'https://staging.example.com', $body['sites']['old_url'] );
		$this->assertSame( 'https://www.example.com', $body['sites']['prod_url'] );
		// Persistance confirmée.
		$settings = get_option( 'son100_htmln_settings', array() );
		$this->assertSame(
			'https://staging.example.com',
			$settings['external_sites']['old_url']
		);
	}

	public function test_post_external_sites_400_when_sites_missing(): void {
		$response = $this->controller()->update_external_sites( new WP_REST_Request() );
		$this->assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'invalid_sites', $body['code'] );
	}

	public function test_post_external_sites_400_when_sites_not_array(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'sites', 'oops' );
		$response = $this->controller()->update_external_sites( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_post_external_sites_silently_normalizes_invalid_values(): void {
		$request = new WP_REST_Request();
		$request->set_param(
			'sites',
			array(
				'old_url'   => 'javascript:alert(1)',
				'prod_url'  => 'not a url',
				'evil_key'  => 'https://evil.example.com',
			)
		);
		$response = $this->controller()->update_external_sites( $request );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		// Invalides → defauts, evil_key ignorée.
		$this->assertSame( 'https://old.ma-maison-mag.fr', $body['sites']['old_url'] );
		$this->assertSame( 'https://ma-maison-mag.fr', $body['sites']['prod_url'] );
		$this->assertArrayNotHasKey( 'evil_key', $body['sites'] );
	}
}
