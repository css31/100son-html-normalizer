/**
 * Store @wordpress/data — namespace `htmln/spa`.
 *
 * État global de la SPA V1.0. Phase 6.2 livre une base minimale qui sera
 * enrichie au fil des vues métier 6.3 à 6.7 :
 *
 *  - `diagnosticsStats`      — compteurs F13 onglets (alimente badges).
 *  - `currentStep`           — pas en cours (F14) : uuid + progression.
 *  - `regressionThresholds`  — seuils γ courants pour Settings (6.7).
 *
 * Pourquoi @wordpress/data plutôt qu'un Context React local :
 *  - persistance entre vues (le pas en cours doit être visible aussi
 *    depuis StepsHistory, le bandeau de reprise, etc.) ;
 *  - DevTools Redux natifs ;
 *  - pattern WP idiomatique pour la SPA admin ;
 *  - facilite l'intégration des resolvers (fetch automatique de
 *    diagnostics au mount d'une vue) qu'on ajoutera en 6.3+.
 *
 * Le store s'enregistre automatiquement à l'import — `index.js` racine
 * importe ce module avant de monter <App />, garantissant que les
 * sélecteurs sont disponibles dès le premier render.
 */

import { createReduxStore, register } from '@wordpress/data';

/**
 * Identifiant du store. Exporté pour pouvoir l'utiliser dans
 * `useSelect( ( select ) => select( STORE_NAME ).getX() )` côté composants.
 */
export const STORE_NAME = 'htmln/spa';

/**
 * État initial — toutes les valeurs `null` signalent « pas encore chargé »
 * et permettent aux vues d'afficher un skeleton/loader avant le premier fetch.
 *
 * Post-2026-05-16 : `selectedRules` (ancienne sélection « Dans le lot »
 * locale au navigateur) a été supprimée du store. Le toggle « Activée »
 * de chaque règle (persisté en BDD via l'option `son100_htmln_presets`)
 * est désormais le **seul** flag — il pilote à la fois l'évaluation par
 * le scan ET l'application au prochain lot. Les vues consomment
 * `usePresets().presets` filtrées sur `enabled = true` pour reconstituer
 * la liste des règles applicables.
 *
 * `normalizeView` est éphémère (perdu au reload) mais survit au
 * changement d'onglet primaire de l'App (`App.jsx` monte/démonte les vues —
 * sans store, on perdrait tab/page/filters/sélection à chaque aller-retour).
 *
 * @typedef {Object} NormalizeViewState
 * @property {'to_improve'|'normal'|'stale'}                                       tab                  Onglet interne F13 actif.
 * @property {number}                                                              page                 Page de pagination (≥ 1).
 * @property {number}                                                              perPage              Articles par page.
 * @property {Object<string, *>}                                                   filters              Filtres FiltersBar (search/cat/year/month/builder/rules).
 * @property {number[]}                                                            selectedPostIds      Post IDs cochés (F14.1). Array pour rester sérialisable Redux ; le composant le matérialise en Set pour `.has()`.
 *
 * @typedef {Object} HtmlnSpaState
 * @property {?{normal: number, to_improve: number, stale: number, total: number}} diagnosticsStats     Compteurs F13 onglets (null = pas encore chargé).
 * @property {?{uuid: string, progress?: Object}}                                  currentStep          Pas F14 en cours (null = aucun pas actif).
 * @property {?Object<string, number>}                                             regressionThresholds Seuils γ courants (Settings 6.7).
 * @property {NormalizeViewState}                                                  normalizeView        État de l'onglet Normaliser persistant au switch d'onglets primaires.
 */

/**
 * Liste canonique des 10 ids de règles, dans l'ordre du pipeline.
 *
 * Note : l'ORDRE ici est l'ordre d'EXÉCUTION (R3 → R4 → … → R2), pas
 * l'ordre d'affichage UI. Pour l'affichage trié (Règles tab, recap),
 * utiliser `compareRuleIdsByDisplayOrder` de `utils/ruleLabels`.
 *
 * @type {string[]}
 */
export const ALL_RULE_IDS = [
	'R1',
	'R2',
	'R3',
	'R4',
	'R5',
	'R6',
	'R7',
	'R8',
	'R9',
	'R10',
	'R11',
	'R12',
	'R13',
	'R14',
	'R15',
	'R16',
	'R17',
];

/**
 * Defaults `normalizeView` — alignés sur l'ancien état local de `Normalize.jsx`
 * (DEFAULT_TAB = `to_improve`, DEFAULT_PER_PAGE = 50, page = 1, filtres vides,
 * aucune sélection). Toute évolution de ces defaults doit rester en miroir
 * avec les constantes du composant pour éviter une dérive silencieuse.
 *
 * @type {NormalizeViewState}
 */
const DEFAULT_NORMALIZE_VIEW = {
	tab: 'to_improve',
	page: 1,
	perPage: 50,
	filters: {},
	selectedPostIds: [],
};

const DEFAULT_STATE = {
	diagnosticsStats: null,
	currentStep: null,
	regressionThresholds: null,
	normalizeView: { ...DEFAULT_NORMALIZE_VIEW },
	// Mode de session courant — alimenté par <SessionGate>. `null` tant que
	// le premier acquire n'a pas répondu, `'primary'` quand on détient le
	// verrou. Le mode `'secondary'` (lecture seule) est réservé à une
	// future évolution si on autorise un second onglet en consultation.
	sessionMode: null,
};

const actions = {
	/**
	 * Enregistre le pas en cours (F14). Appelé après `runStep` réussi
	 * et nettoyé après `finalizeStep`.
	 *
	 * @param {{uuid: string, progress?: Object}} step
	 */
	setCurrentStep( step ) {
		return { type: 'SET_CURRENT_STEP', step };
	},

	clearCurrentStep() {
		return { type: 'CLEAR_CURRENT_STEP' };
	},

	/**
	 * Met à jour les compteurs onglets F13 (alimente les badges du Tabs).
	 *
	 * @param {{normal: number, to_improve: number, stale: number, total: number}} stats
	 */
	setDiagnosticsStats( stats ) {
		return { type: 'SET_DIAGNOSTICS_STATS', stats };
	},

	/**
	 * Met à jour les seuils γ courants (Settings 6.7).
	 *
	 * @param {Object<string, number>} thresholds
	 */
	setRegressionThresholds( thresholds ) {
		return { type: 'SET_REGRESSION_THRESHOLDS', thresholds };
	},

	/**
	 * Met à jour partiellement l'état de la vue Normaliser. Accepte un
	 * sous-ensemble de clés (tab, page, perPage, filters, selectedPostIds) ;
	 * les clés absentes sont préservées. Permet à `Normalize.jsx` de
	 * synchroniser plusieurs champs en un seul dispatch (ex. changement
	 * d'onglet interne + reset de page à 1) — un seul re-render au lieu
	 * de deux.
	 *
	 * @param {Partial<NormalizeViewState>} patch Patch partiel.
	 */
	setNormalizeView( patch ) {
		return { type: 'SET_NORMALIZE_VIEW', patch };
	},

	/**
	 * Bascule la sélection d'un post (F14.1) — additionne s'il est absent,
	 * retire s'il est présent.
	 *
	 * @param {number}  postId  Identifiant de l'article.
	 * @param {boolean} checked État cible (true = coché).
	 */
	toggleNormalizeSelectedPost( postId, checked ) {
		return {
			type: 'TOGGLE_NORMALIZE_SELECTED_POST',
			postId: Number( postId ),
			checked: Boolean( checked ),
		};
	},

	/**
	 * Coche/décoche en bloc tous les articles de la page courante (F14.1).
	 *
	 * @param {number[]} postIds Identifiants des articles à toggler.
	 * @param {boolean}  checked État cible.
	 */
	toggleNormalizeSelectedPostsOnPage( postIds, checked ) {
		return {
			type: 'TOGGLE_NORMALIZE_SELECTED_POSTS_ON_PAGE',
			postIds: Array.isArray( postIds )
				? postIds.map( ( id ) => Number( id ) )
				: [],
			checked: Boolean( checked ),
		};
	},

	/**
	 * Vide la sélection d'articles. Appelé après finalisation d'un pas —
	 * les articles confirmés ne doivent pas rester cochés.
	 */
	clearNormalizeSelectedPosts() {
		return { type: 'CLEAR_NORMALIZE_SELECTED_POSTS' };
	},

	/**
	 * Met à jour le mode de session courant. Dispatch par `<SessionGate>`
	 * au gré des transitions du verrou.
	 *
	 * @param {?('primary'|'secondary')} mode Mode courant (null = pas encore initialisé).
	 */
	setSessionMode( mode ) {
		return { type: 'SET_SESSION_MODE', mode };
	},
};

/**
 * Réducteur du store htmln/spa.
 *
 * @param {HtmlnSpaState}                          state  État courant.
 * @param {{type: string, [key: string]: unknown}} action Action dispatched.
 * @return {HtmlnSpaState} Nouvel état immuable.
 */
function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'SET_CURRENT_STEP':
			return { ...state, currentStep: action.step };
		case 'CLEAR_CURRENT_STEP':
			return { ...state, currentStep: null };
		case 'SET_DIAGNOSTICS_STATS':
			return { ...state, diagnosticsStats: action.stats };
		case 'SET_REGRESSION_THRESHOLDS':
			return { ...state, regressionThresholds: action.thresholds };
		case 'SET_NORMALIZE_VIEW': {
			const patch =
				action.patch && 'object' === typeof action.patch
					? action.patch
					: {};
			return {
				...state,
				normalizeView: { ...state.normalizeView, ...patch },
			};
		}
		case 'TOGGLE_NORMALIZE_SELECTED_POST': {
			const id = Number( action.postId );
			if ( ! Number.isFinite( id ) ) {
				return state;
			}
			const current = state.normalizeView.selectedPostIds;
			const present = current.includes( id );
			let next;
			if ( action.checked ) {
				next = present ? current : [ ...current, id ];
			} else {
				next = present ? current.filter( ( x ) => x !== id ) : current;
			}
			if ( next === current ) {
				return state;
			}
			return {
				...state,
				normalizeView: {
					...state.normalizeView,
					selectedPostIds: next,
				},
			};
		}
		case 'TOGGLE_NORMALIZE_SELECTED_POSTS_ON_PAGE': {
			const ids = Array.isArray( action.postIds )
				? action.postIds
						.map( ( x ) => Number( x ) )
						.filter( ( x ) => Number.isFinite( x ) )
				: [];
			if ( 0 === ids.length ) {
				return state;
			}
			const current = state.normalizeView.selectedPostIds;
			let next;
			if ( action.checked ) {
				// Union sans doublon, ordre stable (existants d'abord).
				const set = new Set( current );
				ids.forEach( ( id ) => set.add( id ) );
				next = Array.from( set );
			} else {
				const drop = new Set( ids );
				next = current.filter( ( id ) => ! drop.has( id ) );
			}
			return {
				...state,
				normalizeView: {
					...state.normalizeView,
					selectedPostIds: next,
				},
			};
		}
		case 'CLEAR_NORMALIZE_SELECTED_POSTS':
			if ( 0 === state.normalizeView.selectedPostIds.length ) {
				return state;
			}
			return {
				...state,
				normalizeView: { ...state.normalizeView, selectedPostIds: [] },
			};
		case 'SET_SESSION_MODE': {
			const mode =
				'primary' === action.mode || 'secondary' === action.mode
					? action.mode
					: null;
			if ( mode === state.sessionMode ) {
				return state;
			}
			return { ...state, sessionMode: mode };
		}
		default:
			return state;
	}
}

const selectors = {
	/**
	 * Pas en cours (F14) — null si aucun pas actif.
	 *
	 * @param {HtmlnSpaState} state État du store.
	 * @return {?Object} Objet pas en cours ou null.
	 */
	getCurrentStep: ( state ) => state.currentStep,

	/**
	 * Vrai ssi un pas est actif. Utilisé par StepResumeBanner.
	 *
	 * @param {HtmlnSpaState} state État du store.
	 * @return {boolean} Présence d'un pas en cours.
	 */
	hasCurrentStep: ( state ) => null !== state.currentStep,

	/**
	 * Compteurs F13 onglets — null si stats pas encore chargées.
	 *
	 * @param {HtmlnSpaState} state État du store.
	 * @return {?Object} Compteurs ou null.
	 */
	getDiagnosticsStats: ( state ) => state.diagnosticsStats,

	/**
	 * Seuils γ courants (Settings 6.7) — null si pas encore chargés.
	 *
	 * @param {HtmlnSpaState} state État du store.
	 * @return {?Object} Seuils ou null.
	 */
	getRegressionThresholds: ( state ) => state.regressionThresholds,

	/**
	 * État de la vue Normaliser (tab/page/perPage/filters/selectedPostIds).
	 * Préservé au switch d'onglets primaires de l'App ; perdu au reload.
	 *
	 * @param {HtmlnSpaState} state État du store.
	 * @return {NormalizeViewState} État courant.
	 */
	getNormalizeView: ( state ) => state.normalizeView,

	/**
	 * Mode de session courant : `'primary'` quand l'onglet détient le verrou,
	 * `'secondary'` en lecture seule, `null` avant le premier acquire.
	 *
	 * @param {HtmlnSpaState} state État du store.
	 * @return {?('primary'|'secondary')} Mode courant ou null.
	 */
	getSessionMode: ( state ) => state.sessionMode,
};

/**
 * Configuration finale du store. Exposée pour les tests éventuels et
 * pour permettre à un caller avancé de re-register dans un context isolé
 * (cas marginal V1.0).
 */
export const storeConfig = {
	reducer,
	actions,
	selectors,
};

const store = createReduxStore( STORE_NAME, storeConfig );

register( store );

export { store };
