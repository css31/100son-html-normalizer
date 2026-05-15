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
 * Layout post-rc4 :
 *
 *   ┌──────────────────────────────────────────────────┐
 *   │ ScanBar       [ Scanner… ]   hint                │
 *   │ ApplyStepBar  [ Appliquer ce lot à K articles ]  │
 *   │               N/11 règles sélectionnées — R1, …   │
 *   │ FiltersBar    (search, cat, year, …, rules)      │
 *   │ TabsHeader                                       │
 *   │ StepResumeBanner (si lot non-finalisé en BDD)    │
 *   │ StepProgressBanner (pendant un lot)              │
 *   │ ArticlesTable (avec checkboxes, plein largeur)   │
 *   └──────────────────────────────────────────────────┘
 *
 * Post-rc4 : `ApplyStepBar` (anciennement `ApplyStepFooter`) déplacé
 * sous `ScanBar` pour regrouper les deux actions principales (scan
 * du corpus + application des règles) dans une même zone haute.
 *
 * État partagé via store `htmln/spa` :
 *  - `normalizeView`  — tab / page / perPage / filters / selectedPostIds.
 *    Persistant au switch d'onglets primaires de l'App (sans store, le
 *    démontage de la vue lors d'un aller-retour `#/notes` ou `#/settings`
 *    réinitialiserait toute la configuration courante).
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
import { useState, useCallback, useEffect, useMemo } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button } from '@wordpress/components';
import TabsHeader from './Normalize/TabsHeader';
import ArticlesTable from './Normalize/ArticlesTable';
import ScanBar from './Normalize/ScanBar';
import FiltersBar from './Normalize/FiltersBar';
import StepProgressBanner from './Normalize/StepProgressBanner';
import StepResumeBanner from './Normalize/StepResumeBanner';
import DiffModal from './Normalize/DiffModal';
import RegressionModal from './Normalize/RegressionModal';
import { useDiagnosticsStats } from '../hooks/useDiagnosticsStats';
import { useDiagnosticsList } from '../hooks/useDiagnosticsList';
import { useDiagnosticsFacets } from '../hooks/useDiagnosticsFacets';
import { useStepRunner } from '../hooks/useStepRunner';
import { useScanBatch } from '../hooks/useScanBatch';
import { useBeforeunload } from '../hooks/useBeforeunload';
import { useExternalSites } from '../hooks/useExternalSites';
import { STORE_NAME, ALL_RULE_IDS } from '../store';
import { formatRuleIdList } from '../utils/ruleLabels';

// Les defaults (tab `to_improve`, perPage 50, page 1, filtres vides, aucune
// sélection) vivent désormais dans `store/index.js > DEFAULT_NORMALIZE_VIEW`
// pour survivre au mount/unmount provoqué par le routeur hash de `App.jsx`.

/**
 * @return {JSX.Element} Vue Normalize complète.
 */
export default function Normalize() {
	// État de la vue persistant au switch d'onglets primaires de l'App
	// (App.jsx démonte la vue à chaque changement de route, donc tout
	// useState serait perdu). Cf. store `htmln/spa.normalizeView`.
	const view = useSelect(
		( select ) => select( STORE_NAME ).getNormalizeView(),
		[]
	);
	const {
		setNormalizeView,
		toggleNormalizeSelectedPost,
		toggleNormalizeSelectedPostsOnPage,
		clearNormalizeSelectedPosts,
	} = useDispatch( STORE_NAME );

	const currentTab = view.tab;
	const currentPage = view.page;
	const perPage = view.perPage;
	const filters = view.filters;
	// Le store conserve un array (sérialisable Redux) ; le composant et
	// `ArticlesTable` exigent un Set pour `.has()` / `.size`. Mémo pour
	// éviter de recréer le Set à chaque render quand le tableau ne change pas.
	const selectedPostIds = useMemo(
		() => new Set( view.selectedPostIds ),
		[ view.selectedPostIds ]
	);

	// Sélection des règles : lue depuis le store (alimentée par l'onglet Règles).
	const selectedRules = useSelect(
		( select ) => select( STORE_NAME ).getSelectedRules(),
		[]
	);

	// Domaines externes (Old / Prod) — pour les boutons d'ouverture par ligne
	// dans le tableau. On consomme `sites` en lecture seule ; la persistance
	// est gérée depuis l'onglet Réglages.
	const { sites: externalSites } = useExternalSites();

	const {
		stats,
		isLoading: isStatsLoading,
		error: statsError,
		refetch: refetchStats,
	} = useDiagnosticsStats();

	const {
		facets,
		isLoading: isFacetsLoading,
		refetch: refetchFacets,
	} = useDiagnosticsFacets();

	const {
		items,
		total,
		totalPages,
		isLoading: isListLoading,
		error: listError,
		refetch: refetchList,
	} = useDiagnosticsList( currentTab, currentPage, perPage, filters );

	// Callback partagé : après un pas, on doit re-récupérer compteurs + liste
	// car les statuts ont pu changer (to_improve → normal sur les articles
	// confirmés, etc.).
	const handleStepFinalized = useCallback( () => {
		refetchStats();
		refetchList();
		clearNormalizeSelectedPosts();
	}, [ refetchStats, refetchList, clearNormalizeSelectedPosts ] );

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
	// tous les articles publiés. À la fin, on rafraîchit stats + liste +
	// facets (les builders/années/categories peuvent évoluer si le scan a
	// touché de nouveaux articles).
	const handleScanComplete = useCallback( () => {
		refetchStats();
		refetchList();
		refetchFacets();
	}, [ refetchStats, refetchList, refetchFacets ] );

	const handleFiltersChange = useCallback(
		( nextFilters ) => {
			// Tout changement de filtre remet à la page 1 — sinon on peut se
			// retrouver hors plage si la nouvelle liste est plus courte.
			// Un seul dispatch pour éviter le double re-render.
			setNormalizeView( { filters: nextFilters, page: 1 } );
		},
		[ setNormalizeView ]
	);

	const handleChangePerPage = useCallback(
		( nextPerPage ) => {
			// Borne [1, MAX_PER_PAGE=1000] côté REST. On clamp ici aussi
			// pour la cohérence UI et pour absorber l'option « Tous » qui
			// passe `total` (≤ corpus) en valeur. Reset à page 1 dans le
			// même dispatch — l'offset précédent n'a plus de sens.
			const clamped = Math.max(
				1,
				Math.min( 1000, Number( nextPerPage ) || 50 )
			);
			setNormalizeView( { perPage: clamped, page: 1 } );
		},
		[ setNormalizeView ]
	);

	const {
		isScanning,
		progress: scanProgress,
		error: scanError,
		lastFinalize: scanLastFinalize,
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

	// Retrouve le titre de l'article ouvert dans le diff (il est dans la
	// page courante des items — la modale ne peut être ouverte que
	// depuis une ligne visible). Fallback chaîne vide si introuvable.
	const diffPostTitle =
		null !== diffPostId
			? String(
					items.find(
						( it ) => Number( it.post_id ) === Number( diffPostId )
					)?.post_title ?? ''
			  )
			: '';

	// Verrou onglet pendant un pas (cf. cahier §13 — beforeunload natif).
	useBeforeunload( isRunning );

	const handleChangeTab = useCallback(
		( nextTab ) => {
			setNormalizeView( { tab: nextTab, page: 1 } );
		},
		[ setNormalizeView ]
	);

	const handleChangePage = useCallback(
		( nextPage ) => {
			const clamped = Math.max(
				1,
				Math.min( totalPages || 1, nextPage )
			);
			setNormalizeView( { page: clamped } );
		},
		[ totalPages, setNormalizeView ]
	);

	useEffect( () => {
		if ( totalPages > 0 && currentPage > totalPages ) {
			setNormalizeView( { page: 1 } );
		}
	}, [ totalPages, currentPage, setNormalizeView ] );

	const handleToggleArticle = useCallback(
		( postId, checked ) => {
			toggleNormalizeSelectedPost( postId, checked );
		},
		[ toggleNormalizeSelectedPost ]
	);

	const handleToggleAllOnPage = useCallback(
		( checked ) => {
			toggleNormalizeSelectedPostsOnPage(
				items.map( ( item ) => item.post_id ),
				checked
			);
		},
		[ items, toggleNormalizeSelectedPostsOnPage ]
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
			<div className="htmln-normalize__actions">
				<ScanBar
					isScanning={ isScanning }
					progress={ scanProgress }
					error={ scanError }
					disabled={ isRunning }
					selectedPostCount={ selectedPostIds.size }
					lastFinalize={ scanLastFinalize }
					onScan={ () =>
						startScan(
							selectedPostIds.size > 0
								? Array.from( selectedPostIds )
								: null
						)
					}
					onDismissError={ dismissScanError }
					onDismissFinalize={ dismissScanError }
				/>

				<ApplyStepBar
					selectedRules={ selectedRules }
					selectedPostCount={ selectedPostIds.size }
					disabled={ isRunning }
					onApplyStep={ handleApplyStep }
				/>
			</div>

			<FiltersBar
				value={ filters }
				onChange={ handleFiltersChange }
				facets={ facets }
				isLoading={ isFacetsLoading }
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
				perPage={ perPage }
				totalPages={ totalPages }
				isLoading={ isListLoading }
				error={ listError }
				onChangePage={ handleChangePage }
				onChangePerPage={ handleChangePerPage }
				selectedIds={ selectedPostIds }
				onToggleArticle={ handleToggleArticle }
				onToggleAllOnPage={ handleToggleAllOnPage }
				disabled={ isRunning }
				onViewDiff={ handleViewDiff }
				externalSites={ externalSites }
			/>

			{ /* DiffModal — preview à la volée depuis le tableau (bouton ligne). */ }
			{ null !== diffPostId && (
				<DiffModal
					postId={ diffPostId }
					postTitle={ diffPostTitle }
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
 * Bandeau récap des règles + bouton « Appliquer ce lot » en zone haute,
 * juste sous `ScanBar` (post-rc4 : déplacé depuis le footer pour
 * regrouper les deux actions principales — scan et application — dans
 * une même section visuelle).
 *
 * La sélection des règles est gérée depuis l'onglet Règles ; ce bandeau
 * sert à rappeler l'état courant + déclencher le lot.
 *
 * @param {Object}     props
 * @param {string[]}   props.selectedRules     Règles cochées (store).
 * @param {number}     props.selectedPostCount Articles cochés dans le tableau.
 * @param {boolean}    props.disabled          Lot en cours.
 * @param {() => void} props.onApplyStep       Déclenche le lot.
 * @return {JSX.Element} Bloc actions « apply ».
 */
function ApplyStepBar( {
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
		<div className="htmln-normalize__apply-bar">
			<div className="htmln-normalize__apply-bar-action">
				<Button
					variant="primary"
					onClick={ onApplyStep }
					disabled={ ! canApply }
				>
					{ sprintf(
						// translators: %d = nombre d'articles cochés.
						__(
							'Appliquer ce lot à %d article(s)',
							'100son-html-normalizer'
						),
						selectedPostCount
					) }
				</Button>
			</div>
			<div className="htmln-normalize__apply-bar-recap">
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
						<span className="htmln-normalize__apply-bar-rules">
							{ formatRuleIdList( selectedRules ) }
						</span>
					</>
				) }
				{ ' (' }
				<a href="#/rules" onClick={ goToRules }>
					{ __( 'modifier dans Règles', '100son-html-normalizer' ) }
				</a>
				{ ')' }
			</div>
		</div>
	);
}
