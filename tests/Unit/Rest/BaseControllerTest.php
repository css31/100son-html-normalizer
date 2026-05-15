<?php
/**
 * Tests BaseController — Phase 5.1 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Rest\BaseController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class BaseControllerTest extends TestCase {

	private BaseController $controller;

	protected function setUp(): void {
		// Reset capability stubs entre tests.
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;

		// Concrete subclass minimaliste — register_routes est no-op.
		$this->controller = new class extends BaseController {
			public function register_routes(): void {}

			// Expose les helpers protected pour les assertions.
			public function call_respond( mixed $data, int $status = 200 ): WP_REST_Response {
				return $this->respond( $data, $status );
			}
			public function call_rest_error( string $code, string $message, int $status = 400, array $extra = array() ): WP_REST_Response {
				return $this->rest_error( $code, $message, $status, $extra );
			}
			public function call_rest_error_from_wp( WP_Error $error, int $status = 400 ): WP_REST_Response {
				return $this->rest_error_from_wp( $error, $status );
			}
			public function call_sanitize_int_list( mixed $value ): array {
				return $this->sanitize_int_list( $value );
			}
			public function call_sanitize_string_list( mixed $value ): array {
				return $this->sanitize_string_list( $value );
			}
		};
	}

	// =========================================================================
	//  Constantes
	// =========================================================================

	public function test_namespace_constant_is_htmln_v1(): void {
		$this->assertSame( 'htmln/v1', BaseController::REST_NAMESPACE );
	}

	public function test_capability_constant_is_manage_options(): void {
		// Cf. cahier §14 hyp. 14.
		$this->assertSame( 'manage_options', BaseController::CAPABILITY );
	}

	// =========================================================================
	//  permission_check_manage_options
	// =========================================================================

	public function test_permission_check_returns_true_when_user_has_capability(): void {
		$GLOBALS['son100_htmln_test_caps']['manage_options'] = true;
		$this->assertTrue(
			$this->controller->permission_check_manage_options( new WP_REST_Request() )
		);
	}

	public function test_permission_check_returns_false_when_user_lacks_capability(): void {
		$GLOBALS['son100_htmln_test_caps']['manage_options'] = false;
		$this->assertFalse(
			$this->controller->permission_check_manage_options( new WP_REST_Request() )
		);
	}

	// =========================================================================
	//  respond / rest_error
	// =========================================================================

	public function test_respond_returns_wp_rest_response_with_data_and_default_status(): void {
		$response = $this->controller->call_respond( array( 'foo' => 'bar' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'foo' => 'bar' ), $response->get_data() );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_respond_supports_custom_status(): void {
		$response = $this->controller->call_respond( null, 201 );
		$this->assertSame( 201, $response->get_status() );
	}

	public function test_rest_error_returns_response_with_code_message_status(): void {
		$response = $this->controller->call_rest_error( 'invalid_input', 'Mauvaise entrée', 400 );

		$this->assertSame( 400, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'invalid_input', $body['code'] );
		$this->assertSame( 'Mauvaise entrée', $body['message'] );
		$this->assertSame( 400, $body['data']['status'] );
	}

	public function test_rest_error_merges_extra_data(): void {
		$response = $this->controller->call_rest_error(
			'siteorigin_detected',
			'panels_data présent',
			409,
			array( 'post_id' => 42, 'has_panels_data' => true )
		);
		$body = $response->get_data();

		$this->assertSame( 409, $body['data']['status'] );
		$this->assertSame( 42, $body['data']['post_id'] );
		$this->assertTrue( $body['data']['has_panels_data'] );
	}

	public function test_rest_error_from_wp_uses_error_code_and_message(): void {
		$response = $this->controller->call_rest_error_from_wp(
			new WP_Error( 'db_down', 'Database unavailable' ),
			500
		);
		$body = $response->get_data();

		$this->assertSame( 'db_down', $body['code'] );
		$this->assertSame( 'Database unavailable', $body['message'] );
		$this->assertSame( 500, $response->get_status() );
	}

	// =========================================================================
	//  sanitize_int_list
	// =========================================================================

	public function test_sanitize_int_list_casts_numeric_values(): void {
		$this->assertSame(
			array( 1, 2, 3 ),
			$this->controller->call_sanitize_int_list( array( '1', 2, 3.0 ) )
		);
	}

	public function test_sanitize_int_list_filters_non_numeric_entries(): void {
		$this->assertSame(
			array( 1, 3 ),
			$this->controller->call_sanitize_int_list( array( 1, 'foo', 3, null, array( 'x' ) ) )
		);
	}

	public function test_sanitize_int_list_returns_empty_for_non_array(): void {
		$this->assertSame( array(), $this->controller->call_sanitize_int_list( null ) );
		$this->assertSame( array(), $this->controller->call_sanitize_int_list( 'not-a-list' ) );
		$this->assertSame( array(), $this->controller->call_sanitize_int_list( 42 ) );
	}

	public function test_sanitize_int_list_preserves_order(): void {
		$this->assertSame(
			array( 5, 1, 3 ),
			$this->controller->call_sanitize_int_list( array( 5, 'x', 1, 3 ) )
		);
	}

	// =========================================================================
	//  sanitize_string_list
	// =========================================================================

	public function test_sanitize_string_list_strips_tags_and_trims(): void {
		$this->assertSame(
			array( 'R1', 'R5' ),
			$this->controller->call_sanitize_string_list( array( '  <b>R1</b>', 'R5' ) )
		);
	}

	public function test_sanitize_string_list_filters_empty_after_sanitize(): void {
		$this->assertSame(
			array( 'R1' ),
			$this->controller->call_sanitize_string_list( array( '', '   ', 'R1', null ) )
		);
	}

	public function test_sanitize_string_list_returns_empty_for_non_array(): void {
		$this->assertSame( array(), $this->controller->call_sanitize_string_list( null ) );
		$this->assertSame( array(), $this->controller->call_sanitize_string_list( 'R1' ) );
	}
}
