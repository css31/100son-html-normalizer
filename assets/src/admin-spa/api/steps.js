/**
 * Helpers REST pour le contrôleur Steps (F14 application par pas + F16 historique).
 *
 * Documentés côté serveur dans `includes/Rest/StepsController.php`.
 */

import { get, post } from './client';

/**
 * `GET /steps` — historique paginé, filtres date optionnels.
 *
 * @param {{page?: number, per_page?: number, from?: string, to?: string}} [params] Pagination + bornes datetime MySQL.
 * @return {Promise<{items: Array, total: number, page: number, per_page: number, total_pages: number}>} Enveloppe paginée.
 */
export const list = ( params = {} ) => get( '/steps', params );

/**
 * `GET /steps/<uuid>` — détail (StepRecord) + progression (resume_progress).
 *
 * @param {string} uuid UUID v4 du pas.
 * @return {Promise<{step: Object, progress: {uuid: string, total_articles: number, processed: number[], regression_pending: number[], pending: number[]}}>} Détail + progression.
 */
export const find = ( uuid ) => get( `/steps/${ uuid }` );

/**
 * `POST /steps/run` — démarre un nouveau pas (UUID v4 généré côté serveur).
 *
 * @param {{post_ids: number[], rule_ids: string[]}} body Articles cibles + IDs de règles cochées.
 * @return {Promise<{uuid: string, total_articles: number}>} UUID serveur + total.
 */
export const run = ( body ) => post( '/steps/run', body );

/**
 * `POST /steps/<uuid>/process` — traite un chunk d'articles. Pas de fail-fast :
 * chaque article a son `ArticleResult` individuel dans `results`.
 *
 * @param {string}                                        uuid UUID v4 du pas.
 * @param {{chunk_post_ids: number[], dry_run?: boolean}} body Lot d'articles + drapeau simulation.
 * @return {Promise<{results: Object<number, Object>, processed_count: number}>} Résultats par article.
 */
export const processChunk = ( uuid, body ) =>
	post( `/steps/${ uuid }/process`, body );

/**
 * `POST /steps/<uuid>/confirm-article` — décision admin sur régression.
 *
 * @param {string}                                          uuid UUID v4 du pas.
 * @param {{post_id: number, decision: 'confirm'|'refuse'}} body Décision sur l'article.
 * @return {Promise<{result: Object}>} ArticleResult final post-décision.
 */
export const confirmArticle = ( uuid, body ) =>
	post( `/steps/${ uuid }/confirm-article`, body );

/**
 * `POST /steps/<uuid>/finalize` — clôt le pas, idempotent.
 *
 * @param {string} uuid UUID v4 du pas.
 * @return {Promise<{step: Object}>} StepRecord finalisé avec compteurs.
 */
export const finalize = ( uuid ) => post( `/steps/${ uuid }/finalize` );

/**
 * `GET /steps/export` — export non paginé, capé à 200 entrées (V1.0 JSON).
 *
 * @param {{from?: string, to?: string}} [params] Bornes datetime MySQL optionnelles.
 * @return {Promise<{items: Array, total: number, capped: boolean, capped_at: number}>} Pas exportés + drapeau si capé.
 */
export const exportAll = ( params = {} ) => get( '/steps/export', params );
