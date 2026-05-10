/**
 * TabsHeader — barre des trois onglets de la vue Normaliser (F13).
 *
 * Onglets, dans l'ordre :
 *   1. « À normaliser (N) »      → status `to_improve`
 *   2. « Normalisés (N) »         → status `normal`
 *   3. « Diagnostics obsolètes (N) » → status `stale`
 *
 * Compteurs N issus de `useDiagnosticsStats` (alimenté depuis le store).
 * En cours de chargement, on affiche `…` à la place du compteur pour
 * signaler explicitement l'attente sans casser la mise en page.
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
 * Récupère le compteur correspondant à un onglet depuis l'objet stats.
 *
 * @param {?Object}   stats Compteurs ou null.
 * @param {TabStatus} key   Clé d'onglet.
 * @return {?number} Compteur ou null si stats indisponibles.
 */
function getCount( stats, key ) {
	if ( ! stats ) {
		return null;
	}
	const value = stats[ key ];
	return Number.isFinite( value ) ? Number( value ) : 0;
}

/**
 * @param {Object}                 props
 * @param {?Object}                props.stats       Compteurs F13 ou null si en cours de chargement.
 * @param {boolean}                props.isLoading   Vrai durant le fetch des stats.
 * @param {TabStatus}              props.currentTab  Onglet actif.
 * @param {(t: TabStatus) => void} props.onChangeTab Callback de changement d'onglet.
 * @return {JSX.Element} Barre d'onglets.
 */
export default function TabsHeader( {
	stats,
	isLoading,
	currentTab,
	onChangeTab,
} ) {
	const tabs = getTabs();

	return (
		<div
			className="htmln-tabs"
			role="tablist"
			aria-label={ __( 'Onglets diagnostic', '100son-html-normalizer' ) }
		>
			{ tabs.map( ( tab ) => {
				const isActive = tab.key === currentTab;
				const count = getCount( stats, tab.key );
				const display =
					isLoading && null === count ? '…' : String( count ?? 0 );
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
						<span
							className="htmln-tabs__count"
							aria-label={ __(
								"Nombre d'articles",
								'100son-html-normalizer'
							) }
						>
							({ display })
						</span>
					</button>
				);
			} ) }
		</div>
	);
}
