/**
 * Hook React — orchestre le flux F14 d'application par pas côté SPA.
 *
 * Cf. cahier §4.4.2 et §3.1 F14. Délègue la logique métier à `StepRunner`
 * côté serveur via les routes REST `/steps/*` (Phase 5.2).
 *
 * Phase 6.5 : ajout de la pause/resume sur régression. La boucle des
 * chunks devient une machine à états :
 *
 *   [idle] → startStep() → [running]
 *   [running] → fin chunks → finalize → [idle]
 *   [running] → régression détectée → [paused-on-regression]
 *   [paused-on-regression] → confirmDecision('confirm') → confirmArticle
 *                                                       → reprise [running]
 *   [paused-on-regression] → confirmDecision('refuse')  → refuseArticle
 *                                                       → reprise [running]
 *   [running] → abandonStep() → finalize best-effort → [idle]
 *
 * Les chunks restants à traiter sont conservés dans une `useRef` qui
 * survit aux pauses sans déclencher de re-render (mutation d'array).
 * À reprise, on continue exactement où on s'est arrêté.
 */

import { useState, useCallback, useRef } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import * as api from '../api';
import { STORE_NAME } from '../store';

/**
 * Taille de chunk REST côté SPA — équilibre latence visible et
 * nombre d'aller-retours. 5 articles = barre de progression fluide.
 */
const CHUNK_SIZE = 5;

/**
 * Découpe une liste d'IDs en sous-listes.
 *
 * @param {number[]} ids Liste à chunker.
 * @return {number[][]} Liste de chunks.
 */
function chunkIds( ids ) {
	const out = [];
	for ( let i = 0; i < ids.length; i += CHUNK_SIZE ) {
		out.push( ids.slice( i, i + CHUNK_SIZE ) );
	}
	return out;
}

/**
 * Cherche le premier article en `regression_pending` parmi les résultats
 * d'un chunk.
 *
 * @param {Object<number, Object>} results Résultats indexés par post_id.
 * @return {?{post_id: number, regression: ?Object, metrics_before: ?Object, metrics_after: ?Object}}
 *         Premier pending ou null.
 */
function findRegressionPending( results ) {
	for ( const [ postIdRaw, result ] of Object.entries( results ?? {} ) ) {
		if ( result && 'regression_pending' === result.status ) {
			return {
				post_id: Number( postIdRaw ),
				regression: result.regression ?? null,
				metrics_before: result.metrics_before ?? null,
				metrics_after: result.metrics_after ?? null,
			};
		}
	}
	return null;
}

/**
 * @typedef {Object} UseStepRunnerResult
 * @property {boolean}                                                   isRunning         Vrai pendant un pas actif.
 * @property {?Object}                                                   progress          Progression {uuid, totalArticles, processedCount, resultsByPostId}.
 * @property {?Object}                                                   regressionPending Article en pause sur régression (RegressionModal).
 * @property {?string}                                                   error             Message d'erreur fatal au pas.
 * @property {(post_ids: number[], rule_ids: string[]) => Promise<void>} startStep         Lance un pas.
 * @property {(decision: 'confirm'|'refuse') => Promise<void>}           confirmDecision   Décision admin sur régression.
 * @property {() => Promise<void>}                                       abandonStep       Finalise le pas en l'état.
 */

/**
 * @param {() => void} [onFinalized] Callback après finalize réussi.
 * @return {UseStepRunnerResult} État + actions.
 */
export function useStepRunner( onFinalized ) {
	const [ isRunning, setIsRunning ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ regressionPending, setRegressionPending ] = useState( null );
	const [ error, setError ] = useState( null );

	const { setCurrentStep, clearCurrentStep } = useDispatch( STORE_NAME );

	// Refs persistantes — état machine pause/resume sans re-render.
	const uuidRef = useRef( null );
	const remainingChunksRef = useRef( [] );

	/**
	 * Reset complet de l'état local après une finalize (réussie ou
	 * abandonnée). Le store @wordpress/data est aussi nettoyé.
	 */
	const cleanup = useCallback( () => {
		clearCurrentStep();
		uuidRef.current = null;
		remainingChunksRef.current = [];
		setProgress( null );
		setRegressionPending( null );
		setIsRunning( false );
	}, [ clearCurrentStep ] );

	/**
	 * Boucle interne : déroule les chunks restants jusqu'à régression
	 * ou épuisement. Stocke à chaque chunk les résultats partiels dans
	 * le state `progress` pour rendu progressif.
	 *
	 * @return {Promise<void>}
	 */
	const processRemaining = useCallback( async () => {
		const uuid = uuidRef.current;
		if ( ! uuid ) {
			return;
		}

		while ( remainingChunksRef.current.length > 0 ) {
			const chunk = remainingChunksRef.current.shift();
			let chunkResult;
			try {
				chunkResult = await api.steps.processChunk( uuid, {
					chunk_post_ids: chunk,
				} );
			} catch ( err ) {
				const msg =
					err && err.message
						? String( err.message )
						: 'process_failed';
				setError( msg );
				// Tente une finalize best-effort.
				try {
					await api.steps.finalize( uuid );
				} catch ( _ignored ) {
					// Pas non-finalisé en BDD → StepResumeBanner le détectera.
				}
				cleanup();
				return;
			}

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

			// Pause sur régression — la modale RegressionModal prend le relais.
			const pending = findRegressionPending( newResults );
			if ( pending ) {
				setRegressionPending( pending );
				return; // remainingChunksRef reste avec les chunks futurs.
			}
		}

		// Plus de chunks → finalize automatique.
		try {
			await api.steps.finalize( uuid );
		} catch ( err ) {
			const msg =
				err && err.message ? String( err.message ) : 'finalize_failed';
			setError( msg );
		}
		cleanup();
		if ( 'function' === typeof onFinalized ) {
			onFinalized();
		}
	}, [ cleanup, onFinalized ] );

	/**
	 * Lance un nouveau pas et déroule les chunks jusqu'à régression
	 * ou finalize.
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

			try {
				const startResult = await api.steps.run( {
					post_ids: postIds,
					rule_ids: ruleIds,
				} );
				const uuid = startResult.uuid;
				uuidRef.current = uuid;
				remainingChunksRef.current = chunkIds( postIds );
				setCurrentStep( {
					uuid,
					progress: { totalArticles: postIds.length },
				} );
				setProgress( {
					uuid,
					totalArticles: postIds.length,
					processedCount: 0,
					resultsByPostId: {},
				} );
			} catch ( err ) {
				const msg =
					err && err.message ? String( err.message ) : 'start_failed';
				setError( msg );
				cleanup();
				return;
			}

			await processRemaining();
		},
		[ isRunning, setCurrentStep, cleanup, processRemaining ]
	);

	/**
	 * Décision admin sur l'article en `regression_pending`. Appelle
	 * `/confirm-article` côté serveur puis reprend la boucle des
	 * chunks restants.
	 *
	 * @param {'confirm'|'refuse'} decision Décision.
	 * @return {Promise<void>}
	 */
	const confirmDecision = useCallback(
		async ( decision ) => {
			const pending = regressionPending;
			const uuid = uuidRef.current;
			if ( ! pending || ! uuid ) {
				return;
			}
			if ( 'confirm' !== decision && 'refuse' !== decision ) {
				return;
			}

			let articleResult;
			try {
				articleResult = await api.steps.confirmArticle( uuid, {
					post_id: pending.post_id,
					decision,
				} );
			} catch ( err ) {
				const msg =
					err && err.message
						? String( err.message )
						: 'confirm_failed';
				setError( msg );
				return;
			}

			// Met à jour le résultat de l'article dans la progression.
			const finalArticleResult = articleResult.result ?? null;
			if ( finalArticleResult ) {
				setProgress( ( prev ) => {
					if ( ! prev ) {
						return prev;
					}
					return {
						...prev,
						resultsByPostId: {
							...prev.resultsByPostId,
							[ pending.post_id ]: finalArticleResult,
						},
					};
				} );
			}

			setRegressionPending( null );

			// Reprise de la boucle.
			await processRemaining();
		},
		[ regressionPending, processRemaining ]
	);

	/**
	 * Finalise immédiatement le pas en cours (équivalent abandon).
	 *
	 * @return {Promise<void>}
	 */
	const abandonStep = useCallback( async () => {
		const uuid = uuidRef.current;
		if ( ! uuid ) {
			return;
		}
		try {
			await api.steps.finalize( uuid );
		} catch ( err ) {
			const msg =
				err && err.message ? String( err.message ) : 'finalize_failed';
			setError( msg );
		}
		cleanup();
		if ( 'function' === typeof onFinalized ) {
			onFinalized();
		}
	}, [ cleanup, onFinalized ] );

	return {
		isRunning,
		progress,
		regressionPending,
		error,
		startStep,
		confirmDecision,
		abandonStep,
	};
}
