/**
 * Hook React — récupère et persiste les 2 URLs des sites externes (Old / Prod).
 *
 * Découplé de `useSettings` (qui gère les seuils γ) volontairement : la vue
 * Settings monte les deux hooks indépendamment et le hook est aussi consommé
 * par la vue Normalize (sans appeler `save`), évitant ainsi de tirer toute la
 * machinerie des seuils dans un endroit qui n'en a pas besoin.
 *
 * Cycle de vie :
 *  1. Au mount : `GET /settings/external-sites` → renseigne `sites` (courant)
 *     et `defaults` (constantes serveur).
 *  2. Sur `save(payload)` : POST → met à jour `sites` avec la version
 *     normalisée renvoyée par le serveur (URL invalide → default, slash final
 *     stripped, etc.).
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} ExternalSitesResult
 * @property {?{old_url: string, prod_url: string}}                                                     sites       URLs courantes ou null pendant le fetch initial.
 * @property {?{old_url: string, prod_url: string}}                                                     defaults    Defaults exposés par le serveur.
 * @property {boolean}                                                                                  isLoading   Vrai durant le fetch initial.
 * @property {boolean}                                                                                  isSaving    Vrai durant un POST.
 * @property {?string}                                                                                  error       Message d'erreur (fetch ou save) ou null.
 * @property {boolean}                                                                                  isDirty     Vrai si la dernière sauvegarde s'est bien terminée.
 * @property {(p: {old_url: string, prod_url: string}) => Promise<{old_url: string, prod_url: string}>} save        Persiste un payload, résout sur la version normalisée.
 * @property {() => void}                                                                               clearStatus Reset isDirty + error.
 */

/**
 * @return {ExternalSitesResult} État + actions.
 */
export function useExternalSites() {
	const [ sites, setSites ] = useState( null );
	const [ defaults, setDefaults ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ isDirty, setIsDirty ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setIsLoading( true );
		setError( null );
		api.settings
			.getExternalSites()
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}
				setSites( result.sites ?? null );
				setDefaults( result.defaults ?? null );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError(
					err && err.message ? String( err.message ) : 'unknown_error'
				);
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	const save = useCallback( async ( payload ) => {
		setIsSaving( true );
		setError( null );
		setIsDirty( false );
		try {
			const result = await api.settings.saveExternalSites( payload );
			const next = result.sites ?? payload;
			setSites( next );
			setIsDirty( true );
			return next;
		} catch ( err ) {
			const msg =
				err && err.message ? String( err.message ) : 'unknown_error';
			setError( msg );
			throw err;
		} finally {
			setIsSaving( false );
		}
	}, [] );

	const clearStatus = useCallback( () => {
		setError( null );
		setIsDirty( false );
	}, [] );

	return {
		sites,
		defaults,
		isLoading,
		isSaving,
		error,
		isDirty,
		save,
		clearStatus,
	};
}
