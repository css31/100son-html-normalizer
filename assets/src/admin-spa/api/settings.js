/**
 * Helpers REST pour le contrôleur Settings (Phase 6.7).
 *
 * Documentés côté serveur dans `includes/Rest/SettingsController.php`.
 */

import { get, post } from './client';

/**
 * `GET /settings/regression-thresholds` — récupère les 7 seuils γ courants
 * + les valeurs par défaut (cf. cahier §14 hyp. 24).
 *
 * @return {Promise<{thresholds: Object<string, number>, defaults: Object<string, number>}>} Seuils courants + defaults.
 */
export const getRegressionThresholds = () =>
	get( '/settings/regression-thresholds' );

/**
 * `POST /settings/regression-thresholds` — écrit les 7 seuils γ. Les
 * valeurs invalides sont normalisées côté serveur (cf.
 * `SettingsRepository::setRegressionThresholds`).
 *
 * @param {Object<string, number>} thresholds Map clé canonique → valeur entière ≥ 0.
 * @return {Promise<{thresholds: Object<string, number>}>} Seuils après normalisation.
 */
export const saveRegressionThresholds = ( thresholds ) =>
	post( '/settings/regression-thresholds', { thresholds } );
