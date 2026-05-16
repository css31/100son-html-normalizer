/**
 * Client REST des routes `/session/*` — acquérir, maintenir, libérer le
 * verrou single-user. Cf. backend `Rest\SessionController`.
 */

import { post, get } from './client';
import { getSessionId } from '../session/sessionId';

/**
 * `GET /session` — statut courant `{session, ttl}`. Pas utilisé par la
 * SPA en pratique (elle préfère acquire directement) mais exposé pour
 * complétude et debug manuel.
 *
 * @return {Promise<{session: ?Object, ttl: number}>} Statut + TTL serveur.
 */
export function getStatus() {
	return get( '/session' );
}

/**
 * `POST /session/acquire` — tente d'acquérir le verrou. Retourne
 * `{session, ttl}` en succès, lance une erreur typée `{code, message, data}`
 * en 409 (verrou détenu) ou autres erreurs.
 *
 * @param {boolean} [force=false] Forcer la prise même si un autre détient le verrou.
 * @return {Promise<{session: Object, ttl: number}>} Verrou acquis avec TTL en secondes.
 */
export function acquire( force = false ) {
	return post( '/session/acquire', {
		session_id: getSessionId(),
		force: !! force,
	} );
}

/**
 * `POST /session/heartbeat` — rafraîchit `last_seen_at` côté serveur.
 * Lance une erreur 409 si le verrou a été repris ou expiré entre temps.
 *
 * @return {Promise<{session: Object, ttl: number}>} Verrou rafraîchi avec TTL en secondes.
 */
export function heartbeat() {
	return post( '/session/heartbeat', {
		session_id: getSessionId(),
	} );
}

/**
 * `POST /session/release` — libère le verrou. Pour les libérations
 * « best-effort » au `beforeunload`, préférer `releaseBeacon()` qui
 * utilise `navigator.sendBeacon` (plus fiable au moment du unload).
 *
 * @return {Promise<{ok: boolean}>} Confirmation serveur (toujours `ok: true`).
 */
export function release() {
	return post( '/session/release', {
		session_id: getSessionId(),
	} );
}

/**
 * Libération « best-effort » au unload via `navigator.sendBeacon`. Cette
 * API garantit l'envoi de la requête même si la page se ferme dans la
 * foulée — `fetch()`/`apiFetch()` peuvent être annulés en cours de route.
 *
 * Construit le payload nativement (sans passer par apiFetch) parce que le
 * Beacon n'accepte qu'un POST simple `application/json` avec corps figé.
 * Inclut manuellement le nonce REST (lu sur `window.wpApiSettings`,
 * injecté côté serveur par WP) — sinon WP renverrait 403 avant même
 * d'atteindre le controller.
 *
 * Retourne `true` si le navigateur a accepté la mise en file d'attente
 * du beacon (pas un ack serveur — c'est asynchrone par nature).
 *
 * @return {boolean} `true` si le beacon a été mis en file d'attente.
 */
/* global navigator */
export function releaseBeacon() {
	if (
		typeof navigator === 'undefined' ||
		typeof navigator.sendBeacon !== 'function'
	) {
		return false;
	}
	const root = window.wpApiSettings?.root ?? '/wp-json/';
	const nonce = window.wpApiSettings?.nonce ?? '';
	const url = `${ root }htmln/v1/session/release?_wpnonce=${ encodeURIComponent(
		nonce
	) }`;
	const body = new Blob(
		[ JSON.stringify( { session_id: getSessionId() } ) ],
		{ type: 'application/json' }
	);
	try {
		return navigator.sendBeacon( url, body );
	} catch ( err ) {
		return false;
	}
}
