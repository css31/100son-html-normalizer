/**
 * Point d'entrée de la SPA d'administration.
 *
 * Monte `<App />` dans le conteneur `#htmln-app` rendu côté serveur par
 * `Admin\Pages\SpaPage`. La SPA reste inerte si le conteneur est absent
 * (autres pages admin de l'extension qui ne nécessitent pas la SPA).
 */

import { createRoot } from '@wordpress/element';
import App from './App';

document.addEventListener( 'DOMContentLoaded', () => {
	const container = document.getElementById( 'htmln-app' );
	if ( ! container ) {
		return;
	}
	createRoot( container ).render( <App /> );
} );
