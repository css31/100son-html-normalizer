<?php
/**
 * Tests PostsController — Phase 5.4 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;
use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Posts\PostNormalizer;
use Cent_Son\Html_Normalizer\Core\Posts\SiteOriginDetector;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Rest\PostsController;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use WP_Post;
use WP_REST_Request;

final class PostsControllerTest extends TestCase {

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;
		$GLOBALS['son100_htmln_options']          = array();
	}

	// =========================================================================
	//  Helpers stubs.
	// =========================================================================

	/**
	 * Settings stub avec post_types F8 configurables.
	 *
	 * @param list<string> $f8_selection Sélection F8 simulée.
	 */
	private function settings_stub( array $f8_selection = array( 'post' ) ): SettingsRepository {
		return new class( $f8_selection ) extends SettingsRepository {
			/** @param list<string> $f8 */
			public function __construct( private array $f8 ) {}
			public function get_f8_post_types_selection(): array {
				return $this->f8;
			}
			public function is_preset_enabled( string $preset_id ): bool { return false; }
			public function get_preset_config( string $preset_id ): array { return array(); }
		};
	}

	/**
	 * SiteOriginDetector stub avec carte post_id → has_panels_data.
	 *
	 * @param array<int, bool> $map
	 */
	private function so_stub( array $map = array() ): SiteOriginDetector {
		return new class( $map ) extends SiteOriginDetector {
			/** @param array<int, bool> $map */
			public function __construct( private array $map ) {}
			public function has_panels_data( int $post_id ): bool {
				return $this->map[ $post_id ] ?? false;
			}
		};
	}

	/**
	 * PostNormalizer stub avec retours scriptés par méthode.
	 *
	 * @param array<string, callable> $overrides
	 */
	private function normalizer_stub( array $overrides = array() ): PostNormalizer {
		$settings   = new SettingsRepository();
		$registry   = new PresetRegistry( $settings );
		$normalizer = new HtmlNormalizer( $registry, new Pipeline() );
		$detector   = new SiteOriginDetector();

		return new class( $normalizer, $detector, $overrides ) extends PostNormalizer {
			/** @param array<string, callable> $overrides */
			public function __construct(
				HtmlNormalizer $normalizer,
				SiteOriginDetector $detector,
				private array $overrides
			) {
				parent::__construct( $normalizer, $detector, null );
			}
			public function preview( int $post_id ): array {
				return isset( $this->overrides['preview'] )
					? ( $this->overrides['preview'] )( $post_id )
					: parent::preview( $post_id );
			}
			public function normalize_post( int $post_id, bool $force_siteorigin = false ): array {
				return isset( $this->overrides['normalize_post'] )
					? ( $this->overrides['normalize_post'] )( $post_id, $force_siteorigin )
					: parent::normalize_post( $post_id, $force_siteorigin );
			}
		};
	}

	private function seed_post( int $id, string $type = 'post', string $title = 'Test' ): void {
		$p                = new WP_Post();
		$p->ID            = $id;
		$p->post_content  = '<p>x</p>';
		$p->post_title    = $title;
		$p->post_type     = $type;
		$p->post_status   = 'publish';
		$p->post_modified = '2026-05-09 10:00:00';
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $p;
	}

	private function make_request( string $method = 'GET', array $params = array() ): WP_REST_Request {
		$req = new WP_REST_Request( $method, '/posts' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function make_controller(
		?SettingsRepository $settings = null,
		?PostNormalizer $normalizer = null,
		?SiteOriginDetector $detector = null
	): PostsController {
		return new PostsController(
			$settings   ?? $this->settings_stub(),
			$normalizer ?? $this->normalizer_stub(),
			$detector   ?? $this->so_stub(),
		);
	}

	// =========================================================================
	//  register_routes
	// =========================================================================

	public function test_register_routes_creates_three_endpoints(): void {
		// Post-V0.1 (2026-05-16) : 3 endpoints actifs (post-types, scan,
		// batch-normalize). Les routes `preview` et `normalize` unitaires ont
		// été retirées avec leurs handlers — n'étaient consommées que par
		// PostsPage V0.1 supprimée.
		$this->make_controller()->register_routes();
		$this->assertCount( 3, $GLOBALS['son100_htmln_test_rest_routes'] );
	}

	// =========================================================================
	//  GET /posts/post-types
	// =========================================================================

	public function test_get_post_types_returns_public_types_with_default_checked(): void {
		// settings_stub default = ['post'] → post est checked, page non.
		$response = $this->make_controller(
			$this->settings_stub( array( 'post' ) )
		)->get_post_types_list( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertCount( 2, $body, 'stub bootstrap retourne post + page' );

		$by_slug = array();
		foreach ( $body as $entry ) {
			$by_slug[ $entry['slug'] ] = $entry;
		}
		$this->assertTrue( $by_slug['post']['default_checked'] );
		$this->assertFalse( $by_slug['page']['default_checked'] );
		$this->assertSame( 'Post', $by_slug['post']['label'] );
	}

	// =========================================================================
	//  GET /posts/scan
	// =========================================================================

	public function test_scan_returns_paginated_envelope_with_has_panels_data(): void {
		$this->seed_post( 100 );
		$this->seed_post( 101 );
		$detector = $this->so_stub( array( 100 => true, 101 => false ) );

		$response = $this->make_controller(
			null,
			null,
			$detector
		)->scan( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 2, $body['total'] );
		$this->assertCount( 2, $body['items'] );

		$by_id = array();
		foreach ( $body['items'] as $item ) {
			$by_id[ $item['id'] ] = $item;
		}
		$this->assertTrue( $by_id[100]['has_panels_data'] );
		$this->assertFalse( $by_id[101]['has_panels_data'] );
	}

	public function test_scan_falls_back_to_f8_defaults_when_post_type_missing(): void {
		$this->seed_post( 100, 'post' );
		$this->seed_post( 200, 'page' );
		// Settings F8 = ['post'] → seul post 100 doit apparaître.
		$response = $this->make_controller(
			$this->settings_stub( array( 'post' ) )
		)->scan( $this->make_request() );

		$body = $response->get_data();
		$this->assertSame( 1, $body['total'] );
		$this->assertSame( 100, $body['items'][0]['id'] );
	}

	public function test_scan_caps_per_page(): void {
		$this->seed_post( 100 );
		$response = $this->make_controller()->scan(
			$this->make_request( 'GET', array( 'per_page' => 9999 ) )
		);
		$this->assertSame( PostsController::MAX_PER_PAGE, $response->get_data()['per_page'] );
	}

	public function test_scan_invalid_post_type_falls_back_to_defaults(): void {
		// post_type[] = ['nonexistent'] : aucun ne matche → bascule sur F8 defaults.
		$this->seed_post( 100, 'post' );
		$response = $this->make_controller(
			$this->settings_stub( array( 'post' ) )
		)->scan( $this->make_request( 'GET', array( 'post_type' => array( 'nonexistent' ) ) ) );

		$this->assertSame( 1, $response->get_data()['total'] );
	}

	// =========================================================================
	//  POST /posts/batch-normalize
	// =========================================================================

	public function test_batch_normalize_aggregates_summary(): void {
		$dispatch = array(
			100 => PostNormalizer::STATUS_MODIFIED,
			101 => PostNormalizer::STATUS_UNCHANGED,
			102 => PostNormalizer::STATUS_SKIPPED_SO,
			103 => PostNormalizer::STATUS_ERROR_WRITE,
		);
		$normalizer = $this->normalizer_stub( array(
			'normalize_post' => fn( $id ) => array(
				'status'          => $dispatch[ $id ] ?? PostNormalizer::STATUS_ERROR_NOT_FOUND,
				'has_panels_data' => false,
			),
		) );
		$response = $this->make_controller( null, $normalizer )->batch_normalize(
			$this->make_request( 'POST', array( 'ids' => array( 100, 101, 102, 103 ) ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$summary = $response->get_data()['summary'];
		$this->assertSame( 1, $summary['modified'] );
		$this->assertSame( 1, $summary['unchanged'] );
		$this->assertSame( 1, $summary['skipped_siteorigin'] );
		$this->assertSame( 1, $summary['errors'] );
	}

	public function test_batch_normalize_400_for_empty_ids(): void {
		$response = $this->make_controller()->batch_normalize(
			$this->make_request( 'POST', array( 'ids' => array() ) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_ids', $response->get_data()['code'] );
	}

	public function test_batch_normalize_passes_force_to_each_call(): void {
		$received   = array();
		$normalizer = $this->normalizer_stub( array(
			'normalize_post' => function ( $id, $force ) use ( &$received ) {
				$received[ $id ] = $force;
				return array( 'status' => PostNormalizer::STATUS_MODIFIED, 'has_panels_data' => false );
			},
		) );
		$this->make_controller( null, $normalizer )->batch_normalize(
			$this->make_request( 'POST', array( 'ids' => array( 100, 101 ), 'force_siteorigin' => true ) )
		);
		$this->assertTrue( $received[100] );
		$this->assertTrue( $received[101] );
	}
}
