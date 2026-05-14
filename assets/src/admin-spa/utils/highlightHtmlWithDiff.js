/**
 * highlightHtmlWithDiff — surlignage des fragments propres à `code` par
 * rapport à `against`, **sans** coloration syntaxique Prism.
 *
 * **STATUT** : voie sync héritée, conservée comme fallback défensif et
 * pour la signature publique de `<HighlightedCode>` (prop `diffAgainst`).
 * Le chemin nominal pour le surlignage est désormais le worker async
 * `workers/diffWorker.js` qui produit du HTML **combinant** Prism et
 * `<mark>` via `workers/mergePrismAndDiff.js`. `DiffModal` n'appelle plus
 * cette voie sync — elle reste accessible aux autres callers éventuels.
 *
 * Utilisé pour mettre en valeur visuellement, sur le panneau « Avant », les
 * portions qui n'existent plus dans « Après » (mode `'removed'`), et en
 * miroir sur « Après » ce qui est nouveau (mode `'added'`).
 *
 * **Pourquoi pas de Prism quand le surlignage est actif** : Prism est
 * synchrone et appelle des regex lourdes. L'appliquer par fragment
 * (parfois 200+ fragments sur un article moyen) cumule un coût qui peut
 * dépasser la seconde — le navigateur freeze et propose le bouton
 * « Déboguer ». L'appliquer une seule fois sur la chaîne complète puis
 * mapper les marks position-par-position demanderait un parser HTML
 * léger pour ne pas couper les `<span class="token">` de Prism — gros
 * refactor. Compromis retenu : pas de coloration Prism quand le
 * surlignage est actif. L'utilisateur bascule selon son besoin :
 *  - Surlignage OFF (default) → Prism actif, code coloré, pas de marks.
 *  - Surlignage ON → marks jaunes/verts, code en texte brut, calcul
 *    instantané sans freeze.
 *
 * **Garde-fou taille (DIFF_MARKS_MAX_CHARS)** : `diffWordsWithSpace` est
 * un Myers diff en O((N+M)·D) sur les **tokens**, qui sur du HTML
 * SiteOrigin lourd (`data-style='{"…":…}'` génère des tokens à la pelle)
 * peut prendre plusieurs minutes et freezer le main thread — Firefox
 * affiche alors « Stop script / Debug script » et la modale ne s'affiche
 * jamais. Au-delà de 40 000 caractères cumulés (before + after), on
 * désactive le surlignage : on retourne simplement `escapeHtml(code)`.
 * La modale détecte ce cas et affiche un Notice à l'utilisateur (cf.
 * `DiffModal.jsx`, prop `isLargePayload`).
 *
 * Algorithme :
 *  1. `diffWordsWithSpace(before, after)` produit un tableau de
 *     `{ value, added?, removed? }`. Granularité « mot » (espaces inclus
 *     comme caractères de séparation) — bon équilibre entre précision
 *     (changement d'attribut, de classe…) et lisibilité.
 *  2. On filtre les parts selon le `mode` : en mode `'removed'`, on
 *     garde tout sauf `added` ; en mode `'added'`, tout sauf `removed`.
 *  3. Pour chaque part conservée : échappe les caractères spéciaux HTML
 *     (`<`, `>`, `&`, `"`), enveloppe dans `<mark>` si c'est un fragment
 *     marqué, concatène.
 *
 * Sécurité : tout le texte issu de `part.value` est échappé HTML avant
 * concaténation ; les `<mark>` ne sont insérées qu'avec une classe en
 * dur (`htmln-diff-*`). Sortie sûre pour `dangerouslySetInnerHTML`.
 */

import { diffWordsWithSpace } from 'diff';
import { escapeHtml } from './escapeHtml';

/**
 * Seuil au-delà duquel le surlignage est désactivé (somme de `code.length`
 * et `against.length`). Valeur empirique calée pour qu'un article SiteOrigin
 * type « riche » (panel-layout + `data-style='{"…":…}'` sur chaque cellule)
 * reste sous la barre, et que les articles vraiment monstres (corpus MMM-2
 * id 16020 = 28 501 + 15 033 = 43 534 chars) basculent en mode dégradé.
 *
 * Exporté pour permettre à `DiffModal` de détecter le cas et afficher un
 * Notice explicatif sans appeler la fonction (qui retournerait juste un
 * fallback sans signaler la dégradation).
 *
 * @type {number}
 */
export const DIFF_MARKS_MAX_CHARS = 40000;

/**
 * @param {string}              code    Chaîne à afficher (panneau actif).
 * @param {string}              against Chaîne de référence pour le diff (panneau opposé).
 * @param {'removed' | 'added'} mode    Quels fragments envelopper dans `<mark>`.
 * @return {string} HTML safe-for-injection (texte échappé + `<mark>`).
 */
export function highlightHtmlWithDiff( code, against, mode ) {
	if ( 'string' !== typeof code || '' === code ) {
		return '';
	}
	if ( 'string' !== typeof against ) {
		// Pas de diff demandé → on retourne juste le texte échappé.
		// Le caller `HighlightedCode` n'arrive ici que si `diffAgainst`
		// est une string ; ce fallback est défensif.
		return escapeHtml( code );
	}

	// Garde-fou taille : au-delà du seuil, on saute `diffWordsWithSpace`
	// (qui pourrait freezer plusieurs secondes) et on retourne juste le
	// texte échappé. Le surlignage est perdu mais l'article reste lisible.
	if ( code.length + against.length > DIFF_MARKS_MAX_CHARS ) {
		return escapeHtml( code );
	}

	// `diffWordsWithSpace` est appelé avec l'ordre canonique (before, after).
	// En mode 'removed' on affiche le panneau Avant ⇒ `code` est `before`.
	// En mode 'added' on affiche le panneau Après ⇒ `code` est `after`.
	const before = 'removed' === mode ? code : against;
	const after = 'removed' === mode ? against : code;
	const parts = diffWordsWithSpace( before, after );
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
