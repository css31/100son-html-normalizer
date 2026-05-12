<?php
/**
 * Tests RichNotesRepository — note libre riche (block grammar Gutenberg).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Notes;

use Cent_Son\Html_Normalizer\Notes\RichNotesRepository;
use PHPUnit\Framework\TestCase;

final class RichNotesRepositoryTest extends TestCase {

	private RichNotesRepository $repo;

	protected function setUp(): void {
		$GLOBALS['son100_htmln_options'] = array();
		$this->repo                       = new RichNotesRepository();
	}

	public function test_get_returns_empty_initially(): void {
		$this->assertSame( '', $this->repo->get() );
	}

	public function test_set_and_get_roundtrip_preserves_block_comments(): void {
		$grammar = "<!-- wp:paragraph -->\n<p>Bonjour</p>\n<!-- /wp:paragraph -->";
		$this->repo->set( $grammar );
		// Round-trip strict — les commentaires `<!-- wp:* -->` doivent
		// survivre à wp_kses_post (sans ça, la SPA ne peut pas re-parser).
		$this->assertSame( $grammar, $this->repo->get() );
	}

	public function test_set_strips_script_tags(): void {
		$dirty = "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->\n<script>alert(1)</script>";
		$this->repo->set( $dirty );
		$stored = $this->repo->get();
		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $stored );
	}

	public function test_set_trims_outer_whitespace(): void {
		$this->repo->set( "  \n<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->\n  " );
		$stored = $this->repo->get();
		$this->assertStringStartsWith( '<!-- wp:paragraph -->', $stored );
		$this->assertStringEndsWith( '<!-- /wp:paragraph -->', $stored );
	}

	public function test_set_replaces_existing(): void {
		$this->repo->set( '<!-- wp:paragraph --><p>premier</p><!-- /wp:paragraph -->' );
		$this->repo->set( '<!-- wp:paragraph --><p>second</p><!-- /wp:paragraph -->' );
		$this->assertStringContainsString( 'second', $this->repo->get() );
		$this->assertStringNotContainsString( 'premier', $this->repo->get() );
	}

	public function test_clear_empties(): void {
		$this->repo->set( '<!-- wp:paragraph --><p>foo</p><!-- /wp:paragraph -->' );
		$this->repo->clear();
		$this->assertSame( '', $this->repo->get() );
	}

	public function test_clear_does_not_delete_option_entry(): void {
		// La clé reste pour éviter le yo-yo autoload — la sémantique
		// « vidée » = option présente avec valeur chaîne vide.
		$this->repo->set( '<!-- wp:paragraph --><p>foo</p><!-- /wp:paragraph -->' );
		$this->repo->clear();
		$this->assertArrayHasKey(
			RichNotesRepository::OPT_NAME,
			$GLOBALS['son100_htmln_options']
		);
		$this->assertSame( '', $GLOBALS['son100_htmln_options'][ RichNotesRepository::OPT_NAME ] );
	}

	public function test_get_returns_empty_string_when_option_holds_non_string(): void {
		// Filet de protection : si quelqu'un a écrit un array sur l'option
		// (corruption manuelle, ancien plugin), on retourne chaîne vide
		// plutôt que de propager le type cassé.
		$GLOBALS['son100_htmln_options'][ RichNotesRepository::OPT_NAME ] = array( 'oops' );
		$this->assertSame( '', $this->repo->get() );
	}
}
