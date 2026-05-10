/**
 * Hook React — récupère les compteurs F13 (`/diagnostics/stats`) au mount,
 * les pousse dans le store @wordpress/data et expose un état local
 * `{stats, isLoading, error, refetch}` à la vue.
 *
 * Pourquoi un hook plutôt qu'un fetch direct dans le composant :
 *  - centralise le pattern (loading + erreur) pour réutilisation par
 *    Settings 6.7 ou tout autre consommateur des stats ;
 *  - garantit un seul point de dispatch vers le store ;
 *  - simplifie le `refetch` après un pas qui modifie les compteurs (F14).
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import * as api from '../api';
import { STORE_NAME } from '../store';

/**
 * @typedef {Object} DiagnosticsStats
 * @property {number} normal     Articles status normal hors stale.
 * @property {number} to_improve Articles status to_improve hors stale.
 * @property {number} stale      Articles is_stale=1 (peu importe status).
 * @property {number} total      Total brut de lignes en table diagnostics.
 */

/**
 * @typedef {Object} UseDiagnosticsStatsResult
 * @property {?DiagnosticsStats} stats     Compteurs ou null si pas encore chargé.
 * @property {boolean}           isLoading Vrai durant le premier fetch et chaque refetch.
 * @property {?string}           error     Message d'erreur ou null.
 * @property {() => void}        refetch   Relance manuelle du fetch (post-pas par exemple).
 */

/**
 * Hook principal — fetch au mount + expose un refetch.
 *
 * @return {UseDiagnosticsStatsResult} État + actions.
 */
export function useDiagnosticsStats() {
	const stats = useSelect(
		( select ) => select( STORE_NAME ).getDiagnosticsStats(),
		[]
	);
	const { setDiagnosticsStats } = useDispatch( STORE_NAME );

	const [ isLoading, setIsLoading ] = useState( null === stats );
	const [ error, setError ] = useState( null );

	const fetchStats = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		try {
			const result = await api.diagnostics.stats();
			setDiagnosticsStats( result );
		} catch ( err ) {
			const msg =
				err && err.message ? String( err.message ) : 'unknown_error';
			setError( msg );
		} finally {
			setIsLoading( false );
		}
	}, [ setDiagnosticsStats ] );

	useEffect( () => {
		// Fetch au mount uniquement si rien dans le store. Évite un re-fetch
		// systématique au montage de chaque vue qui consommerait le hook.
		if ( null === stats ) {
			fetchStats();
		} else {
			setIsLoading( false );
		}
		// stats omis intentionnellement : on ne veut pas re-fetcher quand
		// le store change (ce serait un cycle infini après un set).
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ fetchStats ] );

	return { stats, isLoading, error, refetch: fetchStats };
}
