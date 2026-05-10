/**
 * Hook React — branche `window.beforeunload` quand un pas est en cours
 * pour que le navigateur affiche son dialogue natif si l'utilisateur
 * tente de fermer l'onglet ou de naviguer ailleurs.
 *
 * Cf. cahier §3.1 F14.4 (« Une normalisation est en cours, fermer l'onglet
 * annulera les opérations restantes »).
 *
 * Le message exact n'est plus contrôlable depuis quelques années (les
 * navigateurs modernes affichent leur propre wording pour des raisons de
 * sécurité), mais positionner `event.returnValue` à une chaîne non vide
 * suffit à déclencher le dialogue de confirmation.
 */

import { useEffect } from '@wordpress/element';

/**
 * @param {boolean} active Vrai = afficher le dialogue de confirmation.
 */
export function useBeforeunload( active ) {
	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		/**
		 * @param {BeforeUnloadEvent} event Événement navigateur.
		 * @return {string} Chaîne sentinelle (le navigateur ignore le contenu).
		 */
		const handler = ( event ) => {
			event.preventDefault();
			// Compatibilité historique : certains navigateurs lisent encore
			// `returnValue` pour décider d'afficher le dialogue. La chaîne
			// est ignorée — le wording vient du navigateur lui-même.
			event.returnValue = 'pending-step';
			return 'pending-step';
		};
		window.addEventListener( 'beforeunload', handler );
		return () => {
			window.removeEventListener( 'beforeunload', handler );
		};
	}, [ active ] );
}
