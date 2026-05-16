/**
 * Identifiant de session de l'onglet SPA — UUID v4 généré une seule fois
 * par onglet et stocké dans `sessionStorage`. Perdu à la fermeture de
 * l'onglet (comportement voulu), survit à un reload (utile pour la
 * reprise de heartbeat).
 *
 * Format compatible avec la sanitisation serveur (`[a-zA-Z0-9-]`, 64
 * caractères max). Préfixe `tab-` facultatif mais aide à l'identification
 * en cas d'inspection manuelle des options WP.
 */

const STORAGE_KEY = 'htmln-spa.sessionId';

/**
 * Génère un UUID v4 via `crypto.randomUUID()` quand disponible (toutes
 * les versions modernes de Firefox/Chrome/Safari l'exposent en contexte
 * sécurisé). Fallback Math.random-based pour les contextes très anciens
 * (non garanti unique cryptographiquement, mais suffisant pour cette
 * usage non-secret).
 *
 * @return {string} UUID v4 préfixé `tab-`.
 */
function generateSessionId() {
	if (
		typeof window !== 'undefined' &&
		window.crypto &&
		typeof window.crypto.randomUUID === 'function'
	) {
		return `tab-${ window.crypto.randomUUID() }`;
	}
	// Fallback simple : Math.random ne garantit pas l'unicité globale
	// mais sur ce volume (1 admin, ~quelques onglets) c'est suffisant.
	const random = Math.random().toString( 36 ).slice( 2, 10 );
	return `tab-${ Date.now().toString( 36 ) }-${ random }`;
}

/**
 * Retourne le sessionId de cet onglet, en le générant et persistant la
 * première fois. Idempotent : appelable plusieurs fois sans changer la
 * valeur. Si `sessionStorage` est indisponible (mode privé Safari strict,
 * SSR, etc.), retombe sur un identifiant en mémoire renouvelé à chaque
 * appel — dans ce cas le verrou ne survivra pas à un reload, mais le
 * flux fonctionnel reste intact.
 *
 * @return {string} Identifiant de session.
 */
export function getSessionId() {
	if ( typeof window === 'undefined' || ! window.sessionStorage ) {
		return generateSessionId();
	}
	try {
		const existing = window.sessionStorage.getItem( STORAGE_KEY );
		if ( existing && /^[a-zA-Z0-9-]{1,64}$/.test( existing ) ) {
			return existing;
		}
		const fresh = generateSessionId().slice( 0, 64 );
		window.sessionStorage.setItem( STORAGE_KEY, fresh );
		return fresh;
	} catch ( err ) {
		return generateSessionId();
	}
}

/**
 * Supprime le sessionId stocké. Appelé après un `release` réussi pour
 * éviter qu'un reload suivant tente d'utiliser un identifiant que le
 * serveur a déjà libéré (le serveur lui-même est tolérant, mais c'est
 * plus propre côté UI).
 */
export function clearStoredSessionId() {
	if ( typeof window === 'undefined' || ! window.sessionStorage ) {
		return;
	}
	try {
		window.sessionStorage.removeItem( STORAGE_KEY );
	} catch ( err ) {
		// no-op — l'absence de cleanup n'a pas de conséquence fonctionnelle.
	}
}
