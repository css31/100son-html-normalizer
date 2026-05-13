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

/**
 * `GET /settings/external-sites` — récupère les 2 URLs (Old / Prod) configurées
 * pour l'ouverture rapide d'un article depuis l'onglet Normaliser, plus les
 * defaults pour le bouton « Restaurer ».
 *
 * @return {Promise<{sites: {old_url: string, prod_url: string}, defaults: {old_url: string, prod_url: string}}>} URLs courantes + defaults.
 */
export const getExternalSites = () => get( '/settings/external-sites' );

/**
 * `POST /settings/external-sites` — écrit les 2 URLs. Toute valeur non-URL
 * (mauvais schéma, espaces, vide) est silencieusement remplacée par le default
 * côté serveur (cf. `SettingsRepository::setExternalSites`).
 *
 * @param {{old_url: string, prod_url: string}} sites Map clé canonique → URL.
 * @return {Promise<{sites: {old_url: string, prod_url: string}}>} URLs après normalisation.
 */
export const saveExternalSites = ( sites ) =>
	post( '/settings/external-sites', { sites } );
