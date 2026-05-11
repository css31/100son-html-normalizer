/**
 * Hook React — récupère et persiste les 7 seuils γ de régression (F15).
 *
 * Cycle de vie :
 *  1. Au mount : fetch `GET /settings/regression-thresholds` → renseigne
 *     `thresholds` (état courant) et `defaults` (constantes du cahier).
 *  2. Sur `save(payload)` : POST le payload → met à jour `thresholds`
 *     avec la version normalisée renvoyée par le serveur (pour refléter
 *     immédiatement les corrections silencieuses : valeurs négatives
 *     écrasées par les defaults, etc.).
 *
 * Pourquoi pas dans `@wordpress/data` store : les réglages ne sont lus
 * que par la vue Settings elle-même (les autres vues utilisent
 * `getRegressionThresholds()` côté serveur via le repo, pas le store).
 * Garder ça en état local évite une indirection inutile.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} SettingsResult
 * @property {?Object<string, number>}                                        thresholds  Seuils courants ou null pendant le fetch initial.
 * @property {?Object<string, number>}                                        defaults    Defaults exposés par le serveur (constantes cahier).
 * @property {boolean}                                                        isLoading   Vrai durant le fetch initial.
 * @property {boolean}                                                        isSaving    Vrai durant un POST.
 * @property {?string}                                                        error       Message d'erreur (fetch ou save) ou null.
 * @property {boolean}                                                        isDirty     Vrai si la dernière sauvegarde s'est bien terminée (signal succès court).
 * @property {(p: Object<string, number>) => Promise<Object<string, number>>} save        Persiste un payload, résout sur la version normalisée.
 * @property {() => void}                                                     clearStatus Reset isDirty + error (utile pour cacher la notice succès).
 */

/**
 * @return {SettingsResult} État + actions.
 */
export function useSettings() {
	const [ thresholds, setThresholds ] = useState( null );
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
			.getRegressionThresholds()
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}
				setThresholds( result.thresholds ?? null );
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
			const result =
				await api.settings.saveRegressionThresholds( payload );
			const next = result.thresholds ?? payload;
			setThresholds( next );
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
		thresholds,
		defaults,
		isLoading,
		isSaving,
		error,
		isDirty,
		save,
		clearStatus,
	};
}
