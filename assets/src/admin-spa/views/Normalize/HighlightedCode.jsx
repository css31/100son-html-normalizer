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
 */

import { forwardRef, useMemo } from '@wordpress/element';
import { highlightHtml } from '../../utils/highlightHtml';

/**
 * @param {Object}   props
 * @param {string}   props.code        Chaîne HTML brute à colorer.
 * @param {Function} [props.onScroll]  Handler scroll forwardé au `<pre>`.
 * @param {string}   [props.className] Classe additionnelle facultative.
 * @param {Object}   ref               Forwardée au `<pre>`.
 * @return {JSX.Element} Bloc `<pre><code>` coloré.
 */
const HighlightedCode = forwardRef( function HighlightedCode(
	{ code, onScroll, className = '' },
	ref
) {
	const html = useMemo( () => highlightHtml( code ), [ code ] );
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
