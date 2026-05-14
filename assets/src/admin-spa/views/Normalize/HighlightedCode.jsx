/**
 * HighlightedCode — wrapper `<pre><code>` qui rend une chaîne HTML brute
 * avec coloration syntaxique Prism.
 *
 * Conçu pour les panneaux Avant/Après de la modale Diff (vue « Code
 * source »). Le `<pre>` reste l'élément scrollable — on **forwarde la ref**
 * vers lui pour que le verrou de synchronisation du défilement de
 * `DiffModal` continue de fonctionner sans modification.
 *
 * Le balisage produit par `highlightHtml` est injecté via
 * `dangerouslySetInnerHTML` : c'est sûr parce que Prism échappe lui-même
 * les caractères spéciaux de l'entrée avant d'insérer ses spans. La
 * mémoïsation (`useMemo`) évite de re-tokeniser à chaque render — utile
 * notamment pendant le scroll qui déclenche des re-renders via les
 * handlers de sync.
 *
 * **Mode `precomputedHtml`** : c'est désormais le chemin nominal quand le
 * toggle « surlignage » est activé dans la modale. `DiffModal` invoque
 * `useDiffHighlighting` qui orchestre un Web Worker. Ce worker produit du
 * HTML qui **combine** la coloration Prism (`<span class="token …">`) et le
 * surlignage diff (`<mark class="htmln-diff-…">`) — les deux sont visibles
 * simultanément. La chaîne pré-calculée est passée via `precomputedHtml` :
 * `HighlightedCode` la sert telle quelle et **n'appelle ni Prism ni
 * `highlightHtmlWithDiff`**. Le contrat de sécurité est délégué au
 * producteur (worker `diffWorker.js` + utilitaire `mergePrismAndDiff`).
 *
 * **Mode `code` seul** (sans `diffAgainst` ni `precomputedHtml`) : chemin
 * historique utilisé quand le toggle est désactivé — Prism colore le code,
 * pas de marks. Reste actif pour ce cas et pour `RegressionModal`.
 */

import { forwardRef, useMemo } from '@wordpress/element';
import { highlightHtml } from '../../utils/highlightHtml';
import { highlightHtmlWithDiff } from '../../utils/highlightHtmlWithDiff';

/**
 * @param {Object}            props
 * @param {string}            props.code              Chaîne HTML brute à colorer.
 * @param {Function}          [props.onScroll]        Handler scroll forwardé au `<pre>`.
 * @param {string}            [props.className]       Classe additionnelle facultative.
 * @param {?string}           [props.diffAgainst]     Chaîne de référence pour le surlignage diff. Si null/undefined, la sortie est simplement Prism (pas de `<mark>`). Ignoré si `precomputedHtml` est fourni.
 * @param {'removed'|'added'} [props.diffMode]        Quels fragments wrapper en `<mark>` quand `diffAgainst` est fourni. Default `'removed'`.
 * @param {?string}           [props.precomputedHtml] Chaîne HTML déjà calculée (par le Worker) à injecter telle quelle. Court-circuite tout le pipeline sync. Le producteur garantit le contrat de sécurité.
 * @param {Object}            ref                     Forwardée au `<pre>`.
 * @return {JSX.Element} Bloc `<pre><code>` coloré.
 */
const HighlightedCode = forwardRef( function HighlightedCode(
	{
		code,
		onScroll,
		className = '',
		diffAgainst = null,
		diffMode = 'removed',
		precomputedHtml = null,
	},
	ref
) {
	const html = useMemo( () => {
		if ( 'string' === typeof precomputedHtml ) {
			return precomputedHtml;
		}
		if ( 'string' === typeof diffAgainst ) {
			return highlightHtmlWithDiff( code, diffAgainst, diffMode );
		}
		return highlightHtml( code );
	}, [ code, diffAgainst, diffMode, precomputedHtml ] );
	const preClassName = `htmln-diff-modal__code language-markup${
		className ? ` ${ className }` : ''
	}`;
	return (
		<pre ref={ ref } onScroll={ onScroll } className={ preClassName }>
			<code
				className="language-markup"
				// eslint-disable-next-line react/no-danger -- Prism.highlight a échappé les caractères spéciaux de l'entrée avant d'insérer ses spans, l'output est sûr.
				dangerouslySetInnerHTML={ { __html: html } }
			/>
		</pre>
	);
} );

export default HighlightedCode;
