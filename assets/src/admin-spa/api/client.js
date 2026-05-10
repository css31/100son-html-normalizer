/**
 * Client REST — fines fonctions au-dessus de @wordpress/api-fetch.
 *
 * Préfixe automatique du namespace V1.0 `htmln/v1` (cf. cahier §4.5),
 * sérialisation des paramètres de requête (notamment listes `post_type[]`
 * vers la convention WP `?post_type[]=post&post_type[]=page`), et
 * propagation transparente des erreurs domaine renvoyées par les
 * contrôleurs PHP au format `{code, message, data: {status, ...}}`.
 *
 * Le nonce REST est injecté automatiquement par `@wordpress/api-fetch`
 * via le middleware nonce qu'enregistre WordPress côté serveur (cf.
 * `Admin\Assets::on_enqueue` qui fait ça via `wp_set_script_translations`
 * et l'enqueue standard).
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * Namespace REST V1.0 (cf. `Rest\BaseController::REST_NAMESPACE`).
 */
const NAMESPACE = 'htmln/v1';

/**
 * Sérialise un objet de paramètres en query string compatible WP.
 *
 * Convention :
 *  - `null` / `undefined` → omis,
 *  - tableau → `?key[]=v1&key[]=v2` (clé répétée avec suffixe `[]`),
 *  - autre scalaire → `?key=value`.
 *
 * @param {Object<string, unknown>} [params] Paramètres bruts.
 * @return {string} Query string préfixée de `?` ou chaîne vide si rien à sérialiser.
 */
function buildQuery( params ) {
	if ( ! params || typeof params !== 'object' ) {
		return '';
	}
	const sp = new URLSearchParams();
	for ( const [ key, value ] of Object.entries( params ) ) {
		if ( value === undefined || value === null || value === '' ) {
			continue;
		}
		if ( Array.isArray( value ) ) {
			for ( const item of value ) {
				if ( item !== undefined && item !== null && item !== '' ) {
					sp.append( `${ key }[]`, String( item ) );
				}
			}
		} else if ( typeof value === 'boolean' ) {
			// Convention WP : booléens passés en `1` / `0`.
			sp.append( key, value ? '1' : '0' );
		} else {
			sp.append( key, String( value ) );
		}
	}
	const qs = sp.toString();
	return qs ? `?${ qs }` : '';
}

/**
 * Appel REST bas niveau — préfixe le namespace, exécute apiFetch.
 *
 * @param {string}                                         path      Chemin relatif au namespace (commence par `/`).
 * @param {import('@wordpress/api-fetch').APIFetchOptions} [options] Options apiFetch.
 * @return {Promise<unknown>} Réponse décodée par apiFetch ou rejet typé `{code, message, data}`.
 */
function request( path, options = {} ) {
	return apiFetch( {
		path: `/${ NAMESPACE }${ path }`,
		...options,
	} );
}

/**
 * GET — paramètres de requête optionnels en deuxième argument.
 *
 * @param {string}                  path     Chemin (ex. `/diagnostics/stats`).
 * @param {Object<string, unknown>} [params] Paramètres query.
 * @return {Promise<unknown>} Réponse décodée par apiFetch.
 */
export function get( path, params ) {
	return request( `${ path }${ buildQuery( params ) }`, { method: 'GET' } );
}

/**
 * POST — corps JSON optionnel en deuxième argument (sérialisé par apiFetch).
 *
 * @param {string}                  path   Chemin.
 * @param {Object<string, unknown>} [data] Corps JSON.
 * @return {Promise<unknown>} Réponse décodée par apiFetch.
 */
export function post( path, data ) {
	return request( path, {
		method: 'POST',
		data: data ?? {},
	} );
}

/**
 * DELETE — pas de corps en V1.0.
 *
 * @param {string} path Chemin.
 * @return {Promise<unknown>} Réponse décodée par apiFetch.
 */
export function del( path ) {
	return request( path, { method: 'DELETE' } );
}

/**
 * Exposé pour les rares cas où un handler de vue veut appeler une route
 * tierce hors namespace (ex. ressource WP native). Ne pas utiliser pour
 * les endpoints `htmln/v1` — préférer `get`/`post`/`del`.
 *
 * @param {import('@wordpress/api-fetch').APIFetchOptions} options Options apiFetch complètes.
 * @return {Promise<unknown>} Réponse décodée par apiFetch.
 */
export function raw( options ) {
	return apiFetch( options );
}
