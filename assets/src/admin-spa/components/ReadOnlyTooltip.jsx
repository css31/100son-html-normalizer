/**
 * ReadOnlyTooltip — wrap un contrôle pour afficher un Tooltip explicatif
 * uniquement quand la session est en lecture seule. No-op (passe-plat)
 * en mode primaire — aucun overhead visuel.
 *
 * Usage typique :
 *
 *   const isReadOnly = useIsReadOnly();
 *   <ReadOnlyTooltip>
 *     <Button disabled={ isReadOnly || existingDisabled }>Action</Button>
 *   </ReadOnlyTooltip>
 *
 * Notes :
 *  - WP `Tooltip` peut ne pas déclencher hover sur un `disabled` natif
 *    (les navigateurs n'émettent pas mouseenter sur input désactivé). Le
 *    wrap interne `<span>` capture le hover et propage. Le span hérite
 *    de `display: inline-flex` pour ne pas casser l'alignement.
 *  - Si plusieurs enfants sont passés, on ne wrappe pas (Tooltip exige un
 *    enfant unique) — l'appelant doit alors gérer son tooltip lui-même.
 */

import { Tooltip } from '@wordpress/components';
import { useIsReadOnly, READ_ONLY_TOOLTIP_TEXT } from '../hooks/useSession';

/**
 * @param {Object}      props
 * @param {JSX.Element} props.children Élément unique (Button, IconButton…).
 * @return {JSX.Element} `children` enveloppé d'un Tooltip en lecture seule, sinon `children` brut.
 */
export default function ReadOnlyTooltip( { children } ) {
	const isReadOnly = useIsReadOnly();
	if ( ! isReadOnly ) {
		return children;
	}
	return (
		<Tooltip text={ READ_ONLY_TOOLTIP_TEXT }>
			<span className="htmln-readonly-wrap">{ children }</span>
		</Tooltip>
	);
}
