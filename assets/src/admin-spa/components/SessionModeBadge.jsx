/**
 * SessionModeBadge — pastille discrète à côté du titre indiquant le mode
 * de session courant (« Session principale » en V1.0 — un seul mode existe).
 *
 * Lit `getSessionMode` du store ; ne rend rien tant que le mode n'est pas
 * défini (évite un flash au boot avant la fin du premier acquire).
 *
 * Prévu pour accueillir un mode `'secondary'` (lecture seule) en V1.1 sans
 * changement structurel — le rendu choisit la classe et le libellé selon
 * `sessionMode`.
 */

import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { STORE_NAME } from '../store';
import { requestTakeOver } from '../api/client';

/**
 * @return {?JSX.Element} Pastille ou null si mode pas encore connu.
 */
export default function SessionModeBadge() {
	const mode = useSelect(
		( select ) => select( STORE_NAME ).getSessionMode(),
		[]
	);

	if ( null === mode ) {
		return null;
	}

	const label =
		'primary' === mode
			? __( 'Session principale', '100son-html-normalizer' )
			: __( 'Session secondaire', '100son-html-normalizer' );

	return (
		<div className="htmln-session-mode">
			<span
				className={ `htmln-session-badge htmln-session-badge--${ mode }` }
				role="status"
			>
				{ label }
			</span>
			{ 'secondary' === mode && (
				<Button
					variant="secondary"
					size="small"
					onClick={ () => requestTakeOver() }
					className="htmln-session-mode__takeover"
				>
					{ __( 'Prendre la main', '100son-html-normalizer' ) }
				</Button>
			) }
		</div>
	);
}
