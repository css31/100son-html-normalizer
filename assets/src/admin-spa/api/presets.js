/**
 * Helpers REST pour le contrôleur Presets (onglet Règles SPA).
 *
 * Documentés côté serveur dans `includes/Rest/PresetsController.php`.
 */

import { get, post } from './client';

/**
 * `GET /presets` — liste les 8 préréglages (P1..P8) avec leur metadata,
 * leur état `enabled` et leurs `params` courants + defaults canoniques.
 *
 * @return {Promise<{presets: Array<{id: string, label: string, description: string, has_options: boolean, enabled: boolean, params: Object, defaults: Object}>}>} Liste des préréglages.
 */
export const list = () => get( '/presets' );

/**
 * `POST /presets/<id>` — met à jour `enabled` et/ou `params` d'un
 * préréglage. Les clés absentes du payload sont conservées telles
 * quelles en BDD. Le serveur normalise les valeurs (entiers bornés,
 * booléens, defaults sur invalide).
 *
 * @param {string}                               id   Identifiant `P1`..`P8`.
 * @param {{enabled?: boolean, params?: Object}} body Payload partiel.
 * @return {Promise<{preset: Object}>} Préréglage après normalisation.
 */
export const update = ( id, body ) => post( `/presets/${ id }`, body );
