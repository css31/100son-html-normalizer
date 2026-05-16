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
import { select } from '@wordpress/data';
import { getSessionId } from '../session/sessionId';
import { STORE_NAME } from '../store';

/**
 * Namespace REST V1.0 (cf. `Rest\BaseController::REST_NAMESPACE`).
 */
const NAMESPACE = 'htmln/v1';

/**
 * En-tête HTTP qui identifie l'onglet SPA auprès du verrou serveur
 * (cf. `Rest\Session\SessionLock::HEADER_SESSION_ID`).
 */
const SESSION_ID_HEADER = 'X-Htmln-Session-Id';

/**
 * Callbacks notifiés quand le serveur renvoie `409 htmln_session_locked` ou
 * `htmln_session_required` — c'est `SessionGate` qui s'inscrit pour basculer
 * la SPA en écran bloquant. Stocké dans un Set pour autoriser plusieurs
 * abonnés (HMR React) sans surcharger la mécanique.
 *
 * @type {Set<(payload: {code: string, message: string, owner: ?Object}) => void>}
 */
const lockLostListeners = new Set();

/**
 * Inscrit un listener appelé à chaque réponse REST `409 htmln_session_*`.
 * Retourne une fonction d'unsubscribe à appeler au démontage du composant.
 *
 * @param {(payload: {code: string, message: string, owner: ?Object}) => void} listener
 * @return {() => void} unsubscribe
 */
export function onSessionLockLost( listener ) {
	lockLostListeners.add( listener );
	return () => lockLostListeners.delete( listener );
}

/**
 * Listeners notifiés quand un 409 mutatif arrive **alors qu'on est en mode
 * secondaire** (lecture seule). C'est un événement attendu (action interdite
 * par design), pas une perte de session : on déclenche un toast non bloquant
 * via `<SessionGate>` au lieu de l'écran de verrou.
 *
 * @type {Set<(payload: {code: string, message: string}) => void>}
 */
const secondaryWriteBlockedListeners = new Set();

/**
 * Inscrit un listener appelé à chaque 409 mutatif en mode secondaire.
 *
 * @param {(payload: {code: string, message: string}) => void} listener
 * @return {() => void} unsubscribe
 */
export function onSecondaryWriteBlocked( listener ) {
	secondaryWriteBlockedListeners.add( listener );
	return () => secondaryWriteBlockedListeners.delete( listener );
}

/**
 * Listeners notifiés quand un composant (typiquement le badge `Session
 * secondaire`) demande la prise de contrôle. `<SessionGate>` y répond en
 * appelant `acquire(force=true)` — centralisant la logique d'acquire et
 * de heartbeat sans dupliquer côté badge.
 *
 * @type {Set<() => void>}
 */
const takeOverRequestListeners = new Set();

/**
 * Inscrit un listener appelé à chaque demande de prise de contrôle.
 *
 * @param {() => void} listener
 * @return {() => void} unsubscribe
 */
export function onTakeOverRequest( listener ) {
	takeOverRequestListeners.add( listener );
	return () => takeOverRequestListeners.delete( listener );
}

/**
 * Signale une demande de prise de contrôle aux listeners. Idempotent : si
 * plusieurs clics enchaînés, les listeners verront plusieurs appels (ils
 * peuvent gérer la déduplication via leur propre état `isForcing`).
 */
export function requestTakeOver() {
	takeOverRequestListeners.forEach( ( listener ) => {
		try {
			listener();
		} catch ( err ) {
			// eslint-disable-next-line no-console
			console.error( '[htmln-spa] takeOverRequest listener error', err );
		}
	} );
}

/**
 * Lecture défensive du mode de session courant depuis le store. Le middleware
 * tourne dès qu'apiFetch est appelé — potentiellement avant que le store soit
 * pleinement enregistré (race rare au boot). `try/catch` couvre ce cas en
 * retombant sur `false` (= comportement mode primary, le moins disruptif).
 *
 * @return {boolean} `true` ssi le store reporte explicitement `'secondary'`.
 */
function isSecondaryMode() {
	try {
		return 'secondary' === select( STORE_NAME ).getSessionMode();
	} catch ( err ) {
		return false;
	}
}

/**
 * Middleware apiFetch enregistré une seule fois au boot du module. Ajoute
 * automatiquement l'en-tête `X-Htmln-Session-Id` sur **toutes** les requêtes
 * du namespace `htmln/v1` (afin de respecter la convention serveur :
 * routes mutatives = guard avec ce header) et catche les 409 de verrou pour
 * notifier `SessionGate`.
 *
 * Le middleware n'altère pas les requêtes hors namespace (cas marginal :
 * appels REST WP natifs via `raw()`).
 */
apiFetch.use( async ( options, next ) => {
	const path = options.path ?? '';
	const isOurNamespace =
		path.startsWith( `/${ NAMESPACE }/` ) || path === `/${ NAMESPACE }`;
	if ( ! isOurNamespace ) {
		return next( options );
	}
	const headers = {
		...( options.headers ?? {} ),
		[ SESSION_ID_HEADER ]: getSessionId(),
	};
	try {
		return await next( { ...options, headers } );
	} catch ( error ) {
		const code = error?.code ?? '';
		const status = error?.data?.status ?? null;
		if (
			409 === status &&
			( 'htmln_session_locked' === code ||
				'htmln_session_required' === code )
		) {
			if ( isSecondaryMode() ) {
				// En lecture seule, un 409 est un événement *attendu* (clic
				// sur un bouton non désactivé, action automatique de vue).
				// Pas de bascule en écran bloquant — toast non disruptif.
				const payload = { code, message: error?.message ?? '' };
				secondaryWriteBlockedListeners.forEach( ( listener ) => {
					try {
						listener( payload );
					} catch ( err ) {
						// eslint-disable-next-line no-console
						console.error(
							'[htmln-spa] secondaryWriteBlocked listener error',
							err
						);
					}
				} );
			} else {
				const payload = {
					code,
					message: error?.message ?? '',
					owner: error?.data?.owner ?? null,
				};
				lockLostListeners.forEach( ( listener ) => {
					try {
						listener( payload );
					} catch ( err ) {
						// eslint-disable-next-line no-console
						console.error(
							'[htmln-spa] lockLost listener error',
							err
						);
					}
				} );
			}
		}
		throw error;
	}
} );

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
