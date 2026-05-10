/* global DOMParser */

/**
 * Sanitize JS minimaliste pour injection en `srcdoc` d'iframe (F14.3).
 *
 * Cf. cahier §13 : « Iframes du DiffModal (V1.0) n'ont jamais
 * `allow-scripts` dans leur sandbox. `srcdoc` injecté côté SPA après
 * `wp_kses_post` minimal pour neutraliser scripts/handlers inline. »
 *
 * Pourquoi maison plutôt que DOMPurify : poids minimal (V1.0 n'a pas
 * d'autre besoin de sanitize), périmètre limité (le sandbox iframe
 * sans `allow-scripts` empêche déjà l'exécution JS — c'est une 2e
 * couche défensive), et `DOMParser` est natif (pas de dépendance).
 *
 * Élimine :
 *  - balises potentiellement actives : <script>, <iframe>, <embed>,
 *    <object>, <link>, <meta>, <base>, <form> ;
 *  - tous les attributs commençant par `on*` (gestionnaires inline) ;
 *  - les URLs `javascript:` dans `href` et `src`.
 *
 * Conservé : tout le reste (texte, mise en forme, images, liens
 * légitimes, classes, styles inline). C'est le contenu utile du diff.
 */

/**
 * Liste des balises supprimées intégralement (avec leur contenu).
 *
 * @type {string[]}
 */
const DANGEROUS_TAGS = [
	'script',
	'iframe',
	'embed',
	'object',
	'link',
	'meta',
	'base',
	'form',
];

/**
 * @param {string} html HTML brut, potentiellement malveillant.
 * @return {string} HTML sanitized prêt pour `srcdoc`.
 */
export function sanitizeForIframe( html ) {
	if ( 'string' !== typeof html || '' === html ) {
		return '';
	}

	const doc = new DOMParser().parseFromString( html, 'text/html' );

	// Suppression des balises actives.
	for ( const tag of DANGEROUS_TAGS ) {
		doc.querySelectorAll( tag ).forEach( ( el ) => el.remove() );
	}

	// Suppression des attributs dangereux sur tous les éléments restants.
	doc.querySelectorAll( '*' ).forEach( ( el ) => {
		Array.from( el.attributes ).forEach( ( attr ) => {
			const name = attr.name.toLowerCase();
			if ( name.startsWith( 'on' ) ) {
				el.removeAttribute( attr.name );
				return;
			}
			if (
				( 'href' === name ||
					'src' === name ||
					'xlink:href' === name ) &&
				/^\s*javascript:/i.test( attr.value )
			) {
				el.removeAttribute( attr.name );
			}
		} );
	} );

	return doc.body.innerHTML;
}
