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
 * Surface REST des réglages V1.0.
 *
 * Routes (namespace `htmln/v1`) :
 *
 *  - `GET  /settings/regression-thresholds` — `{ thresholds, defaults }`.
 *  - `POST /settings/regression-thresholds` — body `{ thresholds }`, retourne
 *    `{ thresholds }` après normalisation.
 *  - `GET  /settings/external-sites` — `{ sites, defaults }`.
 *  - `POST /settings/external-sites` — body `{ sites }`, retourne
 *    `{ sites }` après normalisation.
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
		$ns        = self::REST_NAMESPACE;
		$cap       = array( $this, 'permission_check_manage_options' );
		$can_write = array( $this, 'permission_check_locked' );

		register_rest_route( $ns, '/settings/regression-thresholds', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_regression_thresholds' ),
				'permission_callback' => $cap,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_regression_thresholds' ),
				'permission_callback' => $can_write,
			),
		) );

		register_rest_route( $ns, '/settings/external-sites', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_external_sites' ),
				'permission_callback' => $cap,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_external_sites' ),
				'permission_callback' => $can_write,
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

	/**
	 * `GET /settings/external-sites`
	 *
	 * Réponse 200 : `{ sites: array{old_url: string, prod_url: string}, defaults: array{old_url: string, prod_url: string} }`.
	 * Les `defaults` permettent à la SPA d'afficher un bouton « Restaurer
	 * les valeurs par défaut » sans recoder la source de vérité côté client.
	 *
	 * @param WP_REST_Request $request Requête (inutilisée).
	 * @return WP_REST_Response
	 */
	public function get_external_sites( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->respond( array(
			'sites'    => $this->settings->getExternalSites(),
			'defaults' => SettingsRepository::EXTERNAL_SITES_DEFAULTS,
		) );
	}

	/**
	 * `POST /settings/external-sites`
	 *
	 * Body : `{ sites: array<string, mixed> }`. Seules les 2 clés canoniques
	 * (`old_url`, `prod_url`) sont retenues. Toute valeur invalide (mauvais
	 * schéma, vide, non-string) retombe sur le default côté repo.
	 *
	 * Réponse 200 : `{ sites }` après normalisation.
	 * Réponse 400 si `sites` est absent ou n'est pas un objet — même contrat
	 * que `update_regression_thresholds`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function update_external_sites( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_param( 'sites' );
		if ( ! is_array( $payload ) ) {
			return $this->rest_error(
				'invalid_sites',
				'sites must be an object',
				400,
			);
		}
		$written = $this->settings->setExternalSites( $payload );
		return $this->respond( array(
			'sites' => $written,
		) );
	}
}
