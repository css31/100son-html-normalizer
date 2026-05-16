/**
 * Helpers REST pour les contrôleurs Posts (F8) et Diff (F14.3).
 *
 * Documentés côté serveur dans `includes/Rest/PostsController.php` et
 * `includes/Rest/DiffController.php`.
 */

import { get, post } from './client';

/**
 * `GET /posts/post-types` — types publics + drapeau default_checked F8.
 *
 * @return {Promise<Array<{slug: string, label: string, default_checked: boolean}>>} Types disponibles.
 */
export const postTypes = () => get( '/posts/post-types' );

/**
 * `GET /posts/scan` — listing paginé, drapeau has_panels_data par article.
 *
 * @param {{post_type?: string[], page?: number, per_page?: number, search?: string}} [params] Filtres + pagination.
 * @return {Promise<{items: Array, total: number, page: number, per_page: number, total_pages: number}>} Articles paginés.
 */
export const scan = ( params = {} ) => get( '/posts/scan', params );

/**
 * `POST /posts/batch-normalize` — lot ; SO ignorés silencieusement sans force.
 *
 * @param {{ids: number[], force_siteorigin?: boolean, chunk_size?: number}} body Identifiants + force éventuelle.
 * @return {Promise<{results: Object<number, Object>, summary: {modified: number, unchanged: number, skipped_siteorigin: number, errors: number}}>} Résultats par article + synthèse.
 */
export const batchNormalize = ( body ) =>
	post( '/posts/batch-normalize', body );

/**
 * `POST /posts/<id>/diff` — calcule le diff que produiraient les rule_ids,
 * sans écriture, sans révision (preview-only F14.3).
 *
 * @param {number}               postId Identifiant article.
 * @param {{rule_ids: string[]}} body   Règles à appliquer pour le diff.
 * @return {Promise<{html_before: string, html_after: string, metrics_before: Object, metrics_after: Object, warnings: string[], unchanged: boolean}>} HTML + métriques avant/après.
 */
export const diff = ( postId, body ) => post( `/posts/${ postId }/diff`, body );
