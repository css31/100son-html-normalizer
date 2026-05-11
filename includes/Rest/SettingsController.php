<?php
/**
 * SettingsController — endpoints REST des réglages (Phase 6.7).
 *
 * Cf. cahier v2.0 §4.5 (endpoints REST) et §3.1 F15 (seuils γ de régression).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surface REST des réglages V1.0 — pour l'instant uniquement les 7 seuils γ
 * de régression structurelle (F15).
 *
 * Routes (namespace `htmln/v1`) :
 *
 *  - `GET  /settings/regression-thresholds` — `{ thresholds, defaults }`.
 *  - `POST /settings/regression-thresholds` — body `{ thresholds }`, retourne
 *    `{ thresholds }` après normalisation.
 *
 * Toutes les routes : permission `manage_options` (cf. §14 hyp. 14).
 *
 * Pourquoi pas de DELETE pour "restaurer les defaults" : la SPA peut envoyer
 * un POST avec les valeurs `REGRESSION_THRESHOLD_DEFAULTS`. La sémantique reste
 * cohérente (on persiste un état explicite) et la surface REST minimale.
 */
final class SettingsController extends BaseController {

	/**
	 * @param SettingsRepository $settings Repo réglages.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * Enregistre les 2 routes au hook `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns  = self::REST_NAMESPACE;
		$cap = array( $this, 'permission_check_manage_options' );

		register_rest_route( $ns, '/settings/regression-thresholds', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_regression_thresholds' ),
				'permission_callback' => $cap,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_regression_thresholds' ),
				'permission_callback' => $cap,
			),
		) );
	}

	/**
	 * `GET /settings/regression-thresholds`
	 *
	 * Réponse 200 : `{ thresholds: array<string, int>, defaults: array<string, int> }`.
	 * Les `defaults` sont retournés pour permettre à la SPA d'afficher un
	 * bouton « Restaurer les valeurs par défaut » sans avoir à recoder la
	 * source de vérité côté client.
	 *
	 * @param WP_REST_Request $request Requête (inutilisée).
	 * @return WP_REST_Response
	 */
	public function get_regression_thresholds( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->respond( array(
			'thresholds' => $this->settings->getRegressionThresholds(),
			'defaults'   => SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS,
		) );
	}

	/**
	 * `POST /settings/regression-thresholds`
	 *
	 * Body : `{ thresholds: array<string, mixed> }`. Seules les 7 clés
	 * canoniques sont retenues (les autres sont ignorées par le repo).
	 *
	 * Réponse 200 : `{ thresholds: array<string, int> }` après normalisation.
	 * Réponse 400 si `thresholds` est absent ou n'est pas un objet (rare —
	 * le wrapping `body.thresholds` est un contrat de payload pour rester
	 * extensible si V1.1 ajoute d'autres clés de réglages).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function update_regression_thresholds( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_param( 'thresholds' );
		if ( ! is_array( $payload ) ) {
			return $this->rest_error(
				'invalid_thresholds',
				'thresholds must be an object',
				400,
			);
		}
		$written = $this->settings->setRegressionThresholds( $payload );
		return $this->respond( array(
			'thresholds' => $written,
		) );
	}
}
