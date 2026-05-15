/**
 * Helpers REST pour le contrôleur Diagnostics (F12/F13).
 *
 * Une fonction par route — signature stable côté SPA. Les retours sont
 * les payloads bruts décodés par apiFetch, tels que documentés dans
 * `includes/Rest/DiagnosticsController.php`.
 */

import { get, post, del } from './client';

/**
 * `GET /diagnostics?status&page&per_page&search&cat&year&month&builder&rule_ids[]` —
 * liste paginée filtrable (post-rc3 : search + cat/year/month/builder ;
 * post-rc4 : `rule_ids[]` multi-sélection sur règles applicables, OR).
 *
 * @param {{
 *   status?: 'normal'|'to_improve'|'stale',
 *   page?: number,
 *   per_page?: number,
 *   search?: string,
 *   cat?: number,
 *   year?: number,
 *   month?: number,
 *   builder?: 'siteorigin'|'gutenberg'|'other'|'out',
 *   rule_ids?: string[],
 * }} [params] Paramètres de requête.
 * @return {Promise<{items: Array, total: number, page: number, per_page: number, total_pages: number}>} Enveloppe paginée.
 */
export const list = ( params = {} ) => get( '/diagnostics', params );

/**
 * `GET /diagnostics/facets` — données pour populer les dropdowns des
 * filtres SPA (post-rc3, étendu post-rc4 avec `applicable_rules`).
 *
 * @return {Promise<{years: number[], categories: Array<{id: number, name: string, count: number}>, builders: Object<string, number>, applicable_rules: Object<string, number>}>} Facets.
 */
export const facets = () => get( '/diagnostics/facets' );

/**
 * `GET /diagnostics/<post_id>` — détail.
 *
 * @param {number} postId Identifiant article.
 * @return {Promise<{diagnostic: Object}>} Diagnostic ou rejet 404 si inconnu.
 */
export const find = ( postId ) => get( `/diagnostics/${ postId }` );

/**
 * `DELETE /diagnostics/<post_id>` — force re-scan au prochain run.
 * Idempotent : 200 + `{deleted: false}` si rien à supprimer.
 *
 * @param {number} postId Identifiant article.
 * @return {Promise<{deleted: boolean, post_id: number}>} Confirmation suppression.
 */
export const remove = ( postId ) => del( `/diagnostics/${ postId }` );

/**
 * `POST /diagnostics/run` — démarre un scan batch (F12).
 *
 * @param {{chunk_size?: number, post_types?: string[]}} [body] Paramètres scan.
 * @return {Promise<{job_id: string, total_articles: number, post_ids: number[], chunk_size: number}>} Métadonnées du batch.
 */
export const runBatch = ( body = {} ) => post( '/diagnostics/run', body );

/**
 * `POST /diagnostics/run/chunk` — traite un chunk d'articles.
 *
 * @param {{job_id?: string, chunk_post_ids: number[]}} body Lot d'articles à diagnostiquer.
 * @return {Promise<{job_id: string, processed: number, total: number}>} Compteurs du chunk.
 */
export const runChunk = ( body ) => post( '/diagnostics/run/chunk', body );

/**
 * `POST /diagnostics/finalize-scan` — appelé après la dernière boucle de
 * chunks. Si le scan couvre 100 % du corpus, désactive automatiquement
 * les règles `complete` (épuisées) et retourne leurs IDs.
 *
 * @return {Promise<{auto_disabled_rules: string[], fully_scanned: boolean}>} Liste des IDs auto-désactivés + flag de couverture.
 */
export const finalizeScan = () => post( '/diagnostics/finalize-scan', {} );

/**
 * `GET /diagnostics/stats` — compteurs onglets F13.
 *
 * @return {Promise<{normal: number, to_improve: number, stale: number, total: number}>} Compteurs des 4 catégories.
 */
export const stats = () => get( '/diagnostics/stats' );
