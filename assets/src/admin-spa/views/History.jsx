/**
 * History — vue racine F16 (historique des pas).
 *
 * Phase 6.6 : tableau paginé `/steps` + détail `/steps/<uuid>` avec
 * `per_article_results`.
 *
 * Layout simple : un bandeau d'erreur éventuel + le tableau + le drawer
 * de détail superposé (modale plein écran). Pas de sidebar — la vue
 * Historique n'a pas d'action latérale (lecture seule en V1.0).
 *
 * État local :
 *  - `currentPage`   — page courante (≥ 1).
 *  - `selectedUuid`  — UUID du pas dont le drawer est ouvert, ou null.
 *
 * Hooks composés :
 *  - `useStepsList(page, perPage)` — liste paginée.
 *  - `useStepDetail(uuid)`        — détail, activé uniquement si `uuid`.
 */

import { __ } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import StepsTable from './History/StepsTable';
import StepDetailDrawer from './History/StepDetailDrawer';
import { useStepsList } from '../hooks/useStepsList';
import { useStepDetail } from '../hooks/useStepDetail';

/**
 * Nombre de pas par page (cohérent avec `StepsController::DEFAULT_PER_PAGE`).
 *
 * @type {number}
 */
const PER_PAGE = 50;

/**
 * @return {JSX.Element} Vue History complète.
 */
export default function History() {
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ selectedUuid, setSelectedUuid ] = useState( null );

	const { items, total, totalPages, isLoading, error } = useStepsList(
		currentPage,
		PER_PAGE
	);

	const {
		step,
		progress,
		isLoading: isDetailLoading,
		error: detailError,
	} = useStepDetail( selectedUuid );

	const handleChangePage = useCallback(
		( nextPage ) => {
			const clamped = Math.max(
				1,
				Math.min( totalPages || 1, nextPage )
			);
			setCurrentPage( clamped );
		},
		[ totalPages ]
	);

	const handleViewDetail = useCallback( ( uuid ) => {
		setSelectedUuid( uuid );
	}, [] );

	const handleCloseDetail = useCallback( () => {
		setSelectedUuid( null );
	}, [] );

	// Si la pagination passe en dehors des bornes (par ex. au refetch
	// après navigation), retomber en page 1.
	useEffect( () => {
		if ( totalPages > 0 && currentPage > totalPages ) {
			setCurrentPage( 1 );
		}
	}, [ totalPages, currentPage ] );

	return (
		<div className="htmln-history">
			<header className="htmln-history__header">
				<h2>
					{ __( 'Historique des lots', '100son-html-normalizer' ) }
				</h2>
				<p className="description">
					{ __(
						'Toutes les exécutions de lots normaliseurs sont tracées ici. Chaque lot conserve la liste des règles appliquées, le résultat par article (succès, régression confirmée, refus, erreur) et permet de retrouver l’écosystème exact d’une intervention passée.',
						'100son-html-normalizer'
					) }
				</p>
			</header>

			<StepsTable
				items={ items }
				total={ total }
				page={ currentPage }
				totalPages={ totalPages }
				isLoading={ isLoading }
				error={ error }
				onChangePage={ handleChangePage }
				onViewDetail={ handleViewDetail }
			/>

			{ null !== selectedUuid && (
				<StepDetailDrawer
					step={ step }
					progress={ progress }
					isLoading={ isDetailLoading }
					error={ detailError }
					onClose={ handleCloseDetail }
				/>
			) }
		</div>
	);
}
