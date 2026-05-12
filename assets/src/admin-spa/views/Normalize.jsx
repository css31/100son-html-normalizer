/**
 * Normalize — vue racine F13/F14 de la SPA V1.0.
 *
 * Phase 6.3 : F13 (3 onglets + tableau paginé).
 * Phase 6.4 : F14 (sélection articles + sidebar règles + bandeaux + beforeunload).
 * Phase 6.5 : modales DiffModal + RegressionModal.
 * Post-rc1  : sidebar des règles **retirée**, déplacée dans l'onglet Règles
 *             dédié. La sélection des règles est désormais dans le store
 *             `htmln/spa.selectedRules` (partagée entre onglets).
 *
 * Layout post-rc1 :
 *
 *   ┌──────────────────────────────────────────────────┐
 *   │ TabsHeader                                       │
 *   │ StepResumeBanner (si pas non-finalisé en BDD)    │
 *   │ StepProgressBanner (pendant un pas)              │
 *   │ ArticlesTable (avec checkboxes, plein largeur)   │
 *   │ ─────────────────────────────────────────────    │
 *   │ Récap : N règles sélectionnées (→ Règles)        │
 *   │ [ Appliquer ce pas à K articles ]                │
 *   └──────────────────────────────────────────────────┘
 *
 * État local :
 *  - `currentTab`     — onglet actif (F13).
 *  - `currentPage`    — page de pagination (F13).
 *  - `selectedPostIds`— articles cochés (F14.1). Vides au mount.
 *
 * État partagé via store :
 *  - `selectedRules`  — règles cochées (F14.2). Toutes par défaut.
 *    Éditable depuis l'onglet Règles.
 *
 * Hooks composés :
 *  - `useDiagnosticsStats` (F13 compteurs onglets).
 *  - `useDiagnosticsList` (F13 articles paginés).
 *  - `useStepRunner` (F14 orchestration run/process/finalize).
 *  - `useBeforeunload` (F14.4 verrou onglet pendant pas en cours).
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Button } from '@wordpress/components';
import TabsHeader from './Normalize/TabsHeader';
import ArticlesTable from './Normalize/ArticlesTable';
import ScanBar from './Normalize/ScanBar';
import StepProgressBanner from './Normalize/StepProgressBanner';
import StepResumeBanner from './Normalize/StepResumeBanner';
import DiffModal from './Normalize/DiffModal';
import RegressionModal from './Normalize/RegressionModal';
import { useDiagnosticsStats } from '../hooks/useDiagnosticsStats';
import { useDiagnosticsList } from '../hooks/useDiagnosticsList';
import { useStepRunner } from '../hooks/useStepRunner';
import { useScanBatch } from '../hooks/useScanBatch';
import { useBeforeunload } from '../hooks/useBeforeunload';
import { STORE_NAME, ALL_RULE_IDS } from '../store';

const PER_PAGE = 50;

/**
 * @type {'to_improve'|'normal'|'stale'}
 */
const DEFAULT_TAB = 'to_improve';

/**
 * @return {JSX.Element} Vue Normalize complète.
 */
export default function Normalize() {
	const [ currentTab, setCurrentTab ] = useState( DEFAULT_TAB );
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ selectedPostIds, setSelectedPostIds ] = useState( () => new Set() );

	// Sélection des règles : lue depuis le store (alimentée par l'onglet Règles).
	const selectedRules = useSelect(
		( select ) => select( STORE_NAME ).getSelectedRules(),
		[]
	);

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
		confirmDecision,
		abandonStep,
	} = useStepRunner( handleStepFinalized );

	// Scan diagnostic : recompose la table `son100_htmln_diagnostics` pour
	// tous les articles publiés. À la fin, on rafraîchit stats + liste —
	// même callback que pour un pas car ce sont les mêmes données qui ont
	// pu changer (status par article, matching_rules après activation de
	// nouvelles règles, métriques après modif de seuils, etc.).
	const handleScanComplete = useCallback( () => {
		refetchStats();
		refetchList();
	}, [ refetchStats, refetchList ] );

	const {
		isScanning,
		progress: scanProgress,
		error: scanError,
		startScan,
		reset: dismissScanError,
	} = useScanBatch( handleScanComplete );

	// Article ouvert dans DiffModal pour preview à la volée
	// (bouton « Voir le diff » d'une ligne du tableau).
	const [ diffPostId, setDiffPostId ] = useState( null );

	const handleViewDiff = useCallback( ( postId ) => {
		setDiffPostId( postId );
	}, [] );

	const handleCloseDiff = useCallback( () => {
		setDiffPostId( null );
	}, [] );

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
			<ScanBar
				isScanning={ isScanning }
				progress={ scanProgress }
				error={ scanError }
				disabled={ isRunning }
				onScan={ startScan }
				onDismissError={ dismissScanError }
			/>

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
				onViewDiff={ handleViewDiff }
			/>

			<ApplyStepFooter
				selectedRules={ selectedRules }
				selectedPostCount={ selectedPostIds.size }
				disabled={ isRunning }
				onApplyStep={ handleApplyStep }
			/>

			{ /* DiffModal — preview à la volée depuis le tableau (bouton ligne). */ }
			{ null !== diffPostId && (
				<DiffModal
					postId={ diffPostId }
					ruleIds={ selectedRules }
					onClose={ handleCloseDiff }
				/>
			) }

			{ /* RegressionModal — pause sur régression pendant un pas (F15). */ }
			{ regressionPending && (
				<RegressionModal
					pending={ regressionPending }
					ruleIds={ selectedRules }
					onDecision={ confirmDecision }
				/>
			) }
		</div>
	);
}

/**
 * Bandeau récap + bouton « Appliquer ce pas » sous le tableau.
 *
 * Remplace la sidebar latérale post-rc1 : la sélection des règles est
 * gérée depuis l'onglet Règles, on n'a plus qu'à rappeler l'état courant
 * et à offrir le déclencheur du pas.
 *
 * @param {Object}     props
 * @param {string[]}   props.selectedRules     Règles cochées (store).
 * @param {number}     props.selectedPostCount Articles cochés dans le tableau.
 * @param {boolean}    props.disabled          Pas en cours.
 * @param {() => void} props.onApplyStep       Déclenche le pas.
 * @return {JSX.Element} Bloc footer.
 */
function ApplyStepFooter( {
	selectedRules,
	selectedPostCount,
	disabled,
	onApplyStep,
} ) {
	const ruleCount = selectedRules.length;
	const totalRules = ALL_RULE_IDS.length;
	const canApply = ! disabled && ruleCount > 0 && selectedPostCount > 0;

	const goToRules = useCallback( ( event ) => {
		event.preventDefault();
		window.location.hash = '#/rules';
	}, [] );

	return (
		<div className="htmln-normalize__footer">
			<div className="htmln-normalize__footer-recap">
				<strong>
					{ sprintf(
						// translators: 1 = règles sélectionnées, 2 = total.
						__(
							'%1$d / %2$d règles sélectionnées',
							'100son-html-normalizer'
						),
						ruleCount,
						totalRules
					) }
				</strong>
				{ ruleCount > 0 && (
					<>
						{ ' — ' }
						<span className="htmln-normalize__footer-rules">
							{ selectedRules.join( ', ' ) }
						</span>
					</>
				) }
				{ ' (' }
				<a href="#/rules" onClick={ goToRules }>
					{ __( 'modifier dans Règles', '100son-html-normalizer' ) }
				</a>
				{ ')' }
			</div>
			<div className="htmln-normalize__footer-action">
				<Button
					variant="primary"
					onClick={ onApplyStep }
					disabled={ ! canApply }
				>
					{ sprintf(
						// translators: %d = nombre d'articles cochés.
						__(
							'Appliquer ce pas à %d article(s)',
							'100son-html-normalizer'
						),
						selectedPostCount
					) }
				</Button>
			</div>
		</div>
	);
}
