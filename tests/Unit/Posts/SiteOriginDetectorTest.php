<?php
/**
 * Tests SiteOriginDetector.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Posts;

use Cent_Son\Html_Normalizer\Core\Posts\SiteOriginDetector;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Posts_Registry;
use WP_Post;

final class SiteOriginDetectorTest extends TestCase {

	private SiteOriginDetector $detector;

	protected function setUp(): void {
		Son100_Htmln_Test_Posts_Registry::reset();
		$this->detector = new SiteOriginDetector();

		$post = new WP_Post();
		$post->ID = 100;
		Son100_Htmln_Test_Posts_Registry::$posts[ 100 ] = $post;
	}

	public function test_returns_false_when_no_meta(): void {
		$this->assertFalse( $this->detector->has_panels_data( 100 ) );
	}

	public function test_returns_false_when_meta_is_empty_string(): void {
		Son100_Htmln_Test_Posts_Registry::$meta[ 100 ]['panels_data'] = '';
		$this->assertFalse( $this->detector->has_panels_data( 100 ) );
	}

	public function test_returns_false_when_meta_is_empty_array(): void {
		Son100_Htmln_Test_Posts_Registry::$meta[ 100 ]['panels_data'] = [];
		$this->assertFalse( $this->detector->has_panels_data( 100 ) );
	}

	public function test_returns_true_when_meta_is_non_empty_array(): void {
		Son100_Htmln_Test_Posts_Registry::$meta[ 100 ]['panels_data'] = [ 'widgets' => [], 'grids' => [] ];
		$this->assertTrue( $this->detector->has_panels_data( 100 ) );
	}

	public function test_returns_true_for_typical_siteorigin_structure(): void {
		Son100_Htmln_Test_Posts_Registry::$meta[ 100 ]['panels_data'] = [
			'widgets' => [
				[ 'panels_info' => [ 'class' => 'SiteOrigin_Widget_Editor_Widget' ] ],
			],
			'grids'   => [ [] ],
		];
		$this->assertTrue( $this->detector->has_panels_data( 100 ) );
	}
}
