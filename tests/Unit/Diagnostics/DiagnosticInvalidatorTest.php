<?php
/**
 * Tests DiagnosticInvalidator — Phase 3.4 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Diagnostics;

use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticInvalidator;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Wpdb;
use WP_Post;

final class DiagnosticInvalidatorTest extends TestCase {

	private Son100_Htmln_Test_Wpdb $wpdb;
	private DiagnosticsRepository $repo;
	private SettingsRepository $settings;
	private DiagnosticInvalidator $invalidator;

	protected function setUp(): void {
		$GLOBALS['son100_htmln_options']         = [];
		$GLOBALS['son100_htmln_test_actions']    = [];
		$GLOBALS['son100_htmln_test_is_revision'] = [];
		$GLOBALS['son100_htmln_test_is_autosave'] = [];

		$this->wpdb        = new Son100_Htmln_Test_Wpdb();
		$this->repo        = new DiagnosticsRepository( $this->wpdb );
		$this->settings    = new SettingsRepository();
		$this->invalidator = new DiagnosticInvalidator( $this->repo, $this->settings );
	}

	private function published_post( int $id, string $type = 'post' ): WP_Post {
		$p              = new WP_Post();
		$p->ID          = $id;
		$p->post_status = 'publish';
		$p->post_type   = $type;
		return $p;
	}

	public function test_register_attaches_save_post_handler_at_priority_999(): void {
		$this->invalidator->register();
		$this->assertArrayHasKey( 'save_post', $GLOBALS['son100_htmln_test_actions'] );
		$registrations = $GLOBALS['son100_htmln_test_actions']['save_post'];
		$this->assertCount( 1, $registrations );
		$this->assertSame( DiagnosticInvalidator::HOOK_PRIORITY, $registrations[0]['priority'] );
		$this->assertSame( 999, $registrations[0]['priority'] );
		$this->assertSame( 2, $registrations[0]['accepted_args'] );
	}

	public function test_on_save_post_marks_stale_for_published_post(): void {
		$this->wpdb->update_return = 1;
		$post = $this->published_post( 42 );
		$this->invalidator->on_save_post( 42, $post );
		$this->assertCount( 1, $this->wpdb->update_log );
		$this->assertSame( array( 'is_stale' => 1 ), $this->wpdb->update_log[0]['data'] );
		$this->assertSame( array( 'post_id' => 42 ), $this->wpdb->update_log[0]['where'] );
	}

	public function test_on_save_post_skips_revisions(): void {
		$GLOBALS['son100_htmln_test_is_revision'][42] = 100;  // c'est une révision du parent 100
		$post = $this->published_post( 42 );
		$this->invalidator->on_save_post( 42, $post );
		$this->assertCount( 0, $this->wpdb->update_log );
	}

	public function test_on_save_post_skips_autosaves(): void {
		$GLOBALS['son100_htmln_test_is_autosave'][42] = 100;
		$post = $this->published_post( 42 );
		$this->invalidator->on_save_post( 42, $post );
		$this->assertCount( 0, $this->wpdb->update_log );
	}

	public function test_on_save_post_skips_non_publish_posts(): void {
		$post              = new WP_Post();
		$post->ID          = 42;
		$post->post_status = 'draft';
		$post->post_type   = 'post';
		$this->invalidator->on_save_post( 42, $post );
		$this->assertCount( 0, $this->wpdb->update_log );
	}

	public function test_on_save_post_skips_post_types_outside_f8_selection(): void {
		// Settings : seuls les "post" sont scannés ; un "page" doit être ignoré.
		update_option( 'son100_htmln_settings', array( 'f8_post_types_selection' => array( 'post' ) ) );
		$post = $this->published_post( 42, 'page' );
		$this->invalidator->on_save_post( 42, $post );
		$this->assertCount( 0, $this->wpdb->update_log );
	}

	public function test_on_save_post_includes_post_types_listed_in_f8(): void {
		$this->wpdb->update_return = 1;
		update_option( 'son100_htmln_settings', array( 'f8_post_types_selection' => array( 'post', 'page' ) ) );
		$post = $this->published_post( 42, 'page' );
		$this->invalidator->on_save_post( 42, $post );
		$this->assertCount( 1, $this->wpdb->update_log );
	}

	public function test_on_save_post_skips_when_post_arg_is_null(): void {
		$this->invalidator->on_save_post( 42, null );
		$this->assertCount( 0, $this->wpdb->update_log );
	}

	public function test_on_save_post_silently_swallows_repo_exception(): void {
		// Repo qui throw : l'invalidator ne doit PAS propager.
		$throwing_repo = new class extends DiagnosticsRepository {
			public function __construct() {
				// Pas d'appel parent — on ne va pas accéder à $wpdb.
			}
			public function mark_stale_for_post( int $post_id ): bool {
				throw new \RuntimeException( 'BDD indisponible' );
			}
		};
		$invalidator = new DiagnosticInvalidator( $throwing_repo, $this->settings );

		$post = $this->published_post( 42 );
		// Capture stderr (error_log peut écrire dessus selon ini).
		$prev_log_errors = ini_set( 'log_errors', '0' );
		$prev_error_log  = ini_set( 'error_log', '/dev/null' );
		try {
			$invalidator->on_save_post( 42, $post );  // Ne doit pas throw.
			$this->assertTrue( true, 'Pas d exception remontee' );
		} finally {
			if ( false !== $prev_log_errors ) {
				ini_set( 'log_errors', $prev_log_errors );
			}
			if ( false !== $prev_error_log ) {
				ini_set( 'error_log', $prev_error_log );
			}
		}
	}
}
