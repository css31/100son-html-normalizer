<?php
/**
 * Tests MetricsSnapshot — Phase 2.3 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Metrics;

use Cent_Son\Html_Normalizer\Metrics\MetricsSnapshot;
use PHPUnit\Framework\TestCase;

final class MetricsSnapshotTest extends TestCase {

	public function test_zero_returns_all_metrics_at_zero(): void {
		$z = MetricsSnapshot::zero();
		$this->assertSame( 0, $z->chars );
		$this->assertSame( 0, $z->words );
		$this->assertSame( 0, $z->paragraphs );
		$this->assertSame( 0, $z->images );
		$this->assertSame( 0, $z->links );
		$this->assertSame( 0, $z->lists );
		$this->assertSame(
			array( 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ),
			$z->headings
		);
	}

	public function test_to_array_returns_expected_structure(): void {
		$snap = new MetricsSnapshot(
			chars: 100,
			words: 20,
			paragraphs: 3,
			headings: array( 'h1' => 1, 'h2' => 2, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ),
			images: 4,
			links: 5,
			lists: 6,
		);
		$arr = $snap->toArray();
		$this->assertSame( 100, $arr['chars'] );
		$this->assertSame( 20, $arr['words'] );
		$this->assertSame( 3, $arr['paragraphs'] );
		$this->assertSame( array( 'h1' => 1, 'h2' => 2, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ), $arr['headings'] );
		$this->assertSame( 4, $arr['images'] );
		$this->assertSame( 5, $arr['links'] );
		$this->assertSame( 6, $arr['lists'] );
	}

	public function test_round_trip_to_array_from_array(): void {
		$original = new MetricsSnapshot(
			chars: 1024,
			words: 150,
			paragraphs: 12,
			headings: array( 'h1' => 1, 'h2' => 4, 'h3' => 2, 'h4' => 0, 'h5' => 0, 'h6' => 0 ),
			images: 8,
			links: 15,
			lists: 3,
		);
		$decoded = MetricsSnapshot::fromArray( $original->toArray() );
		$this->assertSame( $original->chars, $decoded->chars );
		$this->assertSame( $original->words, $decoded->words );
		$this->assertSame( $original->paragraphs, $decoded->paragraphs );
		$this->assertSame( $original->headings, $decoded->headings );
		$this->assertSame( $original->images, $decoded->images );
		$this->assertSame( $original->links, $decoded->links );
		$this->assertSame( $original->lists, $decoded->lists );
	}

	public function test_from_array_tolerant_to_missing_keys(): void {
		$snap = MetricsSnapshot::fromArray( array( 'chars' => 50 ) );
		$this->assertSame( 50, $snap->chars );
		$this->assertSame( 0, $snap->words );
		$this->assertSame( 0, $snap->paragraphs );
		$this->assertSame( array( 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ), $snap->headings );
	}

	public function test_from_array_tolerant_to_partial_headings(): void {
		$snap = MetricsSnapshot::fromArray( array( 'headings' => array( 'h2' => 5 ) ) );
		$this->assertSame( 0, $snap->headings['h1'] );
		$this->assertSame( 5, $snap->headings['h2'] );
		$this->assertSame( 0, $snap->headings['h6'] );
	}

	public function test_from_array_coerces_string_numbers(): void {
		// Defensif : si JSON décode des "5" en string, on les coerce en int.
		$snap = MetricsSnapshot::fromArray( array(
			'chars'    => '500',
			'words'    => '80',
			'headings' => array( 'h2' => '3' ),
		) );
		$this->assertSame( 500, $snap->chars );
		$this->assertSame( 80, $snap->words );
		$this->assertSame( 3, $snap->headings['h2'] );
	}

	public function test_total_headings_sums_all_levels(): void {
		$snap = new MetricsSnapshot(
			chars: 0, words: 0, paragraphs: 0,
			headings: array( 'h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6 ),
			images: 0, links: 0, lists: 0,
		);
		$this->assertSame( 21, $snap->totalHeadings() );
	}

	public function test_immutability_via_readonly_properties(): void {
		$snap = MetricsSnapshot::zero();
		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line property.readonly
		$snap->chars = 99;
	}
}
