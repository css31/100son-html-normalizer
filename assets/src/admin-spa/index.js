/**
 * Point d'entrée de la SPA d'administration.
 *
 * Monte `<App />` dans le conteneur `#htmln-app` rendu côté serveur par
 * `Admin\Pages\SpaPage`. La SPA reste inerte si le conteneur est absent
 * (autres pages admin de l'extension qui ne nécessitent pas la SPA).
 *
 * L'import `./store` enregistre le store @wordpress/data namespace
 * `htmln/spa` avant le premier render, garantissant que les sélecteurs
 * sont disponibles dès le mount des composants.
 *
 * Toute erreur au boot est affichée directement dans le conteneur
 * (signal visible sans devoir ouvrir DevTools) plus loggée en console.
 */

import { createRoot } from '@wordpress/element';
import App from './App';
import SessionGate from './components/SessionGate';
// Import du client REST avant tout autre import qui pourrait déclencher
// un apiFetch — le middleware d'injection du header X-Htmln-Session-Id et
// de capture des 409 doit être enregistré le plus tôt possible.
import './api/client';
import './store';
import './styles/main.scss';

/**
 * Affiche un message d'erreur lisible dans le conteneur #htmln-app pour
 * éviter qu'un échec de boot reste totalement silencieux côté UI.
 *
 * @param {HTMLElement} container Conteneur racine.
 * @param {string}      message   Message lisible.
 */
function renderBootError( container, message ) {
	container.innerHTML =
		'<div class="notice notice-error"><p><strong>SPA non chargée :</strong> ' +
		String( message ).replace( /</g, '&lt;' ) +
		'</p></div>';
}

/**
 * Monte `<App />` dans `#htmln-app` avec gestion d'erreur.
 *
 * Le bundle est chargé en pied de page par `Admin\Assets` (paramètre
 * `$in_footer = true` de `wp_enqueue_script`) — `DOMContentLoaded` peut
 * donc avoir déjà été émis quand cette fonction s'exécute. Le caller
 * vérifie `document.readyState` avant de décider d'attendre l'événement
 * ou de monter immédiatement.
 */
function bootSpa() {
	const container = document.getElementById( 'htmln-app' );
	if ( ! container ) {
		return;
	}
	try {
		if ( typeof createRoot !== 'function' ) {
			renderBootError(
				container,
				'createRoot indisponible (wp.element trop ancien ?)'
			);
			return;
		}
		createRoot( container ).render(
			<SessionGate>
				<App />
			</SessionGate>
		);
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( '[htmln-spa] boot error', error );
		renderBootError(
			container,
			error && error.message ? error.message : 'erreur inconnue'
		);
	}
}

// Le script est chargé en pied de page (in_footer=true) : à ce stade,
// `DOMContentLoaded` est très probablement déjà passé. On ne peut donc
// pas se contenter d'`addEventListener` — il faut tester `readyState`
// et monter immédiatement si le DOM est déjà parsé.
if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', bootSpa );
} else {
	bootSpa();
}
