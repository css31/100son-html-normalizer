/**
 * Normalize — vue racine F13/F14 de la SPA V1.0.
 *
 * Phase 6.3 : F13 (3 onglets + tableau paginé).
 * Phase 6.4 : F14 (sélection articles + sidebar règles + bandeaux + beforeunload).
 * Phase 6.5 : modales DiffModal + RegressionModal.
 * Post-rc1   : sidebar des règles **retirée**, déplacée dans l'onglet Règles
 *              dédié.
 * 2026-05-16 : fusion finale des concepts « Activée » et « Dans le lot ».
 *              Le toggle « Activée » de chaque règle pilote désormais
 *              à la fois l'évaluation par le scan ET l'application au
 *              prochain « Appliquer ce lot ». La sélection éphémère
 *              localStorage (`selectedRules`) a été supprimée du store.
 *              `applicableRules` est dérivé à la volée de
 *              `usePresets().presets` filtrés sur `enabled = true`.
 *
 * Layout post-rc4 :
 *
 *   ┌──────────────────────────────────────────────────┐
 *   │ ScanBar       [ Scanner… ]   hint                │
 *   │ ApplyStepBar  [ Appliquer ce lot à K articles ]  │
 *   │               N/16 règles activées — R1, …       │
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
import { Button, Notice } from '@wordpress/components';
import { useIsReadOnly } from '../hooks/useSession';
import ReadOnlyTooltip from '../components/ReadOnlyTooltip';
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
import { usePresets } from '../hooks/usePresets';
import { STORE_NAME, ALL_RULE_IDS } from '../store';
import { formatRuleIdList } from '../utils/ruleLabels';

// Les defaults (tab `to_improve`, perPage 50, page 1, filtres vides, aucune
// sélection) vivent désormais dans `store/index.js > DEFAULT_NORMALIZE_VIEW`
// pour survivre au mount/unmount provoqué par le routeur hash de `App.jsx`.

// Seuil au-delà duquel `ApplyStepBar` affiche un bandeau de rappel
// « sauvegarde recommandée » au-dessus du bouton « Appliquer ce lot ».
// En-dessous (lots de test, ajustements ponctuels), le bandeau reste
// silencieux pour éviter la friction. Choix éditorial : 10 — au-delà,
// la portée d'un éventuel rollback manuel via les révisions WP devient
// fastidieuse (10 articles à comparer un par un).
const LARGE_LOT_THRESHOLD = 10;

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

	// Scope du scan — post-rc4. Trois axes cumulables :
	//   - sélection d'articles (cochés dans le tableau) : priorité 1, court-circuite filtres + include_ok.
	//   - filtres FiltersBar actifs (cat/year/month/builder/search) : si présents, scope le scan complet.
	//   - `includeOk` (checkbox) : sémantique UI inversée — cochée = inclure
	//     les articles OK ; décochée (par défaut, depuis 2026-05-18) = les
	//     exclure du scan, ce qui envoie `exclude_normalized = true` au
	//     backend (= retire les articles classés Gutenberg du scope, focus
	//     sur ce qui reste à diagnostiquer en phase de migration).
	// `hasActiveFilters` détecte au moins un filtre non-trivial pour piloter
	// le libellé bouton de ScanBar (« Scanner les articles filtrés » vs « Scanner le corpus »).
	const [ includeOk, setIncludeOk ] = useState( false );
	const hasActiveFilters = useMemo( () => {
		if ( ! filters || 'object' !== typeof filters ) {
			return false;
		}
		const search = String( filters.search ?? '' ).trim();
		return (
			'' !== search ||
			Number( filters.cat_id ) > 0 ||
			Number( filters.year ) > 0 ||
			Number( filters.month ) > 0 ||
			'' !== String( filters.builder ?? '' )
		);
	}, [ filters ] );

	// Règles applicables au prochain « Appliquer ce lot » : depuis 2026-05-16,
	// le toggle « Activée » de chaque règle pilote à la fois l'évaluation par
	// le scan et l'application au lot — fini la dualité « Dans le lot » /
	// « Activée ». La liste est donc dérivée directement des presets
	// `enabled = true` (ordre canonique préservé par PresetRegistry côté
	// serveur). Vide tant que `usePresets()` n'a pas résolu son premier fetch.
	const { presets: presetsList } = usePresets();
	const applicableRules = useMemo( () => {
		if ( ! Array.isArray( presetsList ) ) {
			return [];
		}
		return presetsList
			.filter( ( p ) => Boolean( p.enabled ) )
			.map( ( p ) => p.id );
	}, [ presetsList ] );

	// Domaines externes (Old / Prod) — pour les boutons d'ouverture par ligne
	// dans le tableau. On consomme `sites` en lecture seule ; la persistance
	// est gérée depuis l'onglet Réglages.
	const { sites: externalSites } = useExternalSites();

	// `stats` et `isLoading` ne sont plus consommés depuis 2026-05-16 : les
	// compteurs par onglet ne s'affichent plus dans `<TabsHeader>`. Le total
	// affiché à côté de « Par page » provient de `useDiagnosticsList()` qui
	// reflète l'état filtré courant. On garde le hook pour son `refetch()`
	// déclenché côté code après chaque scan/lot, et `statsError` pour la
	// notice utilisateur en cas d'échec du fetch.
	const { error: statsError, refetch: refetchStats } = useDiagnosticsStats();

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
		if ( 0 === postIds.length || 0 === applicableRules.length ) {
			return;
		}
		startStep( postIds, applicableRules );
	}, [ selectedPostIds, applicableRules, startStep ] );

	const activeUuid = progress?.uuid ?? null;

	return (
		<div className="htmln-spa-root htmln-normalize">
			<div className="htmln-normalize__actions">
				<section
					className="htmln-normalize__action-card htmln-normalize__action-card--scan"
					aria-label={ __(
						'Scanner le corpus',
						'100son-html-normalizer'
					) }
				>
					<ScanBar
						isScanning={ isScanning }
						progress={ scanProgress }
						error={ scanError }
						disabled={ isRunning }
						selectedPostCount={ selectedPostIds.size }
						hasActiveFilters={ hasActiveFilters }
						includeOk={ includeOk }
						onToggleIncludeOk={ setIncludeOk }
						lastFinalize={ scanLastFinalize }
						onScan={ () => {
							// Trois modes mutuellement exclusifs :
							//   - sélection (cochés) : `filters` + include_ok ignorés serveur-side (mode chunk direct).
							//   - filtres + include_ok : scope du scan complet via `/run`. La case
							//     UI « Inclure les articles OK » est inversée par rapport à
							//     l'API : décochée → `exclude_normalized = true`.
							//   - corpus complet : aucun param de scope.
							const hasSelection = selectedPostIds.size > 0;
							const postIds = hasSelection
								? Array.from( selectedPostIds )
								: null;
							const runFilters = hasSelection ? {} : filters;
							const runExcludeNormalized = hasSelection
								? false
								: ! includeOk;
							startScan(
								postIds,
								runFilters,
								runExcludeNormalized
							);
						} }
						onDismissError={ dismissScanError }
						onDismissFinalize={ dismissScanError }
					/>
				</section>

				<section
					className="htmln-normalize__action-card htmln-normalize__action-card--apply"
					aria-label={ __(
						'Appliquer ce lot',
						'100son-html-normalizer'
					) }
				>
					<ApplyStepBar
						selectedRules={ applicableRules }
						selectedPostCount={ selectedPostIds.size }
						disabled={ isRunning }
						onApplyStep={ handleApplyStep }
					/>
				</section>
			</div>

			<FiltersBar
				value={ filters }
				onChange={ handleFiltersChange }
				facets={ facets }
				isLoading={ isFacetsLoading }
			/>

			<TabsHeader
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
					ruleIds={ applicableRules }
					onClose={ handleCloseDiff }
				/>
			) }

			{ /* RegressionModal — pause sur régression pendant un pas (F15). */ }
			{ regressionPending && (
				<RegressionModal
					pending={ regressionPending }
					ruleIds={ applicableRules }
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
	const isReadOnly = useIsReadOnly();
	const ruleCount = selectedRules.length;
	const totalRules = ALL_RULE_IDS.length;
	const canApply = ! disabled && ruleCount > 0 && selectedPostCount > 0;
	// Bandeau de rappel « sauvegarde » au-dessus du bouton dès que le lot
	// atteint LARGE_LOT_THRESHOLD articles. Silencieux en-dessous (lots de
	// test, ajustements ponctuels). Non bloquant, non dismissible — purement
	// informatif. Le plugin lui-même ne fait pas de backup BDD (seules les
	// révisions WP natives sont créées via `wp_save_post_revision()` avant
	// chaque écriture, cf. StepRunner.php).
	const isLargeLot = selectedPostCount >= LARGE_LOT_THRESHOLD;

	const goToRules = useCallback( ( event ) => {
		event.preventDefault();
		window.location.hash = '#/rules';
	}, [] );

	return (
		<>
			{ isLargeLot && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="htmln-normalize__backup-reminder"
				>
					<strong>
						{ sprintf(
							// translators: %d = nombre d'articles concernés par le lot.
							__(
								'Lot important : %d articles.',
								'100son-html-normalizer'
							),
							selectedPostCount
						) }
					</strong>{ ' ' }
					{ __(
						'WordPress enregistre automatiquement une révision avant chaque modification (visible dans l’éditeur de chaque article via « Comparer les révisions »).',
						'100son-html-normalizer'
					) }{ ' ' }
					<strong>
						{ __(
							'Avant un lot de cette taille, nous recommandons fortement une sauvegarde complète de la base de données via votre solution habituelle (UpdraftPlus, BackWPup, snapshot hébergeur, mysqldump…).',
							'100son-html-normalizer'
						) }
					</strong>
				</Notice>
			) }
			<div className="htmln-normalize__apply-bar">
				<div className="htmln-normalize__apply-bar-action">
					<ReadOnlyTooltip>
						<Button
							variant="primary"
							onClick={ onApplyStep }
							disabled={ ! canApply || isReadOnly }
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
					</ReadOnlyTooltip>
				</div>
				<div className="htmln-normalize__apply-bar-recap">
					<strong>
						{ sprintf(
							// translators: 1 = règles activées, 2 = total.
							__(
								'%1$d / %2$d règles activées',
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
						{ __(
							'modifier dans Règles',
							'100son-html-normalizer'
						) }
					</a>
					{ ')' }
				</div>
			</div>
		</>
	);
}
