/**
 * Normalize — vue racine F13/F14 de la SPA V1.0.
 *
 * Phase 6.3 livre la partie F13 (3 onglets + tableau paginé). La partie
 * F14 (sélection articles + sidebar règles + bandeaux progression) arrive
 * en 6.4 ; les modales diff et régression en 6.5.
 *
 * État local minimal :
 *  - `currentTab` : onglet actif (initialisé à `to_improve` — entrée
 *    naturelle du workflow d'admin) ;
 *  - `currentPage` : page de pagination, remise à 1 sur changement d'onglet.
 *
 * Les compteurs F13 viennent du store @wordpress/data via
 * `useDiagnosticsStats`. La liste paginée est locale via
 * `useDiagnosticsList(status, page)`.
 */

import { __ } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import TabsHeader from './Normalize/TabsHeader';
import ArticlesTable from './Normalize/ArticlesTable';
import { useDiagnosticsStats } from '../hooks/useDiagnosticsStats';
import { useDiagnosticsList } from '../hooks/useDiagnosticsList';

/**
 * Articles par page — aligné sur `DiagnosticsController::DEFAULT_PER_PAGE`.
 */
const PER_PAGE = 50;

/**
 * Onglet par défaut au mount — `to_improve` est l'entrée la plus
 * actionnable côté admin (le workflow F14 part de là).
 *
 * @type {'to_improve'|'normal'|'stale'}
 */
const DEFAULT_TAB = 'to_improve';

/**
 * @return {JSX.Element} Vue Normalize complète (onglets + tableau).
 */
export default function Normalize() {
	const [ currentTab, setCurrentTab ] = useState( DEFAULT_TAB );
	const [ currentPage, setCurrentPage ] = useState( 1 );

	const {
		stats,
		isLoading: isStatsLoading,
		error: statsError,
	} = useDiagnosticsStats();

	const {
		items,
		total,
		totalPages,
		isLoading: isListLoading,
		error: listError,
	} = useDiagnosticsList( currentTab, currentPage, PER_PAGE );

	const handleChangeTab = useCallback( ( nextTab ) => {
		setCurrentTab( nextTab );
		setCurrentPage( 1 );
	}, [] );

	const handleChangePage = useCallback(
		( nextPage ) => {
			// Borne défensive — l'UI désactive les boutons hors plage,
			// mais un appel programmatique pourrait passer outre.
			const clamped = Math.max(
				1,
				Math.min( totalPages || 1, nextPage )
			);
			setCurrentPage( clamped );
		},
		[ totalPages ]
	);

	// Réinitialise la page si on passe à un onglet où la page courante
	// dépasse le nouveau totalPages (cas rare mais possible : 5 articles
	// stale puis on arrive sur l'onglet « Normalisés » qui n'en a aucun).
	useEffect( () => {
		if ( totalPages > 0 && currentPage > totalPages ) {
			setCurrentPage( 1 );
		}
	}, [ totalPages, currentPage ] );

	return (
		<div className="htmln-spa-root htmln-normalize">
			<TabsHeader
				stats={ stats }
				isLoading={ isStatsLoading }
				currentTab={ currentTab }
				onChangeTab={ handleChangeTab }
			/>

			{ statsError && (
				<div className="htmln-error notice notice-warning">
					<p>
						{ __(
							"Les compteurs n'ont pas pu être chargés. Le tableau reste utilisable.",
							'100son-html-normalizer'
						) }
					</p>
				</div>
			) }

			<ArticlesTable
				items={ items }
				total={ total }
				page={ currentPage }
				perPage={ PER_PAGE }
				totalPages={ totalPages }
				isLoading={ isListLoading }
				error={ listError }
				onChangePage={ handleChangePage }
			/>
		</div>
	);
}
