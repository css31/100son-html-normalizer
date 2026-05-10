/**
 * Normalize — vue racine F13/F14 de la SPA V1.0.
 *
 * Phase 6.3 : F13 (3 onglets + tableau paginé).
 * Phase 6.4 : F14 (sélection articles + sidebar règles + bandeaux + beforeunload).
 * Phase 6.5 : modales DiffModal + RegressionModal (à venir).
 *
 * Layout :
 *
 *   ┌──────────────────────────────────────────────────┬──────────────┐
 *   │ TabsHeader                                       │              │
 *   │ StepResumeBanner (si pas non-finalisé en BDD)    │ RulesSidebar │
 *   │ StepProgressBanner (pendant un pas)              │ + bouton     │
 *   │ ArticlesTable (avec checkboxes)                  │ « Appliquer »│
 *   └──────────────────────────────────────────────────┴──────────────┘
 *
 * État local :
 *  - `currentTab`     — onglet actif (F13).
 *  - `currentPage`    — page de pagination (F13).
 *  - `selectedRules`  — règles cochées (F14.2). Toutes par défaut.
 *  - `selectedPostIds`— articles cochés (F14.1). Vides au mount.
 *
 * Hooks composés :
 *  - `useDiagnosticsStats` (F13 compteurs onglets).
 *  - `useDiagnosticsList` (F13 articles paginés).
 *  - `useStepRunner` (F14 orchestration run/process/finalize).
 *  - `useBeforeunload` (F14.4 verrou onglet pendant pas en cours).
 */

import { __ } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import TabsHeader from './Normalize/TabsHeader';
import ArticlesTable from './Normalize/ArticlesTable';
import RulesSidebar from './Normalize/RulesSidebar';
import StepProgressBanner from './Normalize/StepProgressBanner';
import StepResumeBanner from './Normalize/StepResumeBanner';
import { useDiagnosticsStats } from '../hooks/useDiagnosticsStats';
import { useDiagnosticsList } from '../hooks/useDiagnosticsList';
import { useStepRunner } from '../hooks/useStepRunner';
import { useBeforeunload } from '../hooks/useBeforeunload';

const PER_PAGE = 50;

/**
 * @type {'to_improve'|'normal'|'stale'}
 */
const DEFAULT_TAB = 'to_improve';

/**
 * Liste des préréglages V1.0 (alignée sur `RulesSidebar::getPresets`).
 * Toutes cochées par défaut au mount.
 *
 * @type {string[]}
 */
const DEFAULT_RULES = [ 'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8' ];

/**
 * @return {JSX.Element} Vue Normalize complète.
 */
export default function Normalize() {
	const [ currentTab, setCurrentTab ] = useState( DEFAULT_TAB );
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ selectedRules, setSelectedRules ] = useState( DEFAULT_RULES );
	const [ selectedPostIds, setSelectedPostIds ] = useState( () => new Set() );

	const {
		stats,
		isLoading: isStatsLoading,
		error: statsError,
		refetch: refetchStats,
	} = useDiagnosticsStats();

	const {
		items,
		total,
		totalPages,
		isLoading: isListLoading,
		error: listError,
		refetch: refetchList,
	} = useDiagnosticsList( currentTab, currentPage, PER_PAGE );

	// Callback partagé : après un pas, on doit re-récupérer compteurs + liste
	// car les statuts ont pu changer (to_improve → normal sur les articles
	// confirmés, etc.).
	const handleStepFinalized = useCallback( () => {
		refetchStats();
		refetchList();
		setSelectedPostIds( new Set() );
	}, [ refetchStats, refetchList ] );

	const {
		isRunning,
		progress,
		regressionPending,
		error: stepError,
		startStep,
		abandonStep,
	} = useStepRunner( handleStepFinalized );

	// Verrou onglet pendant un pas (cf. cahier §13 — beforeunload natif).
	useBeforeunload( isRunning );

	const handleChangeTab = useCallback( ( nextTab ) => {
		setCurrentTab( nextTab );
		setCurrentPage( 1 );
	}, [] );

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

	useEffect( () => {
		if ( totalPages > 0 && currentPage > totalPages ) {
			setCurrentPage( 1 );
		}
	}, [ totalPages, currentPage ] );

	const handleToggleArticle = useCallback( ( postId, checked ) => {
		setSelectedPostIds( ( prev ) => {
			const next = new Set( prev );
			if ( checked ) {
				next.add( postId );
			} else {
				next.delete( postId );
			}
			return next;
		} );
	}, [] );

	const handleToggleAllOnPage = useCallback(
		( checked ) => {
			setSelectedPostIds( ( prev ) => {
				const next = new Set( prev );
				items.forEach( ( item ) => {
					if ( checked ) {
						next.add( item.post_id );
					} else {
						next.delete( item.post_id );
					}
				} );
				return next;
			} );
		},
		[ items ]
	);

	const handleApplyStep = useCallback( () => {
		const postIds = Array.from( selectedPostIds );
		if ( 0 === postIds.length || 0 === selectedRules.length ) {
			return;
		}
		startStep( postIds, selectedRules );
	}, [ selectedPostIds, selectedRules, startStep ] );

	const activeUuid = progress?.uuid ?? null;

	return (
		<div className="htmln-spa-root htmln-normalize">
			<div className="htmln-normalize__layout">
				<main
					className="htmln-normalize__main"
					aria-label={ __(
						'Articles diagnostiqués',
						'100son-html-normalizer'
					) }
				>
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

					<StepResumeBanner
						activeUuid={ activeUuid }
						onResolved={ handleStepFinalized }
					/>

					<StepProgressBanner
						progress={ progress }
						regressionPending={ regressionPending }
						error={ stepError }
						onAbandon={ abandonStep }
					/>

					<ArticlesTable
						items={ items }
						total={ total }
						page={ currentPage }
						perPage={ PER_PAGE }
						totalPages={ totalPages }
						isLoading={ isListLoading }
						error={ listError }
						onChangePage={ handleChangePage }
						selectedIds={ selectedPostIds }
						onToggleArticle={ handleToggleArticle }
						onToggleAllOnPage={ handleToggleAllOnPage }
						disabled={ isRunning }
					/>
				</main>

				<RulesSidebar
					selectedRules={ selectedRules }
					onChangeRules={ setSelectedRules }
					selectedPostIds={ Array.from( selectedPostIds ) }
					disabled={ isRunning }
					onApplyStep={ handleApplyStep }
				/>
			</div>
		</div>
	);
}
