/**
 * Helpers REST pour le contrôleur Presets (onglet Règles SPA).
 *
 * Documentés côté serveur dans `includes/Rest/PresetsController.php`.
 */

import { get, post } from './client';

/**
 * `GET /presets` — liste les 11 règles (R1..R12) avec leur metadata,
 * leur état `enabled` et leurs `params` courants + defaults canoniques.
 *
 * @return {Promise<{presets: Array<{id: string, label: string, description: string, has_options: boolean, enabled: boolean, params: Object, defaults: Object}>}>} Liste des règles.
 */
export const list = () => get( '/presets' );

/**
 * `POST /presets/<id>` — met à jour `enabled` et/ou `params` d'un
 * règle. Les clés absentes du payload sont conservées telles
 * quelles en BDD. Le serveur normalise les valeurs (entiers bornés,
 * booléens, defaults sur invalide).
 *
 * @param {string}                               id   Identifiant `R1`..`R8`.
 * @param {{enabled?: boolean, params?: Object}} body Payload partiel.
 * @return {Promise<{preset: Object}>} Règle après normalisation.
 */
export const update = ( id, body ) => post( `/presets/${ id }`, body );
