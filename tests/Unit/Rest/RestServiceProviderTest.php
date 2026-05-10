<?php
/**
 * Tests RestServiceProvider — Phase 5.1 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Rest\BaseController;
use Cent_Son\Html_Normalizer\Rest\RestServiceProvider;
use PHPUnit\Framework\TestCase;

final class RestServiceProviderTest extends TestCase {

	protected function setUp(): void {
		// Reset les registres globals du bootstrap entre tests : actions
		// (provider register) ET routes REST (autres tests Rest peuvent
		// y avoir écrit en exécution random).
		$GLOBALS['son100_htmln_test_actions']     = array();
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
	}

	/**
	 * Crée un contrôleur stub qui incrémente un compteur quand
	 * `register_routes()` est appelée.
	 */
	private function make_counting_controller( object $tracker ): BaseController {
		return new class( $tracker ) extends BaseController {
			public function __construct( private object $tracker ) {}
			public function register_routes(): void {
				$this->tracker->count = ( $this->tracker->count ?? 0 ) + 1;
			}
		};
	}

	public function test_register_branches_rest_api_init_hook(): void {
		$provider = new RestServiceProvider( array() );
		$provider->register();

		$this->assertArrayHasKey( 'rest_api_init', $GLOBALS['son100_htmln_test_actions'] );
		$this->assertCount( 1, $GLOBALS['son100_htmln_test_actions']['rest_api_init'] );

		$registered = $GLOBALS['son100_htmln_test_actions']['rest_api_init'][0];
		$this->assertIsArray( $registered['callback'] );
		$this->assertSame( $provider, $registered['callback'][0] );
		$this->assertSame( 'register_all_routes', $registered['callback'][1] );
	}

	public function test_register_is_idempotent(): void {
		$provider = new RestServiceProvider( array() );

		$provider->register();
		$provider->register();
		$provider->register();

		$this->assertCount(
			1,
			$GLOBALS['son100_htmln_test_actions']['rest_api_init'],
			'register() doit être idempotent : un seul add_action quel que soit le nombre d\'appels'
		);
	}

	public function test_register_all_routes_calls_register_routes_on_each_controller(): void {
		$tracker_a = new \stdClass();
		$tracker_b = new \stdClass();
		$controller_a = $this->make_counting_controller( $tracker_a );
		$controller_b = $this->make_counting_controller( $tracker_b );

		$provider = new RestServiceProvider( array( $controller_a, $controller_b ) );
		$provider->register_all_routes();

		$this->assertSame( 1, $tracker_a->count ?? 0 );
		$this->assertSame( 1, $tracker_b->count ?? 0 );
	}

	public function test_register_all_routes_respects_input_order(): void {
		$order = array();
		$make  = static function ( string $id ) use ( &$order ): BaseController {
			return new class( $id, $order ) extends BaseController {
				/** @param list<string> $order */
				public function __construct( private string $id, private array &$order ) {}
				public function register_routes(): void {
					$this->order[] = $this->id;
				}
			};
		};

		$provider = new RestServiceProvider( array( $make( 'first' ), $make( 'second' ), $make( 'third' ) ) );
		$provider->register_all_routes();

		$this->assertSame( array( 'first', 'second', 'third' ), $order );
	}

	public function test_controllers_returns_input_list(): void {
		$tracker = new \stdClass();
		$ctrl    = $this->make_counting_controller( $tracker );

		$provider = new RestServiceProvider( array( $ctrl ) );
		$this->assertSame( array( $ctrl ), $provider->controllers() );
	}

	public function test_register_with_empty_list_branches_hook_anyway(): void {
		// Cohérent avec Phase 5.1 où la liste est vide mais l'infra doit
		// quand même être en place — extension Phase 5.2-5.4 sans toucher au boot.
		$provider = new RestServiceProvider( array() );
		$provider->register();

		$this->assertArrayHasKey( 'rest_api_init', $GLOBALS['son100_htmln_test_actions'] );
	}

	public function test_register_all_routes_with_empty_list_is_noop(): void {
		$provider = new RestServiceProvider( array() );
		// Ne doit pas throw même si aucun contrôleur.
		$provider->register_all_routes();
		$this->assertSame( array(), $GLOBALS['son100_htmln_test_rest_routes'] );
	}
}
