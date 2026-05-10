<?php
/**
 * Tests DiffController — Phase 5.4 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Rest;

use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Rest\DiffController;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use WP_Post;
use WP_REST_Request;

final class DiffControllerTest extends TestCase {

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$GLOBALS['son100_htmln_test_rest_routes'] = array();
		$GLOBALS['son100_htmln_test_caps']        = array();
		$GLOBALS['son100_htmln_test_can_default'] = true;
	}

	private function registry_with( array $rules ): PresetRegistry {
		return new class( $rules ) extends PresetRegistry {
			/** @param list<RuleInterface> $rules */
			public function __construct( private array $rules ) {
				parent::__construct( new SettingsRepository() );
			}
			public function get_enabled_rules(): array {
				return $this->rules;
			}
		};
	}

	private function fake_rule( string $id, callable $transform ): RuleInterface {
		return new class( $id, $transform ) implements RuleInterface {
			public function __construct( private string $rule_id, private mixed $transform ) {}
			public function id(): string { return $this->rule_id; }
			public function label(): string { return $this->rule_id; }
			public function apply( string $html, array $context = array() ): string {
				return ( $this->transform )( $html );
			}
			public function countMatches( string $html, array $context = array() ): int { return 0; }
		};
	}

	private function seed_post( int $id, string $content ): void {
		$p                = new WP_Post();
		$p->ID            = $id;
		$p->post_content  = $content;
		$p->post_type     = 'post';
		$p->post_status   = 'publish';
		Son100_Htmln_Test_Posts_Registry::$posts[ $id ] = $p;
	}

	private function make_request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request( 'POST', '/posts/diff' );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function make_controller( array $rules = array() ): DiffController {
		return new DiffController(
			$this->registry_with( $rules ),
			new Pipeline(),
			new MetricsCalculator(),
		);
	}

	// =========================================================================
	//  register_routes
	// =========================================================================

	public function test_register_routes_creates_one_endpoint(): void {
		$this->make_controller()->register_routes();
		$this->assertCount( 1, $GLOBALS['son100_htmln_test_rest_routes'] );
		$this->assertSame( 'htmln/v1', $GLOBALS['son100_htmln_test_rest_routes'][0]['namespace'] );
		$this->assertSame( '/posts/(?P<id>\d+)/diff', $GLOBALS['son100_htmln_test_rest_routes'][0]['route'] );
	}

	// =========================================================================
	//  compute_diff
	// =========================================================================

	public function test_compute_diff_returns_before_after_and_metrics(): void {
		$this->seed_post( 100, '<p>Original</p>' );
		$rule = $this->fake_rule(
			'P1',
			static fn( string $html ): string => str_replace( 'Original', 'Modifié', $html )
		);

		$response = $this->make_controller( array( $rule ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'P1' ) ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( '<p>Original</p>', $body['html_before'] );
		$this->assertSame( '<p>Modifié</p>', $body['html_after'] );
		$this->assertArrayHasKey( 'paragraphs', $body['metrics_before'] );
		$this->assertArrayHasKey( 'paragraphs', $body['metrics_after'] );
		$this->assertFalse( $body['unchanged'] );
	}

	public function test_compute_diff_marks_unchanged_when_html_identical(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$noop = $this->fake_rule( 'P1', static fn( string $h ): string => $h );

		$response = $this->make_controller( array( $noop ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'P1' ) ) )
		);

		$this->assertTrue( $response->get_data()['unchanged'] );
	}

	public function test_compute_diff_does_not_create_revision_or_write(): void {
		// Garde-fou §13 : le diff est preview-only, pas d'effet sur post_content.
		$this->seed_post( 100, '<p>Original</p>' );
		$rule = $this->fake_rule( 'P1', static fn(): string => '<p>Tout effacé</p>' );

		$this->make_controller( array( $rule ) )->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'P1' ) ) )
		);

		$this->assertSame(
			'<p>Original</p>',
			Son100_Htmln_Test_Posts_Registry::$posts[100]->post_content,
			'post_content doit rester intact (diff preview-only)'
		);
		$this->assertArrayNotHasKey( 100, Son100_Htmln_Test_Posts_Registry::$updates );
		$this->assertArrayNotHasKey( 100, Son100_Htmln_Test_Posts_Registry::$revisions_created );
	}

	public function test_compute_diff_400_for_empty_rule_ids(): void {
		$this->seed_post( 100, '<p>x</p>' );
		$response = $this->make_controller()->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array() ) )
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_rule_ids', $response->get_data()['code'] );
	}

	public function test_compute_diff_404_for_unknown_post(): void {
		$response = $this->make_controller()->compute_diff(
			$this->make_request( array( 'id' => 999, 'rule_ids' => array( 'P1' ) ) )
		);
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'post_not_found', $response->get_data()['code'] );
	}

	public function test_compute_diff_ignores_unknown_rule_ids(): void {
		// applySubset ignore silencieusement les ids inconnus → pas d'erreur.
		$this->seed_post( 100, '<p>x</p>' );
		$response = $this->make_controller()->compute_diff(
			$this->make_request( array( 'id' => 100, 'rule_ids' => array( 'P_UNKNOWN' ) ) )
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['unchanged'] );
	}
}
