<?php
/**
 * SessionLock — verrou « single concurrent user » de la SPA admin.
 *
 * Garantit qu'un seul administrateur (et un seul onglet) interagit avec
 * l'extension à un instant T. Bloque toute route REST mutative si l'appelant
 * n'est pas le détenteur du verrou.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Session;

defined( 'ABSPATH' ) || exit;

use Closure;
use WP_Error;
use WP_REST_Request;

/**
 * Verrou de session unique stocké dans une option WP autoloadée.
 *
 * Stockage : option `son100_htmln_active_session` =
 *   {
 *     user_id:      int,
 *     user_login:   string,
 *     display_name: string,
 *     session_id:   string  (UUID généré côté SPA, propre à un onglet),
 *     started_at:   int     (timestamp UNIX),
 *     last_seen_at: int     (timestamp UNIX, mis à jour par heartbeat)
 *   }
 *
 * Le couple (user_id, session_id) sert d'identité du détenteur — bloque
 * donc aussi un même user contre lui-même multi-onglets/multi-navigateurs.
 *
 * TTL : `TTL_SECONDS` (5 min). Au-delà sans heartbeat, le verrou est
 * considéré périmé — le prochain `acquire()` réussit sans `force`.
 * Couvre les cas « onglet fermé brutalement » ou « machine endormie ».
 *
 * L'horloge est injectable (`$clock`) pour les tests unitaires — production
 * utilise `time()`.
 */
final class SessionLock {

	/**
	 * Clé de l'option WP qui stocke le verrou.
	 */
	public const OPTION_KEY = 'son100_htmln_active_session';

	/**
	 * Durée de vie du verrou sans heartbeat (secondes).
	 *
	 * 5 min : assez court pour libérer rapidement un onglet oublié, assez
	 * long pour survivre à une reprise de Wi-Fi ou à un onglet endormi par
	 * le navigateur.
	 */
	public const TTL_SECONDS = 300;

	/**
	 * En-tête HTTP que la SPA injecte sur chaque requête pour s'identifier.
	 */
	public const HEADER_SESSION_ID = 'X-Htmln-Session-Id';

	/**
	 * Horloge injectable — retourne un timestamp UNIX.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * @param Closure(): int|null $clock Horloge personnalisée (tests). Null = `time()`.
	 */
	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Statut courant du verrou — null si aucun verrou actif ou périmé.
	 *
	 * Un verrou périmé est implicitement traité comme « libre » : on ne
	 * supprime pas l'option (rare, et le prochain `acquire()` l'écrasera),
	 * mais `status()` retourne null pour signaler la disponibilité.
	 *
	 * @return array{user_id: int, user_login: string, display_name: string, session_id: string, started_at: int, last_seen_at: int}|null
	 */
	public function status(): ?array {
		$stored = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $stored ) || ! isset( $stored['session_id'] ) ) {
			return null;
		}
		if ( $this->is_expired( $stored ) ) {
			return null;
		}
		return $this->normalize_record( $stored );
	}

	/**
	 * Tente d'acquérir le verrou pour la session courante.
	 *
	 * Retour :
	 *  - succès : `{ok: true, session: <record>}`
	 *  - utilisateur non connecté : `{ok: false, code: 'no_user', status: 401}`
	 *  - verrou détenu par un autre : `{ok: false, code: 'htmln_session_locked', status: 409, owner: <public_owner>}`
	 *
	 * `$force = true` arrache le verrou même s'il est encore frais — utilisé
	 * par le bouton « Forcer la prise de contrôle » côté SPA. Si l'appelant
	 * est déjà le détenteur, on rafraîchit `last_seen_at` (équivalent
	 * heartbeat) sans changer `started_at`.
	 *
	 * @param string $session_id UUID de l'onglet SPA.
	 * @param bool   $force      Arracher le verrou même s'il est détenu.
	 * @return array{ok: bool, code?: string, status?: int, owner?: array<string, mixed>, session?: array<string, mixed>}
	 */
	public function acquire( string $session_id, bool $force = false ): array {
		$session_id = $this->sanitize_session_id( $session_id );
		if ( '' === $session_id ) {
			return array( 'ok' => false, 'code' => 'invalid_session_id', 'status' => 400 );
		}
		$user    = wp_get_current_user();
		$user_id = (int) $user->ID;
		if ( $user_id <= 0 ) {
			return array( 'ok' => false, 'code' => 'no_user', 'status' => 401 );
		}
		$current = $this->status();
		if ( null === $current ) {
			return array(
				'ok'      => true,
				'session' => $this->write_lock( $session_id, $user_id, $user ),
			);
		}
		// Déjà détenteur — refresh sans changer started_at.
		if ( $current['session_id'] === $session_id && $current['user_id'] === $user_id ) {
			return array(
				'ok'      => true,
				'session' => $this->write_lock( $session_id, $user_id, $user, $current['started_at'] ),
			);
		}
		if ( $force ) {
			return array(
				'ok'      => true,
				'session' => $this->write_lock( $session_id, $user_id, $user ),
			);
		}
		return array(
			'ok'     => false,
			'code'   => 'htmln_session_locked',
			'status' => 409,
			'owner'  => $this->public_owner( $current ),
		);
	}

	/**
	 * Met à jour `last_seen_at` si l'appelant est le détenteur courant.
	 *
	 * @param string $session_id UUID de l'onglet SPA.
	 * @return array{ok: bool, code?: string, status?: int, owner?: array<string, mixed>|null, session?: array<string, mixed>}
	 */
	public function heartbeat( string $session_id ): array {
		$session_id = $this->sanitize_session_id( $session_id );
		$user_id    = (int) wp_get_current_user()->ID;
		$current    = $this->status();
		if ( null === $current ) {
			return array( 'ok' => false, 'code' => 'no_session', 'status' => 409, 'owner' => null );
		}
		if ( $current['session_id'] !== $session_id || $current['user_id'] !== $user_id ) {
			return array(
				'ok'     => false,
				'code'   => 'htmln_session_locked',
				'status' => 409,
				'owner'  => $this->public_owner( $current ),
			);
		}
		$current['last_seen_at'] = ( $this->clock )();
		update_option( self::OPTION_KEY, $current, false );
		return array( 'ok' => true, 'session' => $current );
	}

	/**
	 * Libère le verrou si l'appelant est le détenteur courant. No-op
	 * silencieux sinon (la libération côté SPA doit rester tolérante à un
	 * verrou déjà perdu, p. ex. à cause d'un `force` concurrent).
	 *
	 * @param string $session_id UUID de l'onglet SPA.
	 * @return array{ok: bool}
	 */
	public function release( string $session_id ): array {
		$session_id = $this->sanitize_session_id( $session_id );
		$user_id    = (int) wp_get_current_user()->ID;
		$current    = $this->status();
		if ( null === $current ) {
			return array( 'ok' => true );
		}
		if ( $current['session_id'] !== $session_id || $current['user_id'] !== $user_id ) {
			return array( 'ok' => true );
		}
		delete_option( self::OPTION_KEY );
		return array( 'ok' => true );
	}

	/**
	 * Vrai ssi l'utilisateur courant détient le verrou avec ce session_id.
	 *
	 * @param string $session_id UUID de l'onglet SPA.
	 * @return bool
	 */
	public function is_owner( string $session_id ): bool {
		$session_id = $this->sanitize_session_id( $session_id );
		if ( '' === $session_id ) {
			return false;
		}
		$user_id = (int) wp_get_current_user()->ID;
		if ( $user_id <= 0 ) {
			return false;
		}
		$current = $this->status();
		return null !== $current
			&& $current['session_id'] === $session_id
			&& $current['user_id'] === $user_id;
	}

	/**
	 * `permission_callback` à utiliser sur toutes les routes REST mutatives.
	 *
	 * Combine la capability `manage_options` (filet standard WP) et la
	 * vérification d'ownership du verrou. Retourne :
	 *  - `true` si le caller détient le verrou,
	 *  - `false` si la capability manque (→ 403 standard WP),
	 *  - `WP_Error` 409 sinon, avec `owner` dans `data` pour permettre
	 *    à la SPA d'afficher qui détient le verrou.
	 *
	 * @param WP_REST_Request $request Requête en cours.
	 * @return true|WP_Error
	 */
	public function guard( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Permissions insuffisantes.', '100son-html-normalizer' ),
				array( 'status' => 403 )
			);
		}
		$session_id = self::extract_session_id( $request );
		if ( '' === $session_id ) {
			return new WP_Error(
				'htmln_session_required',
				__( 'Identifiant de session manquant. Rechargez la page d\'administration.', '100son-html-normalizer' ),
				array( 'status' => 409, 'owner' => null )
			);
		}
		if ( $this->is_owner( $session_id ) ) {
			return true;
		}
		$current = $this->status();
		return new WP_Error(
			'htmln_session_locked',
			__( 'Cette extension est en cours d\'utilisation par un autre administrateur.', '100son-html-normalizer' ),
			array(
				'status' => 409,
				'owner'  => null !== $current ? $this->public_owner( $current ) : null,
			)
		);
	}

	/**
	 * Extrait le session_id d'une requête REST. Priorité : header
	 * `X-Htmln-Session-Id`, puis paramètre de corps `session_id` (utilisé
	 * par les routes `/session/*`). Static car les controllers en ont
	 * besoin sans instance de SessionLock.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return string Session id sanitisé ou chaîne vide.
	 */
	public static function extract_session_id( WP_REST_Request $request ): string {
		$header = $request->get_header( self::HEADER_SESSION_ID );
		if ( is_string( $header ) && '' !== $header ) {
			return self::sanitize_static_session_id( $header );
		}
		$body = $request->get_param( 'session_id' );
		if ( is_string( $body ) && '' !== $body ) {
			return self::sanitize_static_session_id( $body );
		}
		return '';
	}

	/**
	 * Sanitisation publique du session_id (alphanumérique + tiret, 64 max).
	 *
	 * @param string $value Valeur brute.
	 * @return string
	 */
	public static function sanitize_static_session_id( string $value ): string {
		$clean = preg_replace( '/[^a-zA-Z0-9-]/', '', $value );
		if ( ! is_string( $clean ) ) {
			return '';
		}
		return substr( $clean, 0, 64 );
	}

	/**
	 * Wrapper d'instance autour de la sanitisation statique. Symétrique avec
	 * l'API d'instance pour les callers internes.
	 *
	 * @param string $value Valeur brute.
	 * @return string
	 */
	private function sanitize_session_id( string $value ): string {
		return self::sanitize_static_session_id( $value );
	}

	/**
	 * Vrai ssi le verrou stocké a dépassé son TTL.
	 *
	 * @param array<string, mixed> $stored Record brut.
	 * @return bool
	 */
	private function is_expired( array $stored ): bool {
		$last = (int) ( $stored['last_seen_at'] ?? 0 );
		return ( ( $this->clock )() - $last ) > self::TTL_SECONDS;
	}

	/**
	 * Persiste un record et le retourne (normalisé). Si `$started_at` est
	 * fourni (cas refresh d'un verrou déjà détenu), il est préservé ; sinon
	 * `started_at = now`.
	 *
	 * @param string                  $session_id UUID.
	 * @param int                     $user_id    ID utilisateur WP.
	 * @param object                  $user       Utilisateur (pour user_login + display_name).
	 * @param int|null                $started_at Timestamp d'origine (refresh) ou null.
	 * @return array{user_id: int, user_login: string, display_name: string, session_id: string, started_at: int, last_seen_at: int}
	 */
	private function write_lock( string $session_id, int $user_id, object $user, ?int $started_at = null ): array {
		$now    = ( $this->clock )();
		$record = array(
			'user_id'      => $user_id,
			'user_login'   => isset( $user->user_login ) ? (string) $user->user_login : '',
			'display_name' => isset( $user->display_name ) && '' !== (string) $user->display_name
				? (string) $user->display_name
				: ( isset( $user->user_login ) ? (string) $user->user_login : '' ),
			'session_id'   => $session_id,
			'started_at'   => $started_at ?? $now,
			'last_seen_at' => $now,
		);
		update_option( self::OPTION_KEY, $record, false );
		return $record;
	}

	/**
	 * Renvoie une vue publique (sans session_id) du record détenteur, pour
	 * inclusion dans les réponses d'erreur 409. Le session_id est volontairement
	 * masqué — c'est un secret de l'autre onglet, pas une donnée à exposer.
	 *
	 * @param array<string, mixed> $stored Record interne.
	 * @return array{user_id: int, user_login: string, display_name: string, started_at: int, last_seen_at: int}
	 */
	private function public_owner( array $stored ): array {
		return array(
			'user_id'      => (int) ( $stored['user_id'] ?? 0 ),
			'user_login'   => (string) ( $stored['user_login'] ?? '' ),
			'display_name' => (string) ( $stored['display_name'] ?? '' ),
			'started_at'   => (int) ( $stored['started_at'] ?? 0 ),
			'last_seen_at' => (int) ( $stored['last_seen_at'] ?? 0 ),
		);
	}

	/**
	 * Normalise un record brut lu depuis l'option (types stricts).
	 *
	 * @param array<string, mixed> $stored Record brut.
	 * @return array{user_id: int, user_login: string, display_name: string, session_id: string, started_at: int, last_seen_at: int}
	 */
	private function normalize_record( array $stored ): array {
		return array(
			'user_id'      => (int) ( $stored['user_id'] ?? 0 ),
			'user_login'   => (string) ( $stored['user_login'] ?? '' ),
			'display_name' => (string) ( $stored['display_name'] ?? '' ),
			'session_id'   => (string) ( $stored['session_id'] ?? '' ),
			'started_at'   => (int) ( $stored['started_at'] ?? 0 ),
			'last_seen_at' => (int) ( $stored['last_seen_at'] ?? 0 ),
		);
	}
}
