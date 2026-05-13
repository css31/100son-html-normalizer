/**
 * highlightHtmlWithDiff — surlignage des fragments propres à `code` par
 * rapport à `against`, **sans** coloration syntaxique Prism.
 *
 * Utilisé par `HighlightedCode` quand la modale Diff a son toggle
 * « surlignage » activé. Sert à mettre en valeur visuellement, sur le
 * panneau « Avant », les portions qui n'existent plus dans « Après »
 * (mode `'removed'`), et en miroir sur « Après » ce qui est nouveau
 * (mode `'added'`).
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

/**
 * Échappe les caractères spéciaux HTML pour permettre l'injection sûre
 * du texte brut dans `dangerouslySetInnerHTML`.
 *
 * @param {string} text Texte brut.
 * @return {string} Texte HTML-safe.
 */
function escapeHtml( text ) {
	return String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

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
