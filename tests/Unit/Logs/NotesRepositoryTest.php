<?php
/**
 * Tests NotesRepository.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Logs;

use Cent_Son\Html_Normalizer\Core\Logs\NotesRepository;
use PHPUnit\Framework\TestCase;

final class NotesRepositoryTest extends TestCase {

	private NotesRepository $repo;

	protected function setUp(): void {
		$GLOBALS['son100_htmln_options'] = [];
		$this->repo                       = new NotesRepository();
	}

	public function test_get_returns_empty_initially(): void {
		$this->assertSame( '', $this->repo->get() );
	}

	public function test_set_and_get_roundtrip(): void {
		$this->repo->set( "Note de test\nLigne 2" );
		$this->assertSame( "Note de test\nLigne 2", $this->repo->get() );
	}

	public function test_set_trims_whitespace(): void {
		$this->repo->set( "   Texte avec espaces   \n" );
		$this->assertSame( 'Texte avec espaces', $this->repo->get() );
	}

	public function test_set_replaces_existing(): void {
		$this->repo->set( 'Premier' );
		$this->repo->set( 'Second' );
		$this->assertSame( 'Second', $this->repo->get() );
	}

	public function test_clear_empties(): void {
		$this->repo->set( 'Quelque chose' );
		$this->repo->clear();
		$this->assertSame( '', $this->repo->get() );
	}
}
