/**
 * Hook React — orchestre le flux F14 d'application par pas côté SPA.
 *
 * Cf. cahier §4.4.2 et §3.1 F14. Délègue la logique métier à `StepRunner`
 * côté serveur via les routes REST `/steps/*` (Phase 5.2). Côté SPA, ce
 * hook gère :
 *  - l'enchaînement run → boucle process → finalize ;
 *  - la pause sur la première régression (modale RegressionModal arrive
 *    en Phase 6.5 ; en attendant, on s'arrête et un bandeau invite
 *    l'utilisateur à abandonner le pas) ;
 *  - la persistance de l'UUID du pas en cours dans le store @wordpress/data
 *    (visible par StepResumeBanner, future StepsHistory, etc.) ;
 *  - l'agrégation locale des résultats par article pour affichage
 *    progressif dans la SPA.
 *
 * Note V1.0 (6.4) : la modale de décision sur régression n'existe pas
 * encore. Sur le premier `regression_pending`, on stoppe l'auto-pilotage,
 * on garde l'UUID dans le store, et `regressionPending` expose l'article
 * fautif. La vue affiche un bandeau qui propose `abandon()` — appelle
 * `finalize` côté serveur (le comptage erreurs/pending serveur est
 * idempotent côté `StepRunner::finalize_step`).
 */

import { useState, useCallback, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import * as api from '../api';
import { STORE_NAME } from '../store';

/**
 * Taille de chunk REST côté SPA — équilibre latence visible (chunks
 * petits = retours rapides) et nombre d'aller-retours (chunks grands =
 * moins de requêtes). 5 articles par chunk = visualisation fluide sans
 * trop saturer le serveur.
 */
const CHUNK_SIZE = 5;

/**
 * @typedef {Object} StepProgress
 * @property {string}                 uuid            UUID v4 du pas serveur.
 * @property {number}                 totalArticles   Articles ciblés au démarrage.
 * @property {number}                 processedCount  Articles déjà traités.
 * @property {Object<number, Object>} resultsByPostId Résultats agrégés par post_id.
 */

/**
 * @typedef {Object} UseStepRunnerResult
 * @property {boolean}                                                   isRunning         Vrai pendant un pas actif.
 * @property {?StepProgress}                                             progress          Progression du pas en cours, null sinon.
 * @property {?{post_id: number, regression: Object}}                    regressionPending Article en pause (V1.0 : un seul à la fois).
 * @property {?string}                                                   error             Message d'erreur fatal au pas (différent de l'erreur par article).
 * @property {(post_ids: number[], rule_ids: string[]) => Promise<void>} startStep         Lance un pas.
 * @property {() => Promise<void>}                                       abandonStep       Finalise le pas en l'état (= abandon).
 */

/**
 * Hook principal. Conserve l'état du pas en cours et expose les callbacks.
 *
 * Le store @wordpress/data ne contient que l'UUID + une vue minimale —
 * les détails du pas (résultats par article) restent locaux au hook
 * pour limiter la pression sur Redux. La SPA Phase 6.6 (StepsHistory)
 * lira indépendamment les pas finalisés via `/steps`.
 *
 * @param {() => void} [onFinalized] Callback appelé après finalize réussie
 *                                   (la vue parente peut refresher les
 *                                   compteurs `useDiagnosticsStats`).
 * @return {UseStepRunnerResult} État + actions.
 */
export function useStepRunner( onFinalized ) {
	const [ isRunning, setIsRunning ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ regressionPending, setRegressionPending ] = useState( null );
	const [ error, setError ] = useState( null );

	const { setCurrentStep, clearCurrentStep } = useDispatch( STORE_NAME );

	// Référence sur le pas courant — évite les re-renders intermédiaires
	// pendant l'orchestration de la boucle de chunks.
	const stepRef = useRef( null );

	/**
	 * Découpe une liste d'IDs en sous-listes de taille CHUNK_SIZE.
	 *
	 * @param {number[]} ids Liste à chunker.
	 * @return {number[][]} Liste de chunks.
	 */
	const chunkIds = useCallback( ( ids ) => {
		const chunks = [];
		for ( let i = 0; i < ids.length; i += CHUNK_SIZE ) {
			chunks.push( ids.slice( i, i + CHUNK_SIZE ) );
		}
		return chunks;
	}, [] );

	/**
	 * Lance un nouveau pas et déroule la boucle de chunks jusqu'à finalize
	 * ou jusqu'à la première régression rencontrée.
	 *
	 * @param {number[]} postIds Articles cibles.
	 * @param {string[]} ruleIds Règles cochées.
	 * @return {Promise<void>}
	 */
	const startStep = useCallback(
		async ( postIds, ruleIds ) => {
			if ( isRunning ) {
				return;
			}
			setError( null );
			setRegressionPending( null );
			setIsRunning( true );

			let uuid = null;
			try {
				const startResult = await api.steps.run( {
					post_ids: postIds,
					rule_ids: ruleIds,
				} );
				uuid = startResult.uuid;
				stepRef.current = uuid;
				setCurrentStep( {
					uuid,
					progress: { totalArticles: postIds.length },
				} );

				const initialProgress = {
					uuid,
					totalArticles: postIds.length,
					processedCount: 0,
					resultsByPostId: {},
				};
				setProgress( initialProgress );

				const chunks = chunkIds( postIds );
				for ( const chunk of chunks ) {
					const chunkResult = await api.steps.processChunk( uuid, {
						chunk_post_ids: chunk,
					} );
					const newResults = chunkResult.results ?? {};

					setProgress( ( prev ) => {
						const merged = {
							...( prev?.resultsByPostId ?? {} ),
							...newResults,
						};
						return {
							...prev,
							processedCount: Object.keys( merged ).length,
							resultsByPostId: merged,
						};
					} );

					// Détection régression — V1.0 : on s'arrête sur la première,
					// la modale 6.5 prendra le relais. En attendant, l'admin peut
					// abandonner le pas via le bouton du banner d'erreur.
					const pendingEntry = Object.entries( newResults ).find(
						( [ , result ] ) =>
							result && 'regression_pending' === result.status
					);
					if ( pendingEntry ) {
						const [ postIdRaw, result ] = pendingEntry;
						setRegressionPending( {
							post_id: Number( postIdRaw ),
							regression: result.regression ?? null,
						} );
						return; // Boucle interrompue, finalize attendra.
					}
				}

				// Pas de régression → finalize automatique.
				await api.steps.finalize( uuid );
				clearCurrentStep();
				stepRef.current = null;
				setProgress( null );
				if ( 'function' === typeof onFinalized ) {
					onFinalized();
				}
			} catch ( err ) {
				const msg =
					err && err.message
						? String( err.message )
						: 'unknown_error';
				setError( msg );
				// Tente une finalize best-effort si on avait un UUID.
				if ( uuid ) {
					try {
						await api.steps.finalize( uuid );
					} catch ( _ignored ) {
						// Erreur silencieuse — le pas restera non-finalisé en BDD,
						// `StepResumeBanner` le détectera au prochain mount.
					}
					clearCurrentStep();
					stepRef.current = null;
					setProgress( null );
				}
			} finally {
				setIsRunning( false );
			}
		},
		[ isRunning, chunkIds, setCurrentStep, clearCurrentStep, onFinalized ]
	);

	/**
	 * Finalise immédiatement le pas en cours (équivalent abandon en V1.0).
	 *
	 * @return {Promise<void>}
	 */
	const abandonStep = useCallback( async () => {
		const uuid = stepRef.current;
		if ( ! uuid ) {
			return;
		}
		try {
			await api.steps.finalize( uuid );
		} catch ( err ) {
			// On laisse l'erreur visible mais on nettoie quand même l'état
			// local — le bandeau de reprise permettra de retenter plus tard.
			const msg =
				err && err.message ? String( err.message ) : 'finalize_failed';
			setError( msg );
		} finally {
			clearCurrentStep();
			stepRef.current = null;
			setProgress( null );
			setRegressionPending( null );
			setIsRunning( false );
			if ( 'function' === typeof onFinalized ) {
				onFinalized();
			}
		}
	}, [ clearCurrentStep, onFinalized ] );

	return {
		isRunning,
		progress,
		regressionPending,
		error,
		startStep,
		abandonStep,
	};
}
