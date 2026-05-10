/**
 * Hook React — récupère la liste paginée des diagnostics (`GET /diagnostics`)
 * filtrée par status (onglet F13 actif) et page courante.
 *
 * État local : pas de store global (la liste est éphémère et propre à la
 * vue Normalize). Re-fetch automatique sur changement de `status` ou `page`.
 *
 * Le hook annule les fetch en cours via un drapeau `cancelled` capturé en
 * cleanup d'effet, pour éviter les writes incohérents si l'utilisateur
 * change rapidement d'onglet ou de page.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} DiagnosticsListResult
 * @property {Array}      items      Articles diagnostiqués pour la page courante.
 * @property {number}     total      Total non paginé (utile pour total_pages).
 * @property {number}     totalPages Nombre de pages.
 * @property {boolean}    isLoading  Vrai durant chaque fetch.
 * @property {?string}    error      Message d'erreur ou null.
 * @property {() => void} refetch    Relance manuelle (utile après une décision F14).
 */

/**
 * @param {?'normal'|'to_improve'|'stale'} status  Filtre status (null = tous).
 * @param {number}                         page    Page (≥ 1).
 * @param {number}                         perPage Articles par page (1..200).
 * @return {DiagnosticsListResult} État + actions.
 */
export function useDiagnosticsList( status, page = 1, perPage = 50 ) {
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
				const params = { page, per_page: perPage };
				if ( null !== status ) {
					params.status = status;
				}
				const result = await api.diagnostics.list( params );
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
		[ status, page, perPage ]
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
