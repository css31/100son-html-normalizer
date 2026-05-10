<?php
/**
 * Tests RegressionReport + RegressionFailure — Phase 3.1 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Regression;

use Cent_Son\Html_Normalizer\Regression\RegressionFailure;
use Cent_Son\Html_Normalizer\Regression\RegressionReport;
use PHPUnit\Framework\TestCase;

final class RegressionReportTest extends TestCase {

	private function failure( string $key, int $loss = 1 ): RegressionFailure {
		return new RegressionFailure(
			metric_key: $key,
			before: 10,
			after: 10 - $loss,
			threshold: 0,
			unit: RegressionFailure::UNIT_ABSOLUTE,
			loss: $loss,
			loss_pct: null,
		);
	}

	public function test_failure_count_returns_size(): void {
		$report = new RegressionReport( array(
			$this->failure( 'images' ),
			$this->failure( 'links' ),
		) );
		$this->assertSame( 2, $report->failure_count() );
	}

	public function test_has_failure_locates_metric(): void {
		$report = new RegressionReport( array( $this->failure( 'images' ) ) );
		$this->assertTrue( $report->has_failure( 'images' ) );
		$this->assertFalse( $report->has_failure( 'links' ) );
	}

	public function test_failure_for_returns_specific_failure(): void {
		$f      = $this->failure( 'links', 3 );
		$report = new RegressionReport( array( $this->failure( 'images' ), $f ) );
		$this->assertSame( $f, $report->failure_for( 'links' ) );
		$this->assertNull( $report->failure_for( 'lists' ) );
	}

	public function test_to_array_serializes_failures(): void {
		$report = new RegressionReport( array(
			$this->failure( 'images', 2 ),
			new RegressionFailure(
				metric_key: 'chars',
				before: 1000,
				after: 950,
				threshold: 0,
				unit: RegressionFailure::UNIT_PCT,
				loss: 50,
				loss_pct: 5.0,
			),
		) );
		$arr = $report->to_array();
		$this->assertCount( 2, $arr['failures'] );
		$this->assertSame( 'images', $arr['failures'][0]['metric_key'] );
		$this->assertSame( 'absolute', $arr['failures'][0]['unit'] );
		$this->assertNull( $arr['failures'][0]['loss_pct'] );
		$this->assertSame( 'chars', $arr['failures'][1]['metric_key'] );
		$this->assertSame( 'pct', $arr['failures'][1]['unit'] );
		$this->assertSame( 5.0, $arr['failures'][1]['loss_pct'] );
		$this->assertTrue( $arr['failures'][0]['exceeded'] );
		$this->assertTrue( $arr['failures'][1]['exceeded'] );
	}

	public function test_failure_to_array_keeps_all_fields(): void {
		$f   = new RegressionFailure(
			metric_key: 'headings.h2',
			before: 5,
			after: 3,
			threshold: 0,
			unit: RegressionFailure::UNIT_ABSOLUTE,
			loss: 2,
			loss_pct: null,
		);
		$arr = $f->to_array();
		$this->assertSame( 'headings.h2', $arr['metric_key'] );
		$this->assertSame( 5, $arr['before'] );
		$this->assertSame( 3, $arr['after'] );
		$this->assertSame( 2, $arr['loss'] );
		$this->assertNull( $arr['loss_pct'] );
		$this->assertTrue( $arr['exceeded'] );
	}

	// =========================================================================
	//  from_array — Phase 4.2 (reconstruction depuis persistance)
	// =========================================================================

	public function test_failure_from_array_round_trips(): void {
		$original = new RegressionFailure(
			metric_key: 'chars',
			before: 1000,
			after: 800,
			threshold: 5,
			unit: RegressionFailure::UNIT_PCT,
			loss: 200,
			loss_pct: 20.0,
		);
		$rebuilt  = RegressionFailure::from_array( $original->to_array() );

		$this->assertSame( $original->metric_key, $rebuilt->metric_key );
		$this->assertSame( $original->before, $rebuilt->before );
		$this->assertSame( $original->after, $rebuilt->after );
		$this->assertSame( $original->threshold, $rebuilt->threshold );
		$this->assertSame( $original->unit, $rebuilt->unit );
		$this->assertSame( $original->loss, $rebuilt->loss );
		$this->assertSame( $original->loss_pct, $rebuilt->loss_pct );
	}

	public function test_failure_from_array_with_absolute_unit(): void {
		$rebuilt = RegressionFailure::from_array( array(
			'metric_key' => 'images',
			'before'     => 4,
			'after'      => 2,
			'threshold'  => 0,
			'unit'       => 'absolute',
			'loss'       => 2,
			'loss_pct'   => null,
		) );
		$this->assertSame( 'absolute', $rebuilt->unit );
		$this->assertNull( $rebuilt->loss_pct );
	}

	public function test_failure_from_array_unknown_unit_falls_back_to_absolute(): void {
		$rebuilt = RegressionFailure::from_array( array(
			'metric_key' => 'images',
			'before'     => 4,
			'after'      => 2,
			'threshold'  => 0,
			'unit'       => 'bogus',
			'loss'       => 2,
		) );
		$this->assertSame( 'absolute', $rebuilt->unit );
	}

	public function test_failure_from_array_missing_fields_default_to_safe_values(): void {
		$rebuilt = RegressionFailure::from_array( array() );
		$this->assertSame( '', $rebuilt->metric_key );
		$this->assertSame( 0, $rebuilt->before );
		$this->assertSame( 0, $rebuilt->after );
		$this->assertSame( 0, $rebuilt->threshold );
		$this->assertSame( 'absolute', $rebuilt->unit );
		$this->assertSame( 0, $rebuilt->loss );
		$this->assertNull( $rebuilt->loss_pct );
	}

	public function test_report_from_array_round_trips(): void {
		$original = new RegressionReport( array(
			$this->failure( 'images', 1 ),
			$this->failure( 'links', 2 ),
		) );
		$rebuilt  = RegressionReport::from_array( $original->to_array() );

		$this->assertNotNull( $rebuilt );
		$this->assertSame( 2, $rebuilt->failure_count() );
		$this->assertTrue( $rebuilt->has_failure( 'images' ) );
		$this->assertTrue( $rebuilt->has_failure( 'links' ) );
	}

	public function test_report_from_array_returns_null_when_failures_missing(): void {
		$this->assertNull( RegressionReport::from_array( array() ) );
		$this->assertNull( RegressionReport::from_array( array( 'failures' => array() ) ) );
		$this->assertNull( RegressionReport::from_array( array( 'failures' => 'not-an-array' ) ) );
	}

	public function test_report_from_array_skips_non_array_entries(): void {
		$rebuilt = RegressionReport::from_array( array(
			'failures' => array(
				array( 'metric_key' => 'images', 'before' => 4, 'after' => 2, 'loss' => 2, 'unit' => 'absolute', 'threshold' => 0 ),
				'corrupted-entry',
				42,
			),
		) );
		$this->assertNotNull( $rebuilt );
		$this->assertSame( 1, $rebuilt->failure_count() );
		$this->assertTrue( $rebuilt->has_failure( 'images' ) );
	}
}
