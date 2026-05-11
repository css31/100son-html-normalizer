/**
 * Hook React — récupère l'historique paginé des pas (`GET /steps`) pour la
 * vue History F16.
 *
 * État local (pas de store global — l'historique est éphémère et propre à
 * la vue). Re-fetch automatique sur changement de `page` ou `perPage`.
 *
 * Le hook annule les fetch en cours via un drapeau `cancelled` capturé en
 * cleanup d'effet, pour éviter les writes incohérents si l'utilisateur
 * change rapidement de page ou démonte la vue.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} StepsListResult
 * @property {Array}      items      Pas pour la page courante (StepRecord serialisés).
 * @property {number}     total      Total non paginé.
 * @property {number}     totalPages Nombre de pages.
 * @property {boolean}    isLoading  Vrai durant chaque fetch.
 * @property {?string}    error      Message d'erreur ou null.
 * @property {() => void} refetch    Relance manuelle (utile après un pas qui vient d'être finalisé).
 */

/**
 * @param {number} page    Page (≥ 1).
 * @param {number} perPage Pas par page (1..200).
 * @return {StepsListResult} État + actions.
 */
export function useStepsList( page = 1, perPage = 50 ) {
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const fetchPage = useCallback(
		async ( signal ) => {
			setIsLoading( true );
			setError( null );
			try {
				const result = await api.steps.list( {
					page,
					per_page: perPage,
				} );
				if ( signal && signal.cancelled ) {
					return;
				}
				setItems( Array.isArray( result.items ) ? result.items : [] );
				setTotal( Number( result.total ) || 0 );
				setTotalPages( Number( result.total_pages ) || 0 );
			} catch ( err ) {
				if ( signal && signal.cancelled ) {
					return;
				}
				const msg =
					err && err.message
						? String( err.message )
						: 'unknown_error';
				setError( msg );
				setItems( [] );
				setTotal( 0 );
				setTotalPages( 0 );
			} finally {
				if ( ! signal || ! signal.cancelled ) {
					setIsLoading( false );
				}
			}
		},
		[ page, perPage ]
	);

	useEffect( () => {
		const signal = { cancelled: false };
		fetchPage( signal );
		return () => {
			signal.cancelled = true;
		};
	}, [ fetchPage ] );

	const refetch = useCallback(
		() => fetchPage( { cancelled: false } ),
		[ fetchPage ]
	);

	return { items, total, totalPages, isLoading, error, refetch };
}
