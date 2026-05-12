/**
 * Hook React — récupère les facets de filtres (years, categories, builders)
 * pour les dropdowns du `<FiltersBar>` dans l'onglet Normaliser.
 *
 * Fetch unique au mount — les facets bougent avec le contenu de la table
 * diagnostics (years apparaissent au scan d'articles plus anciens,
 * categories suivent les taxonomies WP). Pour V1.0, on accepte que les
 * facets soient légèrement stales entre deux refetch manuels : le hook
 * expose `refetch` qu'on relance après un scan.
 *
 * État local : pas de store global, pattern aligné sur useDiagnosticsList.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} Facets
 * @property {number[]}                                         years      Années disponibles (DESC).
 * @property {Array<{id: number, name: string, count: number}>} categories Catégories WP avec count.
 * @property {Object<string, number>}                           builders   Map type → count.
 */

/**
 * @typedef {Object} DiagnosticsFacetsResult
 * @property {Facets}     facets    Données (vide initialement).
 * @property {boolean}    isLoading Vrai durant le fetch.
 * @property {?string}    error     Message d'erreur ou null.
 * @property {() => void} refetch   Force un nouveau fetch.
 */

const EMPTY = Object.freeze( {
	years: [],
	categories: [],
	builders: {},
} );

/**
 * @return {DiagnosticsFacetsResult} État + actions.
 */
export function useDiagnosticsFacets() {
	const [ facets, setFacets ] = useState( EMPTY );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	const fetchFacets = useCallback( async ( signal ) => {
		setIsLoading( true );
		setError( null );
		try {
			const result = await api.diagnostics.facets();
			if ( signal && signal.cancelled ) {
				return;
			}
			setFacets( {
				years: Array.isArray( result.years ) ? result.years : [],
				categories: Array.isArray( result.categories )
					? result.categories
					: [],
				builders:
					result.builders && typeof result.builders === 'object'
						? result.builders
						: {},
			} );
		} catch ( err ) {
			if ( signal && signal.cancelled ) {
				return;
			}
			setError(
				err && err.message ? String( err.message ) : 'unknown_error'
			);
		} finally {
			if ( ! signal || ! signal.cancelled ) {
				setIsLoading( false );
			}
		}
	}, [] );

	useEffect( () => {
		const signal = { cancelled: false };
		fetchFacets( signal );
		return () => {
			signal.cancelled = true;
		};
	}, [ fetchFacets ] );

	const refetch = useCallback(
		() => fetchFacets( { cancelled: false } ),
		[ fetchFacets ]
	);

	return { facets, isLoading, error, refetch };
}
