/**
 * Hook React — récupère le détail d'un pas (`GET /steps/<uuid>`) pour F16.
 *
 * Reponse serveur : `{step: StepRecord, progress: resume_progress}`.
 *
 * Le hook s'active uniquement quand `uuid` est non-null — utile pour le
 * drawer de détail qui n'est monté qu'à l'ouverture. Si `uuid` change
 * (changement de pas dans le drawer) ou redevient null, un nouveau fetch
 * est déclenché ou l'état est réinitialisé.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} StepDetailResult
 * @property {?Object}    step      StepRecord serialisé (cf. StepsController::step_to_array) ou null.
 * @property {?Object}    progress  Snapshot `resume_progress` (uuid, total_articles, processed, regression_pending, pending) ou null.
 * @property {boolean}    isLoading Vrai durant le fetch.
 * @property {?string}    error     Message d'erreur ou null.
 * @property {() => void} refetch   Relance manuelle (après confirm/refuse d'un article par ex.).
 */

/**
 * @param {?string} uuid UUID v4 du pas, ou null pour désactiver le hook.
 * @return {StepDetailResult} État + actions.
 */
export function useStepDetail( uuid ) {
	const [ step, setStep ] = useState( null );
	const [ progress, setProgress ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const fetchDetail = useCallback(
		async ( signal ) => {
			if ( ! uuid ) {
				setStep( null );
				setProgress( null );
				setError( null );
				setIsLoading( false );
				return;
			}
			setIsLoading( true );
			setError( null );
			try {
				const result = await api.steps.find( uuid );
				if ( signal && signal.cancelled ) {
					return;
				}
				setStep( result.step ?? null );
				setProgress( result.progress ?? null );
			} catch ( err ) {
				if ( signal && signal.cancelled ) {
					return;
				}
				const msg =
					err && err.message
						? String( err.message )
						: 'unknown_error';
				setError( msg );
				setStep( null );
				setProgress( null );
			} finally {
				if ( ! signal || ! signal.cancelled ) {
					setIsLoading( false );
				}
			}
		},
		[ uuid ]
	);

	useEffect( () => {
		const signal = { cancelled: false };
		fetchDetail( signal );
		return () => {
			signal.cancelled = true;
		};
	}, [ fetchDetail ] );

	const refetch = useCallback(
		() => fetchDetail( { cancelled: false } ),
		[ fetchDetail ]
	);

	return { step, progress, isLoading, error, refetch };
}
