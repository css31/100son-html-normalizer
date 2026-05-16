/**
 * TabsHeader — barre des trois onglets de la vue Normaliser (F13).
 *
 * Onglets, dans l'ordre :
 *   1. « À normaliser »          → status `to_improve`
 *   2. « Normalisés »            → status `normal`
 *   3. « Diagnostics obsolètes » → status `stale`
 *
 * Le compteur de l'onglet actif est affiché à côté de « Par page »
 * dans `PaginationBar` (cf. évolution UX 2026-05-16). Plus de pastille
 * de compteur dans les onglets eux-mêmes — moins d'encombrement visuel,
 * lecture du total à un seul endroit cohérent avec la pagination.
 */

import { __ } from '@wordpress/i18n';

/**
 * @typedef {'to_improve'|'normal'|'stale'} TabStatus
 */

/**
 * Définition statique des onglets — séparée du JSX pour faciliter
 * l'ajout futur d'un onglet (ex. erreurs F12 V1.1).
 *
 * @return {Array<{key: TabStatus, label: string}>} Onglets.
 */
function getTabs() {
	return [
		{
			key: 'to_improve',
			label: __( 'À normaliser', '100son-html-normalizer' ),
		},
		{ key: 'normal', label: __( 'Normalisés', '100son-html-normalizer' ) },
		{
			key: 'stale',
			label: __( 'Diagnostics obsolètes', '100son-html-normalizer' ),
		},
	];
}

/**
 * @param {Object}                 props
 * @param {TabStatus}              props.currentTab  Onglet actif.
 * @param {(t: TabStatus) => void} props.onChangeTab Callback de changement d'onglet.
 * @return {JSX.Element} Barre d'onglets.
 */
export default function TabsHeader( { currentTab, onChangeTab } ) {
	const tabs = getTabs();

	return (
		<div
			className="htmln-tabs"
			role="tablist"
			aria-label={ __( 'Onglets diagnostic', '100son-html-normalizer' ) }
		>
			{ tabs.map( ( tab ) => {
				const isActive = tab.key === currentTab;
				return (
					<button
						key={ tab.key }
						type="button"
						role="tab"
						aria-selected={ isActive }
						className={
							'htmln-tabs__tab' +
							( isActive ? ' htmln-tabs__tab--active' : '' )
						}
						onClick={ () => onChangeTab( tab.key ) }
					>
						<span className="htmln-tabs__label">{ tab.label }</span>
					</button>
				);
			} ) }
		</div>
	);
}
