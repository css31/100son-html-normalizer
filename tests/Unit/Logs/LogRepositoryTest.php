<?php
/**
 * Tests LogRepository.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Logs;

use Cent_Son\Html_Normalizer\Core\Logs\LogRepository;
use PHPUnit\Framework\TestCase;

final class LogRepositoryTest extends TestCase {

	private LogRepository $repo;

	protected function setUp(): void {
		$GLOBALS['son100_htmln_options'] = [];
		$this->repo                       = new LogRepository();
	}

	public function test_empty_initially(): void {
		$this->assertSame( [], $this->repo->all() );
		$this->assertSame( 0, $this->repo->count() );
	}

	public function test_add_single_entry(): void {
		$this->repo->add( [ 'event' => 'normalize', 'post_id' => 1 ] );
		$this->assertSame( 1, $this->repo->count() );
		$entries = $this->repo->all();
		$this->assertSame( 1, $entries[0]['post_id'] );
	}

	public function test_recent_first_returns_reverse_chronological(): void {
		$this->repo->add( [ 'event' => 'normalize', 'post_id' => 1 ] );
		$this->repo->add( [ 'event' => 'normalize', 'post_id' => 2 ] );
		$this->repo->add( [ 'event' => 'normalize', 'post_id' => 3 ] );
		$recent = $this->repo->recent_first();
		$this->assertSame( 3, $recent[0]['post_id'] );
		$this->assertSame( 2, $recent[1]['post_id'] );
		$this->assertSame( 1, $recent[2]['post_id'] );
	}

	public function test_fifo_eviction_at_max(): void {
		for ( $i = 1; $i <= LogRepository::MAX_ENTRIES + 10; $i++ ) {
			$this->repo->add( [ 'post_id' => $i ] );
		}
		$this->assertSame( LogRepository::MAX_ENTRIES, $this->repo->count() );
		$entries = $this->repo->all();
		// Les 10 premiers doivent avoir été évincés.
		$this->assertSame( 11, $entries[0]['post_id'] );
		$this->assertSame( LogRepository::MAX_ENTRIES + 10, $entries[ LogRepository::MAX_ENTRIES - 1 ]['post_id'] );
	}

	public function test_paginate(): void {
		for ( $i = 1; $i <= 75; $i++ ) {
			$this->repo->add( [ 'post_id' => $i ] );
		}
		$page = $this->repo->paginate( 1, 50 );
		$this->assertSame( 75, $page['total'] );
		$this->assertSame( 2, $page['total_pages'] );
		$this->assertCount( 50, $page['entries'] );
		// Recent first : la première entrée doit être post_id 75.
		$this->assertSame( 75, $page['entries'][0]['post_id'] );
	}

	public function test_clear_empties_repo(): void {
		$this->repo->add( [ 'post_id' => 1 ] );
		$this->repo->add( [ 'post_id' => 2 ] );
		$this->repo->clear();
		$this->assertSame( 0, $this->repo->count() );
		$this->assertSame( [], $this->repo->all() );
	}
}
