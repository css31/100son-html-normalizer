<?php
/**
 * Tests HtmlMetrics.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Metrics;

use Cent_Son\Html_Normalizer\Core\Metrics\HtmlMetrics;
use PHPUnit\Framework\TestCase;

final class HtmlMetricsTest extends TestCase {

	// ====== compute ====== //

	public function test_compute_empty(): void {
		$m = HtmlMetrics::compute( '' );
		$this->assertSame( 0, $m['word_count'] );
		$this->assertSame( 0, $m['char_count'] );
		$this->assertSame( 0, $m['image_count'] );
	}

	public function test_compute_words(): void {
		$m = HtmlMetrics::compute( '<p>Bonjour le monde.</p>' );
		$this->assertSame( 3, $m['word_count'] );
	}

	public function test_compute_words_with_accents(): void {
		$m = HtmlMetrics::compute( '<p>Réformer l\'écriture française.</p>' );
		// "Réformer", "l", "écriture", "française" = 4 sequences alphanum (l est un mot d'1 lettre)
		$this->assertGreaterThanOrEqual( 3, $m['word_count'] );
	}

	public function test_compute_words_ignores_nbsp(): void {
		$m = HtmlMetrics::compute( '<p>Mot1&nbsp;Mot2&nbsp;Mot3</p>' );
		$this->assertSame( 3, $m['word_count'] );
	}

	public function test_compute_chars_strips_tags(): void {
		$m = HtmlMetrics::compute( '<p style="color:red">abc</p>' );
		$this->assertSame( 3, $m['char_count'] );
	}

	public function test_compute_image_count(): void {
		$m = HtmlMetrics::compute( '<p><img src="1"><img src="2"><img src="3"></p>' );
		$this->assertSame( 3, $m['image_count'] );
	}

	public function test_compute_image_count_case_insensitive(): void {
		$m = HtmlMetrics::compute( '<P><IMG src="1"></P>' );
		$this->assertSame( 1, $m['image_count'] );
	}

	// ====== compare ====== //

	public function test_compare_unchanged_is_ok(): void {
		$before = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 5 ];
		$cmp    = HtmlMetrics::compare( $before, $before );
		$this->assertSame( 0, $cmp['word_delta'] );
		$this->assertSame( 0.0, $cmp['word_pct'] );
		$this->assertSame( HtmlMetrics::SEVERITY_OK, $cmp['severity'] );
	}

	public function test_compare_small_loss_is_ok(): void {
		$before = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 5 ];
		$after  = [ 'word_count' => 95,  'char_count' => 480, 'image_count' => 5 ];
		$cmp    = HtmlMetrics::compare( $before, $after );
		$this->assertSame( -5, $cmp['word_delta'] );
		$this->assertSame( -5.0, $cmp['word_pct'] );
		$this->assertSame( HtmlMetrics::SEVERITY_OK, $cmp['severity'] );
	}

	public function test_compare_10pct_word_loss_triggers_warning(): void {
		$before = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 5 ];
		$after  = [ 'word_count' => 89,  'char_count' => 450, 'image_count' => 5 ];
		$cmp    = HtmlMetrics::compare( $before, $after );
		$this->assertSame( HtmlMetrics::SEVERITY_WARNING, $cmp['severity'] );
	}

	public function test_compare_30pct_word_loss_triggers_critical(): void {
		$before = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 5 ];
		$after  = [ 'word_count' => 60,  'char_count' => 300, 'image_count' => 5 ];
		$cmp    = HtmlMetrics::compare( $before, $after );
		$this->assertSame( HtmlMetrics::SEVERITY_CRITICAL, $cmp['severity'] );
	}

	public function test_compare_one_image_lost_triggers_warning(): void {
		$before = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 5 ];
		$after  = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 4 ];
		$cmp    = HtmlMetrics::compare( $before, $after );
		$this->assertSame( -1, $cmp['image_delta'] );
		$this->assertSame( HtmlMetrics::SEVERITY_WARNING, $cmp['severity'] );
	}

	public function test_compare_two_images_lost_triggers_critical(): void {
		$before = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 5 ];
		$after  = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 3 ];
		$cmp    = HtmlMetrics::compare( $before, $after );
		$this->assertSame( HtmlMetrics::SEVERITY_CRITICAL, $cmp['severity'] );
	}

	public function test_compare_gain_is_ok(): void {
		// Gain (perdu négatif) ne devrait jamais alerter.
		$before = [ 'word_count' => 100, 'char_count' => 500, 'image_count' => 5 ];
		$after  = [ 'word_count' => 120, 'char_count' => 600, 'image_count' => 7 ];
		$cmp    = HtmlMetrics::compare( $before, $after );
		$this->assertSame( 20, $cmp['word_delta'] );
		$this->assertSame( HtmlMetrics::SEVERITY_OK, $cmp['severity'] );
	}

	public function test_compare_handles_zero_before(): void {
		$before = [ 'word_count' => 0, 'char_count' => 0, 'image_count' => 0 ];
		$after  = [ 'word_count' => 0, 'char_count' => 0, 'image_count' => 0 ];
		$cmp    = HtmlMetrics::compare( $before, $after );
		$this->assertSame( 0.0, $cmp['word_pct'] );
		$this->assertSame( HtmlMetrics::SEVERITY_OK, $cmp['severity'] );
	}
}
