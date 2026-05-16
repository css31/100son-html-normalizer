<?php
/**
 * Tests NotesController — endpoints REST de la note libre riche.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Notes\RichNotesRepository;
use Cent_Son\Html_Normalizer\Rest\NotesController;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class NotesControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;
		$GLOBALS['son100_htmln_options']          = array();
	}

	private function controller(): NotesController {
		return new NotesController( new RichNotesRepository() );
	}

	// =========================================================================
	//  register_routes
	// =========================================================================

	public function test_register_routes_registers_single_endpoint_with_three_methods(): void {
		$this->controller()->register_routes();
		$this->assertCount( 1, $GLOBALS['son100_htmln_test_rest_routes'] );
		$entry = $GLOBALS['son100_htmln_test_rest_routes'][0];
		$this->assertSame( 'htmln/v1', $entry['namespace'] );
		$this->assertSame( '/notes', $entry['route'] );

		$methods = array_map(
			static fn( array $row ): string => $row['methods'],
			$entry['args']
		);
		$this->assertContains( 'GET', $methods );
		$this->assertContains( 'PUT', $methods );
		$this->assertContains( 'DELETE', $methods );
	}

	public function test_register_routes_uses_manage_options_permission(): void {
		// Post-v1.0.0 : GET reste sur manage_options, PUT/DELETE basculent
		// sur permission_check_locked (verrou single-user).
		$controller = $this->controller();
		$controller->register_routes();
		$entry = $GLOBALS['son100_htmln_test_rest_routes'][0];
		foreach ( $entry['args'] as $row ) {
			$this->assertIsArray( $row['permission_callback'] );
			$this->assertSame( $controller, $row['permission_callback'][0] );
			$expected = 'GET' === $row['methods']
				? 'permission_check_manage_options'
				: 'permission_check_locked';
			$this->assertSame( $expected, $row['permission_callback'][1] );
		}
	}

	// =========================================================================
	//  GET /notes
	// =========================================================================

	public function test_get_returns_empty_string_when_no_notes_yet(): void {
		$response = $this->controller()->get_notes( new WP_REST_Request() );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( '', $body['content'] );
	}

	public function test_get_returns_persisted_block_grammar(): void {
		$grammar = "<!-- wp:paragraph -->\n<p>Note de test</p>\n<!-- /wp:paragraph -->";
		( new RichNotesRepository() )->set( $grammar );

		$response = $this->controller()->get_notes( new WP_REST_Request() );
		$this->assertSame( $grammar, $response->get_data()['content'] );
	}

	// =========================================================================
	//  PUT /notes
	// =========================================================================

	public function test_put_writes_and_returns_sanitized_content(): void {
		$request = new WP_REST_Request();
		$grammar = "<!-- wp:paragraph -->\n<p>Bonjour</p>\n<!-- /wp:paragraph -->";
		$request->set_param( 'content', $grammar );

		$response = $this->controller()->update_notes( $request );
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		// Le serveur renvoie ce qui est persisté — `wp_kses_post` ne touche
		// pas aux commentaires `<!-- wp:* -->`, contrat critique.
		$this->assertSame( $grammar, $body['content'] );
	}

	public function test_put_strips_dangerous_markup(): void {
		$request = new WP_REST_Request();
		$dirty   = "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->\n<script>alert(1)</script>";
		$request->set_param( 'content', $dirty );

		$response = $this->controller()->update_notes( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( '<script', $response->get_data()['content'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $response->get_data()['content'] );
	}

	public function test_put_accepts_empty_string_content(): void {
		// Sémantique « j'ai vidé l'éditeur et je sauvegarde » — équivalent
		// à clear côté repo mais explicité par PUT plutôt que DELETE.
		$request = new WP_REST_Request();
		$request->set_param( 'content', '' );

		$response = $this->controller()->update_notes( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['content'] );
	}

	public function test_put_400_when_content_missing(): void {
		$response = $this->controller()->update_notes( new WP_REST_Request() );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_content', $response->get_data()['code'] );
	}

	public function test_put_400_when_content_not_string(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'content', array( 'oops' ) );
		$response = $this->controller()->update_notes( $request );
		$this->assertSame( 400, $response->get_status() );
	}

	// =========================================================================
	//  DELETE /notes
	// =========================================================================

	public function test_delete_clears_and_returns_empty_content(): void {
		( new RichNotesRepository() )->set( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );

		$response = $this->controller()->delete_notes( new WP_REST_Request() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', $response->get_data()['content'] );
		// Persistance : la note est bien vidée.
		$this->assertSame( '', ( new RichNotesRepository() )->get() );
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
}
