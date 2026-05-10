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
 * @typedef {Object} HtmlnSpaState
 * @property {?{normal: number, to_improve: number, stale: number, total: number}} diagnosticsStats     Compteurs F13 onglets (null = pas encore chargé).
 * @property {?{uuid: string, progress?: Object}}                                  currentStep          Pas F14 en cours (null = aucun pas actif).
 * @property {?Object<string, number>}                                             regressionThresholds Seuils γ courants (Settings 6.7).
 */
const DEFAULT_STATE = {
	diagnosticsStats: null,
	currentStep: null,
	regressionThresholds: null,
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
