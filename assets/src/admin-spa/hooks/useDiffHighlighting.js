/* global Worker */

/**
 * useDiffHighlighting — hook React qui orchestre le calcul asynchrone du
 * surlignage Avant/Après via un Web Worker dédié.
 *
 * Utilisé exclusivement par `DiffModal` pour les articles dont la taille
 * combinée dépasse `DIFF_MARKS_MAX_CHARS` (cf. `highlightHtmlWithDiff`).
 * Sous ce seuil, le calcul sync est instantané et la modale n'instancie
 * pas ce hook.
 *
 * Cycle de vie :
 *  1. `enabled` passe à `true` (ou les inputs changent) → un Worker est
 *     instancié, on lui poste un message `{ id, before, after }` ;
 *  2. pendant le calcul : `isComputing === true`, les sorties HTML sont
 *     `null` → `DiffModal` affiche du texte échappé brut + spinner ;
 *  3. quand le worker répond avec un `id` qui matche la requête courante :
 *     `isComputing === false`, `removedHtml`/`addedHtml` sont remplis,
 *     `DiffModal` réinjecte les versions markées via `precomputedHtml`.
 *  4. cleanup à l'unmount ou au changement d'inputs : `worker.terminate()`
 *     interrompt immédiatement le calcul (utile si l'utilisateur ferme
 *     la modale avant la fin).
 *
 * Race condition sur `id` : si les inputs changent pendant qu'un worker
 * tourne, on incrémente `requestIdRef` et on termine l'ancien worker
 * dans le cleanup. Mais un postMessage déjà en vol peut arriver après
 * la nouvelle requête ; le filtrage `data.id !== currentId` côté
 * `'message'` handler ignore ce résidu.
 *
 * Fallback navigateurs sans Worker (ou bloqué par CSP) : `new Worker()`
 * throw → on capture, on log côté console, et on retourne un état
 * `{ error: 'worker_unavailable' }`. Le caller affichera le Notice
 * « article volumineux, surlignage désactivé » comme avant le Worker.
 *
 * Politique d'erreur : on **ne** réagit **pas** dans l'UI à l'event natif
 * `error` du Worker. Cet event peut fire pour des incidents non fatals
 * (warnings d'init Prism, exceptions transitoires recouvrées), et plus
 * d'une fois on a vu le worker fire `error` puis délivrer correctement
 * son résultat juste après — la notice « calcul échoué » apparaissait
 * brièvement avant d'être effacée par l'arrivée du résultat (cf. article
 * #374 du corpus MMM-2). Seul le message explicite `{ ok: false, error }`
 * envoyé via `postMessage` par notre propre `try/catch` worker fait foi.
 * Les events `error` natifs sont loggués en console pour diagnostic.
 */

import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * @typedef {Object} DiffHighlightingState
 * @property {?string} removedHtml HTML markup pour le panneau Avant, ou null si pas encore calculé.
 * @property {?string} addedHtml   HTML markup pour le panneau Après, ou null si pas encore calculé.
 * @property {boolean} isComputing Vrai pendant que le worker calcule.
 * @property {?string} error       Message d'erreur si le worker a échoué (ou null).
 */

const INITIAL_STATE = {
	removedHtml: null,
	addedHtml: null,
	isComputing: false,
	error: null,
};

/**
 * @param {string}  before  HTML brut avant normalisation.
 * @param {string}  after   HTML brut après normalisation.
 * @param {boolean} enabled Si false, le hook ne fait rien et retourne l'état initial.
 * @return {DiffHighlightingState} État courant du calcul.
 */
export function useDiffHighlighting( before, after, enabled ) {
	const [ state, setState ] = useState( INITIAL_STATE );

	// Compteur monotone des requêtes : permet de filtrer les réponses
	// d'anciens workers qui arriveraient après un changement d'inputs.
	const requestIdRef = useRef( 0 );

	useEffect( () => {
		if (
			! enabled ||
			'string' !== typeof before ||
			'string' !== typeof after
		) {
			setState( INITIAL_STATE );
			return undefined;
		}

		const currentId = ++requestIdRef.current;
		setState( {
			removedHtml: null,
			addedHtml: null,
			isComputing: true,
			error: null,
		} );

		let worker;
		try {
			worker = new Worker(
				new URL( '../workers/diffWorker.js', import.meta.url )
			);
		} catch ( _err ) {
			setState( {
				removedHtml: null,
				addedHtml: null,
				isComputing: false,
				error: 'worker_unavailable',
			} );
			return undefined;
		}

		const handleMessage = ( event ) => {
			const data = event.data;
			// Filtre anti-race : on ignore les réponses d'anciennes requêtes
			// qui auraient été postées avant le cleanup (rare, mais possible).
			if ( ! data || data.id !== currentId ) {
				return;
			}
			if ( data.ok ) {
				setState( {
					removedHtml: String( data.removedHtml ?? '' ),
					addedHtml: String( data.addedHtml ?? '' ),
					isComputing: false,
					error: null,
				} );
			} else {
				setState( {
					removedHtml: null,
					addedHtml: null,
					isComputing: false,
					error: String( data.error || 'worker_failed' ),
				} );
			}
		};

		// L'event natif `error` d'un Worker fire pour des erreurs **non
		// nécessairement fatales** : warnings d'init des libs embarquées
		// (Prism par exemple), exceptions transitoires recouvrées par la
		// boucle d'événements, etc. Plusieurs cas observés où le worker
		// fire un `error` *puis* delivre le résultat avec succès — on
		// risquait alors d'afficher brièvement « Le calcul a échoué » avant
		// que le résultat n'arrive et n'efface la notice (cf. article #374
		// du corpus MMM-2). On loggue donc à des fins de diagnostic, mais
		// on ne touche **pas** à l'état UI : seul un message explicite
		// `{ ok: false, error }` envoyé par le worker via `postMessage`
		// (et capté par `handleMessage`) signale une vraie erreur fatale.
		const handleError = ( event ) => {
			// eslint-disable-next-line no-console
			console.warn(
				'[diffWorker] error event (non-fatal, ignored in UI):',
				event?.message || event?.filename || event
			);
		};

		worker.addEventListener( 'message', handleMessage );
		worker.addEventListener( 'error', handleError );
		worker.postMessage( { id: currentId, before, after } );

		return () => {
			worker.removeEventListener( 'message', handleMessage );
			worker.removeEventListener( 'error', handleError );
			worker.terminate();
		};
	}, [ before, after, enabled ] );

	return state;
}
