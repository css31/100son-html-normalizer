<?php
/**
 * Tests SessionLock — verrou single concurrent user (post-v1.0.0).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Session;

use Cent_Son\Html_Normalizer\Session\SessionLock;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class SessionLockTest extends TestCase {

	/**
	 * Horloge testable — incrémentée manuellement via `$this->now`.
	 *
	 * @var int
	 */
	private int $now = 1_700_000_000;

	private SessionLock $lock;

	protected function setUp(): void {
		// Reset état partagé entre tests : option + utilisateur courant + caps.
		$GLOBALS['son100_htmln_options']            = array();
		$GLOBALS['son100_htmln_test_caps']          = array();
		$GLOBALS['son100_htmln_test_can_default']   = true;
		$GLOBALS['son100_htmln_test_current_user']  = (object) array(
			'ID'           => 42,
			'user_login'   => 'admin',
			'display_name' => 'Admin Name',
		);

		$this->now  = 1_700_000_000;
		$this->lock = new SessionLock( fn(): int => $this->now );
	}

	protected function tearDown(): void {
		// Réinitialise le current_user à l'anonyme (ID=0) pour ne pas
		// polluer les tests sensibles à `wp_get_current_user()` qui tournent
		// après (cf. LoggerTest::test_user_id_is_zero_in_test_env).
		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 0,
			'user_login'   => '',
			'display_name' => '',
		);
		$GLOBALS['son100_htmln_options']           = array();
	}

	// =========================================================================
	//  status()
	// =========================================================================

	public function test_status_returns_null_when_no_option_set(): void {
		$this->assertNull( $this->lock->status() );
	}

	public function test_status_returns_normalized_record_after_acquire(): void {
		$this->lock->acquire( 'tab-abc' );

		$status = $this->lock->status();
		$this->assertNotNull( $status );
		$this->assertSame( 42, $status['user_id'] );
		$this->assertSame( 'admin', $status['user_login'] );
		$this->assertSame( 'Admin Name', $status['display_name'] );
		$this->assertSame( 'tab-abc', $status['session_id'] );
		$this->assertSame( $this->now, $status['started_at'] );
		$this->assertSame( $this->now, $status['last_seen_at'] );
	}

	public function test_status_returns_null_when_lock_expired(): void {
		$this->lock->acquire( 'tab-abc' );
		// Avance au-delà du TTL.
		$this->now += SessionLock::TTL_SECONDS + 1;

		$this->assertNull( $this->lock->status() );
	}

	public function test_status_returns_record_at_exact_ttl_boundary(): void {
		$this->lock->acquire( 'tab-abc' );
		// Pile à TTL — encore valide (la comparaison utilise `>` strict).
		$this->now += SessionLock::TTL_SECONDS;

		$this->assertNotNull( $this->lock->status() );
	}

	// =========================================================================
	//  acquire()
	// =========================================================================

	public function test_acquire_succeeds_when_no_lock_exists(): void {
		$result = $this->lock->acquire( 'tab-abc' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'tab-abc', $result['session']['session_id'] );
		$this->assertSame( 42, $result['session']['user_id'] );
	}

	public function test_acquire_fails_with_409_when_another_user_holds_lock(): void {
		$this->lock->acquire( 'tab-abc' );

		// Switch user — un autre admin tente d'acquérir avec un autre onglet.
		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 99,
			'user_login'   => 'editor',
			'display_name' => 'Editor User',
		);
		$result = $this->lock->acquire( 'tab-xyz' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'htmln_session_locked', $result['code'] );
		$this->assertSame( 409, $result['status'] );
		// Le owner reporté doit être l'admin précédent (user 42), pas l'appelant.
		$this->assertSame( 42, $result['owner']['user_id'] );
		$this->assertSame( 'Admin Name', $result['owner']['display_name'] );
	}

	public function test_acquire_fails_when_same_user_uses_different_session_id(): void {
		// Bloque aussi un même user contre lui-même multi-onglets — cas
		// critique sur lequel repose la garantie « pas de course d'écriture ».
		$this->lock->acquire( 'tab-abc' );
		$result = $this->lock->acquire( 'tab-xyz' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'htmln_session_locked', $result['code'] );
	}

	public function test_acquire_refreshes_last_seen_for_existing_owner(): void {
		$this->lock->acquire( 'tab-abc' );
		$original_started = $this->lock->status()['started_at'];

		// Avance l'horloge sans dépasser le TTL puis re-acquire (mêmes id+user).
		$this->now += 60;
		$result = $this->lock->acquire( 'tab-abc' );

		$this->assertTrue( $result['ok'] );
		// started_at préservé (refresh, pas nouvelle prise).
		$this->assertSame( $original_started, $result['session']['started_at'] );
		// last_seen_at rafraîchi.
		$this->assertSame( $this->now, $result['session']['last_seen_at'] );
	}

	public function test_acquire_with_force_takes_over_existing_lock(): void {
		$this->lock->acquire( 'tab-abc' );

		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 99,
			'user_login'   => 'editor',
			'display_name' => 'Editor User',
		);
		$this->now += 30;
		$result = $this->lock->acquire( 'tab-xyz', true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 99, $result['session']['user_id'] );
		$this->assertSame( 'tab-xyz', $result['session']['session_id'] );
		// Force = nouveau started_at (pas de préservation, c'est une vraie reprise).
		$this->assertSame( $this->now, $result['session']['started_at'] );
	}

	public function test_acquire_succeeds_after_ttl_expired_without_force(): void {
		$this->lock->acquire( 'tab-abc' );

		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 99,
			'user_login'   => 'editor',
			'display_name' => 'Editor User',
		);
		// Onglet d'origine fermé brutalement (pas de release) — TTL expire.
		$this->now += SessionLock::TTL_SECONDS + 1;
		$result = $this->lock->acquire( 'tab-xyz' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 99, $result['session']['user_id'] );
	}

	public function test_acquire_fails_when_user_not_logged_in(): void {
		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 0,
			'user_login'   => '',
			'display_name' => '',
		);
		$result = $this->lock->acquire( 'tab-abc' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_user', $result['code'] );
		$this->assertSame( 401, $result['status'] );
	}

	public function test_acquire_fails_with_empty_session_id(): void {
		$result = $this->lock->acquire( '' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_session_id', $result['code'] );
		$this->assertSame( 400, $result['status'] );
	}

	public function test_acquire_sanitizes_session_id(): void {
		// Caractères hors [a-zA-Z0-9-] supprimés ; les alphanum restent.
		$this->lock->acquire( 'tab-abc/../etc/passwd' );
		$this->assertSame( 'tab-abcetcpasswd', $this->lock->status()['session_id'] );
	}

	public function test_acquire_falls_back_to_user_login_when_display_name_empty(): void {
		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 42,
			'user_login'   => 'admin',
			'display_name' => '',
		);
		$this->lock->acquire( 'tab-abc' );
		$this->assertSame( 'admin', $this->lock->status()['display_name'] );
	}

	// =========================================================================
	//  heartbeat()
	// =========================================================================

	public function test_heartbeat_refreshes_last_seen_for_owner(): void {
		$this->lock->acquire( 'tab-abc' );
		$this->now += 90;
		$result = $this->lock->heartbeat( 'tab-abc' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $this->now, $result['session']['last_seen_at'] );
	}

	public function test_heartbeat_fails_for_non_owner(): void {
		$this->lock->acquire( 'tab-abc' );
		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 99,
			'user_login'   => 'editor',
			'display_name' => 'Editor User',
		);
		$result = $this->lock->heartbeat( 'tab-xyz' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'htmln_session_locked', $result['code'] );
		$this->assertSame( 409, $result['status'] );
		$this->assertSame( 42, $result['owner']['user_id'] );
	}

	public function test_heartbeat_fails_when_no_lock_active(): void {
		$result = $this->lock->heartbeat( 'tab-abc' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_session', $result['code'] );
		$this->assertNull( $result['owner'] );
	}

	public function test_heartbeat_fails_when_lock_has_expired(): void {
		$this->lock->acquire( 'tab-abc' );
		$this->now += SessionLock::TTL_SECONDS + 1;
		$result = $this->lock->heartbeat( 'tab-abc' );

		// Même l'ancien owner ne peut pas ressusciter via heartbeat — il doit re-acquire.
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_session', $result['code'] );
	}

	// =========================================================================
	//  release()
	// =========================================================================

	public function test_release_clears_lock_for_owner(): void {
		$this->lock->acquire( 'tab-abc' );
		$result = $this->lock->release( 'tab-abc' );

		$this->assertTrue( $result['ok'] );
		$this->assertNull( $this->lock->status() );
	}

	public function test_release_is_noop_when_no_lock_active(): void {
		$result = $this->lock->release( 'tab-abc' );

		$this->assertTrue( $result['ok'] );
	}

	public function test_release_is_noop_for_non_owner_user(): void {
		$this->lock->acquire( 'tab-abc' );
		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 99,
			'user_login'   => 'editor',
			'display_name' => 'Editor User',
		);
		$result = $this->lock->release( 'tab-abc' );

		$this->assertTrue( $result['ok'] );
		// Le verrou de l'autre user reste intact.
		$this->assertNotNull( $this->lock->status() );
		$this->assertSame( 42, $this->lock->status()['user_id'] );
	}

	public function test_release_is_noop_for_wrong_session_id(): void {
		$this->lock->acquire( 'tab-abc' );
		$result = $this->lock->release( 'tab-xyz' );

		$this->assertTrue( $result['ok'] );
		$this->assertNotNull( $this->lock->status() );
	}

	// =========================================================================
	//  is_owner()
	// =========================================================================

	public function test_is_owner_true_for_current_holder(): void {
		$this->lock->acquire( 'tab-abc' );
		$this->assertTrue( $this->lock->is_owner( 'tab-abc' ) );
	}

	public function test_is_owner_false_for_different_session_id(): void {
		$this->lock->acquire( 'tab-abc' );
		$this->assertFalse( $this->lock->is_owner( 'tab-xyz' ) );
	}

	public function test_is_owner_false_for_different_user(): void {
		$this->lock->acquire( 'tab-abc' );
		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 99,
			'user_login'   => 'editor',
			'display_name' => 'Editor User',
		);
		$this->assertFalse( $this->lock->is_owner( 'tab-abc' ) );
	}

	public function test_is_owner_false_for_anonymous_caller(): void {
		$this->lock->acquire( 'tab-abc' );
		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 0,
			'user_login'   => '',
			'display_name' => '',
		);
		$this->assertFalse( $this->lock->is_owner( 'tab-abc' ) );
	}

	public function test_is_owner_false_when_session_id_empty(): void {
		$this->lock->acquire( 'tab-abc' );
		$this->assertFalse( $this->lock->is_owner( '' ) );
	}

	// =========================================================================
	//  guard()
	// =========================================================================

	public function test_guard_returns_true_when_caller_is_owner(): void {
		$this->lock->acquire( 'tab-abc' );

		$request = new WP_REST_Request( 'POST', '/htmln/v1/settings/external-sites' );
		$request->set_header( SessionLock::HEADER_SESSION_ID, 'tab-abc' );

		$this->assertTrue( $this->lock->guard( $request ) );
	}

	public function test_guard_returns_403_error_when_caller_lacks_capability(): void {
		$GLOBALS['son100_htmln_test_caps']['manage_options'] = false;
		$GLOBALS['son100_htmln_test_can_default']            = false;

		$request = new WP_REST_Request( 'POST', '/htmln/v1/settings/external-sites' );
		$request->set_header( SessionLock::HEADER_SESSION_ID, 'tab-abc' );

		$result = $this->lock->guard( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_guard_returns_409_when_session_header_missing(): void {
		$request = new WP_REST_Request( 'POST', '/htmln/v1/settings/external-sites' );
		// Pas d'en-tête X-Htmln-Session-Id.

		$result = $this->lock->guard( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'htmln_session_required', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
	}

	public function test_guard_returns_409_with_owner_when_not_holder(): void {
		$this->lock->acquire( 'tab-abc' );

		$GLOBALS['son100_htmln_test_current_user'] = (object) array(
			'ID'           => 99,
			'user_login'   => 'editor',
			'display_name' => 'Editor User',
		);
		$request = new WP_REST_Request( 'POST', '/htmln/v1/settings/external-sites' );
		$request->set_header( SessionLock::HEADER_SESSION_ID, 'tab-xyz' );

		$result = $this->lock->guard( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'htmln_session_locked', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 42, $data['owner']['user_id'] );
		$this->assertSame( 'Admin Name', $data['owner']['display_name'] );
	}

	public function test_guard_accepts_session_id_from_body_when_header_missing(): void {
		// Fallback body param — utilisé par les routes /session/* qui ne passent
		// pas par le middleware SPA (corps JSON, pas d'header).
		$this->lock->acquire( 'tab-abc' );

		$request = new WP_REST_Request( 'POST', '/htmln/v1/something' );
		$request->set_param( 'session_id', 'tab-abc' );

		$this->assertTrue( $this->lock->guard( $request ) );
	}

	// =========================================================================
	//  extract_session_id() / sanitize_static_session_id()
	// =========================================================================

	public function test_extract_session_id_prefers_header_over_body(): void {
		$request = new WP_REST_Request( 'POST', '/x' );
		$request->set_header( SessionLock::HEADER_SESSION_ID, 'from-header' );
		$request->set_param( 'session_id', 'from-body' );

		$this->assertSame( 'from-header', SessionLock::extract_session_id( $request ) );
	}

	public function test_extract_session_id_falls_back_to_body_param(): void {
		$request = new WP_REST_Request( 'POST', '/x' );
		$request->set_param( 'session_id', 'from-body' );

		$this->assertSame( 'from-body', SessionLock::extract_session_id( $request ) );
	}

	public function test_extract_session_id_returns_empty_when_none_provided(): void {
		$request = new WP_REST_Request( 'POST', '/x' );

		$this->assertSame( '', SessionLock::extract_session_id( $request ) );
	}

	public function test_sanitize_static_session_id_strips_disallowed_characters(): void {
		// Whitelist [a-zA-Z0-9-] : underscore, slash, point, espace, etc. → tombent.
		$this->assertSame( 'abc-123', SessionLock::sanitize_static_session_id( 'abc-123/./;' ) );
		$this->assertSame( 'abc123', SessionLock::sanitize_static_session_id( 'abc_123' ) );
		$this->assertSame( '', SessionLock::sanitize_static_session_id( '!!!' ) );
	}

	public function test_sanitize_static_session_id_caps_length_at_64(): void {
		$long  = str_repeat( 'a', 80 );
		$clean = SessionLock::sanitize_static_session_id( $long );
		$this->assertSame( 64, strlen( $clean ) );
	}

	// =========================================================================
	//  Constantes
	// =========================================================================

	public function test_ttl_constant_is_five_minutes(): void {
		$this->assertSame( 300, SessionLock::TTL_SECONDS );
	}

	public function test_option_key_is_namespaced(): void {
		$this->assertSame( 'son100_htmln_active_session', SessionLock::OPTION_KEY );
	}

	public function test_header_constant_uses_plugin_prefix(): void {
		$this->assertSame( 'X-Htmln-Session-Id', SessionLock::HEADER_SESSION_ID );
	}
}
