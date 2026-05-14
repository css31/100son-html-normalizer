/**
 * useElapsedTime — chronomètre une opération asynchrone arbitraire.
 *
 * Quand `isActive` passe de `false` à `true`, le hook capture `Date.now()`
 * et démarre un `setInterval` 1 Hz qui met à jour `elapsedSeconds`. Quand
 * `isActive` retombe à `false`, l'interval est nettoyé et la durée totale
 * est figée dans `lastDurationSeconds` — qui reste visible jusqu'à la
 * prochaine activation (utile pour afficher « opération terminée en X s »
 * de façon persistante dans l'UI).
 *
 * Utilisé par `DiffModal` pour chronométrer le calcul du surlignage diff
 * dans le Web Worker (cf. `useDiffHighlighting`) — sur les articles
 * SiteOrigin lourds, ce calcul peut prendre une minute, le chrono donne
 * un retour visuel à l'utilisateur.
 *
 * Granularité 1 Hz : volontairement basse pour éviter un re-render
 * inutile à chaque frame. La précision « à la seconde » est suffisante
 * pour le contexte (durées allant de 0 à ~60 s). Si une mesure plus fine
 * devenait utile, augmenter la fréquence à 100 ms et ajouter une décimale
 * à l'affichage côté caller.
 *
 * Cas limites :
 *  - **Activation très courte** (< 1 s) : `elapsedSeconds` reste à 0
 *    pendant l'intervalle ; au passage `true → false`, on calcule la
 *    durée réelle qui peut donc être 0 (arrondi vers le bas). C'est
 *    acceptable pour un affichage informatif.
 *  - **Unmount pendant l'opération** : la cleanup function nettoie
 *    `setInterval`. `lastDurationSeconds` n'est pas mis à jour (le
 *    state est jeté avec le composant) — pas de fuite.
 *  - **Toggle rapide** : chaque transition `false → true` reset
 *    `elapsedSeconds` à 0 et capture un nouveau `startTime`.
 */

import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * @typedef {Object} ElapsedTimeState
 * @property {number}  elapsedSeconds      Secondes écoulées depuis le dernier passage `isActive: false → true`. Vaut 0 au repos et au démarrage d'une nouvelle opération.
 * @property {?number} lastDurationSeconds Durée totale (secondes) du dernier intervalle actif terminé. Vaut `null` tant qu'aucune opération n'est arrivée à terme dans ce cycle de vie du hook.
 */

/**
 * @param {boolean} isActive Drapeau d'activité — typiquement le `isComputing` d'un autre hook ou un flag local.
 * @return {ElapsedTimeState} État courant du chronomètre.
 */
export function useElapsedTime( isActive ) {
	const [ elapsedSeconds, setElapsedSeconds ] = useState( 0 );
	const [ lastDurationSeconds, setLastDurationSeconds ] = useState( null );
	// `startTimeRef` est tenu hors du state pour éviter un re-render à
	// chaque tick juste pour propager une référence stable. Le state qui
	// déclenche les re-renders est `elapsedSeconds`.
	const startTimeRef = useRef( null );

	useEffect( () => {
		if ( ! isActive ) {
			return undefined;
		}
		startTimeRef.current = Date.now();
		setElapsedSeconds( 0 );
		// On nettoie `lastDurationSeconds` au démarrage d'une nouvelle
		// opération pour que la consommation reste explicite : pendant
		// que `isActive` est vrai, seul `elapsedSeconds` est pertinent.
		setLastDurationSeconds( null );

		const intervalId = setInterval( () => {
			if ( null !== startTimeRef.current ) {
				setElapsedSeconds(
					Math.floor( ( Date.now() - startTimeRef.current ) / 1000 )
				);
			}
		}, 1000 );

		return () => {
			clearInterval( intervalId );
			if ( null !== startTimeRef.current ) {
				setLastDurationSeconds(
					Math.floor( ( Date.now() - startTimeRef.current ) / 1000 )
				);
				startTimeRef.current = null;
			}
		};
	}, [ isActive ] );

	return { elapsedSeconds, lastDurationSeconds };
}
