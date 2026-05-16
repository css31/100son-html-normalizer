<?php
/**
 * SessionController — endpoints REST du verrou single-user.
 *
 * Surface : 4 routes utilisées par la SPA pour acquérir, maintenir et
 * libérer le verrou de session. Volontairement non protégées par le verrou
 * elles-mêmes (sinon impossible de l'acquérir au premier appel) — seule
 * la capability `manage_options` est requise.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Session\SessionLock;
use WP_REST_Request;
use WP_REST_Response;

/**
 * 4 routes :
 *
 *  - `GET  /session`           — statut courant `{session, ttl}`.
 *  - `POST /session/acquire`   — body `{session_id, force?}`, retourne 200 / 409.
 *  - `POST /session/heartbeat` — body `{session_id}`, retourne 200 / 409.
 *  - `POST /session/release`   — body `{session_id}`, no-op si pas owner.
 *
 * Cf. `SessionLock` pour la sémantique détaillée (TTL, force, ownership).
 */
final class SessionController extends BaseController {

	/**
	 * @param SessionLock $lock Verrou injecté.
	 */
	public function __construct(
		private readonly SessionLock $lock,
	) {}

	/**
	 * Enregistre les 4 routes au hook `rest_api_init`.
	 *
	 * Toutes sont protégées par `permission_check_manage_options` (jamais
	 * par le verrou — sinon `acquire` ne pourrait jamais réussir).
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns  = self::REST_NAMESPACE;
		$cap = array( $this, 'permission_check_manage_options' );

		register_rest_route( $ns, '/session', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => $cap,
		) );

		register_rest_route( $ns, '/session/acquire', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'acquire' ),
			'permission_callback' => $cap,
		) );

		register_rest_route( $ns, '/session/heartbeat', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'heartbeat' ),
			'permission_callback' => $cap,
		) );

		register_rest_route( $ns, '/session/release', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'release' ),
			'permission_callback' => $cap,
		) );
	}

	/**
	 * `GET /session` — statut courant. `session` est null si aucun verrou
	 * actif ou périmé. La SPA n'utilise pas cette route en pratique (elle
	 * appelle directement `acquire` au mount) ; elle existe pour les tests
	 * manuels et l'observabilité (curl).
	 *
	 * @param WP_REST_Request $request Requête (inutilisée).
	 * @return WP_REST_Response
	 */
	public function get_status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->respond( array(
			'session' => $this->lock->status(),
			'ttl'     => SessionLock::TTL_SECONDS,
		) );
	}

	/**
	 * `POST /session/acquire` — body `{session_id, force?}`. Retourne 200
	 * avec `{session, ttl}` si succès, 409 avec `{owner}` dans `data` si
	 * un autre détient le verrou et `force` est absent ou faux.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function acquire( WP_REST_Request $request ): WP_REST_Response {
		$session_id = SessionLock::extract_session_id( $request );
		if ( '' === $session_id ) {
			return $this->rest_error(
				'invalid_session_id',
				__( 'session_id requis.', '100son-html-normalizer' ),
				400
			);
		}
		$force  = (bool) $request->get_param( 'force' );
		$result = $this->lock->acquire( $session_id, $force );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->rest_error(
				(string) ( $result['code'] ?? 'htmln_session_locked' ),
				__( 'Cette extension est en cours d\'utilisation par un autre administrateur.', '100son-html-normalizer' ),
				(int) ( $result['status'] ?? 409 ),
				array( 'owner' => $result['owner'] ?? null )
			);
		}
		return $this->respond( array(
			'session' => $result['session'] ?? null,
			'ttl'     => SessionLock::TTL_SECONDS,
		) );
	}

	/**
	 * `POST /session/heartbeat` — body `{session_id}`. Retourne 200 si
	 * l'appelant est toujours détenteur, 409 sinon (verrou repris ou
	 * périmé entre deux heartbeats).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function heartbeat( WP_REST_Request $request ): WP_REST_Response {
		$session_id = SessionLock::extract_session_id( $request );
		$result     = $this->lock->heartbeat( $session_id );
		if ( ! ( $result['ok'] ?? false ) ) {
			return $this->rest_error(
				(string) ( $result['code'] ?? 'htmln_session_locked' ),
				__( 'La session a expiré ou été reprise par un autre administrateur.', '100son-html-normalizer' ),
				(int) ( $result['status'] ?? 409 ),
				array( 'owner' => $result['owner'] ?? null )
			);
		}
		return $this->respond( array(
			'session' => $result['session'] ?? null,
			'ttl'     => SessionLock::TTL_SECONDS,
		) );
	}

	/**
	 * `POST /session/release` — body `{session_id}`. Toujours 200 (no-op
	 * silencieux si l'appelant n'est pas le détenteur — la libération doit
	 * être tolérante à un verrou déjà perdu côté serveur).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function release( WP_REST_Request $request ): WP_REST_Response {
		$session_id = SessionLock::extract_session_id( $request );
		$this->lock->release( $session_id );
		return $this->respond( array( 'ok' => true ) );
	}
}
