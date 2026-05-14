/* global self */

/**
 * diffWorker — Web Worker dédié à la production du HTML à afficher dans
 * les panneaux Avant/Après de la modale Diff (vue Code source, toggle
 * « surlignage » activé).
 *
 * Le worker réalise trois passes en séquence pour chaque côté :
 *  1. `diffWordsWithSpace(before, after)` — algo de Myers (jsdiff). Produit
 *     un tableau de parts `{ value, added?, removed? }`. C'est l'étape la
 *     plus coûteuse — O((N+M)·D) sur les tokens — qui peut atteindre la
 *     minute sur du SiteOrigin lourd (article #16020 du corpus MMM-2).
 *  2. `Prism.highlight(code, …, 'markup')` — coloration syntaxique de la
 *     chaîne source du panneau. Linéaire, négligeable face au diff.
 *  3. `mergePrismAndDiff(prismHtml, mask, markClass)` — fusion : on parse
 *     la sortie Prism token-par-token et on insère `<mark>` aux frontières
 *     des fragments marqués, en respectant le nesting `<span>` Prism. Le
 *     résultat affiche **simultanément** coloration et surlignage diff.
 *
 * Pourquoi un Worker ? Le calcul du diff est synchrone et bloquant. En le
 * déportant ici, le main thread reste 100 % réactif pendant que le worker
 * peut tourner ~1 min sur les pires articles. Prism et la fusion sont
 * embarqués dans le même worker parce qu'ils opèrent sur les mêmes données
 * et qu'on évite ainsi un aller-retour `postMessage`.
 *
 * Piège Prism + Worker : Prism détecte automatiquement un environnement
 * worker et instancie un message handler global qui répond à TOUS les
 * `postMessage`. Il entrerait alors en conflit avec notre propre
 * `addEventListener('message')`. On désactive ce handler avec le flag
 * `self.Prism.disableWorkerMessageHandler` **avant** l'import — sinon la
 * lecture de Prism arrive trop tard, le handler est déjà attaché.
 *
 * Protocole de message :
 *  - **In**  : `{ id: number, before: string, after: string }`
 *  - **Out** : `{ id, ok: true, removedHtml, addedHtml }`
 *              ou `{ id, ok: false, error: string }`
 *
 * Robustesse : si la fusion Prism+diff plante pour un cas exotique (HTML
 * malformé que Prism aurait étrangement tokenisé, etc.), on retombe sur
 * l'ancien comportement « texte échappé + marks, sans Prism ». La modale
 * reste utilisable, juste moins jolie.
 *
 * Sécurité : output destiné à `dangerouslySetInnerHTML` côté React. Le
 * contrat est délégué à Prism (qui échappe `<`, `>`, `&`, `"`) et à
 * `mergePrismAndDiff` (qui n'injecte que des `<mark>` avec une classe en
 * dur). Pas de surface d'attaque additionnelle vs le rendu Prism direct.
 */

// Désactivation du handler auto Prism — DOIT être posé avant l'import.
self.Prism = self.Prism || {};
self.Prism.disableWorkerMessageHandler = true;

import Prism from 'prismjs';
import 'prismjs/components/prism-markup';
import { diffWordsWithSpace } from 'diff';
import { escapeHtml } from '../utils/escapeHtml';
import { buildMarkedMask, mergePrismAndDiff } from './mergePrismAndDiff';

/**
 * Construit la sortie HTML fusionnée (Prism + marks) pour un panneau.
 *
 * @param {Array<{value: string, added?: boolean, removed?: boolean}>} parts Sortie brute de `diffWordsWithSpace`.
 * @param {string}                                                     code  Chaîne source du panneau (`before` pour 'removed', `after` pour 'added').
 * @param {'removed' | 'added'}                                        mode  Quel panneau on construit.
 * @return {string} HTML safe-for-injection avec coloration Prism et surlignage `<mark>`.
 */
function buildPrismMarked( parts, code, mode ) {
	const markClass =
		'removed' === mode ? 'htmln-diff-removed' : 'htmln-diff-added';
	const mask = buildMarkedMask( parts, mode, code.length );
	const prismHtml = Prism.highlight( code, Prism.languages.markup, 'markup' );
	return mergePrismAndDiff( prismHtml, mask, markClass );
}

/**
 * Fallback historique : texte échappé brut + `<mark>`, sans Prism. Utilisé
 * en dernier recours si la fusion Prism+diff lève une exception, pour ne
 * pas perdre toute la fonctionnalité sur un cas exotique.
 *
 * @param {Array<{value: string, added?: boolean, removed?: boolean}>} parts Sortie brute de `diffWordsWithSpace`.
 * @param {'removed' | 'added'}                                        mode  Quel panneau on construit.
 * @return {string} HTML safe-for-injection (texte échappé + `<mark>`).
 */
function buildHighlightedFallback( parts, mode ) {
	const cls = 'removed' === mode ? 'htmln-diff-removed' : 'htmln-diff-added';
	return parts
		.filter( ( part ) =>
			'removed' === mode ? ! part.added : ! part.removed
		)
		.map( ( part ) => {
			const escaped = escapeHtml( part.value );
			const isMarked =
				( 'removed' === mode && part.removed ) ||
				( 'added' === mode && part.added );
			return isMarked
				? `<mark class="${ cls }">${ escaped }</mark>`
				: escaped;
		} )
		.join( '' );
}

/**
 * Calcule le HTML d'un panneau en privilégiant la voie Prism+marks, et en
 * retombant silencieusement sur le fallback si elle plante.
 *
 * @param {Array<{value: string, added?: boolean, removed?: boolean}>} parts Sortie brute de `diffWordsWithSpace`.
 * @param {string}                                                     code  Chaîne source du panneau.
 * @param {'removed' | 'added'}                                        mode  Quel panneau.
 * @return {string} HTML du panneau, jamais une exception.
 */
function buildPanelHtml( parts, code, mode ) {
	try {
		return buildPrismMarked( parts, code, mode );
	} catch ( _err ) {
		// Si Prism ou le merger plante (cas exotique non encore observé),
		// on dégrade gracieusement plutôt que de planter le worker.
		return buildHighlightedFallback( parts, mode );
	}
}

self.addEventListener( 'message', ( event ) => {
	const data = event.data || {};
	const id = data.id;
	const before = 'string' === typeof data.before ? data.before : '';
	const after = 'string' === typeof data.after ? data.after : '';

	try {
		const parts = diffWordsWithSpace( before, after );
		self.postMessage( {
			id,
			ok: true,
			removedHtml: buildPanelHtml( parts, before, 'removed' ),
			addedHtml: buildPanelHtml( parts, after, 'added' ),
		} );
	} catch ( err ) {
		self.postMessage( {
			id,
			ok: false,
			error: err && err.message ? String( err.message ) : 'worker_failed',
		} );
	}
} );
