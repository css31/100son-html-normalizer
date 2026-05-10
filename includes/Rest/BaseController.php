<?php
/**
 * BaseController — classe abstraite des contrôleurs REST V1.0.
 *
 * Cf. cahier v2.0 §4.5 (endpoints REST), §13 (garde-fous), §14 (capability
 * `manage_options`).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Squelette commun à tous les contrôleurs REST du plugin.
 *
 * Chaque contrôleur étend cette classe et implémente `register_routes()`,
 * appelée par `RestServiceProvider` au hook `rest_api_init`.
 *
 * Fournit :
 *  - constantes `REST_NAMESPACE` et `CAPABILITY` partagées (cf. §14
 *    hyp. 14 : `manage_options` partout en V1.0) ;
 *  - `permission_check_manage_options()` : permission_callback prêt à
 *    l'emploi pour `register_rest_route()` ;
 *  - helpers de réponse `respond()` / `rest_error()` cohérents avec
 *    l'attente §13 « `WP_REST_Response` typées avec status code adapté » ;
 *  - sanitizers `sanitize_int_list()` / `sanitize_string_list()` qui ne
 *    laissent passer que les valeurs scalaires conformes (filet anti
 *    injection — cf. §13 « Sanitize entrées + escape sorties »).
 *
 * Volontairement abstraite (pas de trait) : `register_routes()` étant
 * obligatoire, l'abstract le rend explicite côté types.
 */
abstract class BaseController {

	/**
	 * Namespace REST V1.0 du plugin (cf. §4.5).
	 */
	public const REST_NAMESPACE = 'htmln/v1';

	/**
	 * Capability requise pour toutes les routes V1.0 (cf. §14 hyp. 14).
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Enregistre les routes du contrôleur via `register_rest_route()`.
	 *
	 * Appelée par `RestServiceProvider` au hook `rest_api_init`.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback `manage_options` partagé par toutes les routes V1.0.
	 *
	 * Signature `(WP_REST_Request)` : compatible avec
	 * `register_rest_route(..., 'permission_callback' => [$this, 'permission_check_manage_options'])`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return bool
	 */
	public function permission_check_manage_options( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Construit une `WP_REST_Response` 2xx typée.
	 *
	 * @param mixed $data   Payload.
	 * @param int   $status Code HTTP (défaut 200).
	 * @return WP_REST_Response
	 */
	protected function respond( mixed $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Construit une `WP_REST_Response` d'erreur typée selon la convention
	 * `{ code, message, data: { status, ...extra } }` — alignée avec le
	 * format que WP REST utilise nativement pour les `WP_Error` sérialisés.
	 *
	 * @param string               $code    Code machine (ex. `siteorigin_detected`).
	 * @param string               $message Message lisible (texte localisé).
	 * @param int                  $status  Code HTTP (défaut 400).
	 * @param array<string, mixed> $extra   Données complémentaires injectées dans `data`.
	 * @return WP_REST_Response
	 */
	protected function rest_error(
		string $code,
		string $message,
		int $status = 400,
		array $extra = array()
	): WP_REST_Response {
		$body = array(
			'code'    => $code,
			'message' => $message,
			'data'    => array_merge( array( 'status' => $status ), $extra ),
		);
		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Construit une `WP_REST_Response` à partir d'un `WP_Error`. Utilisé
	 * lorsque le domaine retourne un `WP_Error` qu'on veut propager tel
	 * quel à l'API.
	 *
	 * @param WP_Error $error  Erreur source.
	 * @param int      $status Code HTTP (défaut 400).
	 * @return WP_REST_Response
	 */
	protected function rest_error_from_wp( WP_Error $error, int $status = 400 ): WP_REST_Response {
		return $this->rest_error(
			$error->get_error_code() !== '' ? $error->get_error_code() : 'wp_error',
			$error->get_error_message(),
			$status,
		);
	}

	/**
	 * Sanitize d'une liste d'entiers — filtre les non-numériques et
	 * cast en int. Préserve l'ordre, déduplique éventuellement via
	 * `array_values(array_unique())` côté caller si besoin (rarement
	 * pertinent — l'ordre admin est souvent significatif).
	 *
	 * @param mixed $value Valeur brute (typiquement issue de `$request->get_param()`).
	 * @return list<int>
	 */
	protected function sanitize_int_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( is_numeric( $entry ) ) {
				$out[] = (int) $entry;
			}
		}
		return $out;
	}

	/**
	 * Sanitize d'une liste de chaînes — `sanitize_text_field` puis filtre
	 * les vides.
	 *
	 * @param mixed $value Valeur brute.
	 * @return list<string>
	 */
	protected function sanitize_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( is_scalar( $entry ) ) {
				$clean = sanitize_text_field( (string) $entry );
				if ( '' !== $clean ) {
					$out[] = $clean;
				}
			}
		}
		return $out;
	}
}
