/**
 * Hook React — récupère et persiste la config des 11 règles
 * (onglet Règles SPA).
 *
 * Cycle de vie :
 *  1. Au mount : `GET /presets` → état `presets` rempli avec les 8
 *     entrées normalisées par le serveur.
 *  2. Sur `save(id, payload)` : POST `/presets/<id>` → met à jour
 *     l'entrée correspondante dans `presets` avec la version
 *     normalisée renvoyée par le serveur (utile si l'admin tape
 *     `99` sur le threshold de R5 alors que le max est 20 → le
 *     serveur retombe à 2 par défaut et la SPA reflète ça).
 *
 * État local plutôt que store global : la config des règles
 * n'intéresse que la vue Règles et le récap dans Normaliser. Le
 * récap utilise une vue dérivée (les `enabled`) qu'on calcule côté
 * Normalize via un simple `useMemo`. Pas besoin d'indirection store.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} PresetEntry
 * @property {string}  id          Identifiant `R1`..`R8`.
 * @property {string}  label       Libellé localisé.
 * @property {string}  description Description HTML (rendu via dangerouslySetInnerHTML — contenu serveur de confiance).
 * @property {boolean} has_options Vrai si la règle a des paramètres configurables.
 * @property {boolean} enabled     Activée par défaut.
 * @property {Object}  params      Paramètres courants (forme dépendante de l'id).
 * @property {Object}  defaults    Defaults canoniques pour bouton « Restaurer ».
 */

/**
 * @typedef {Object} PresetsResult
 * @property {?Array<PresetEntry>}                                   presets   Liste 8 entrées ou null pendant le fetch initial.
 * @property {boolean}                                               isLoading Vrai durant le fetch initial.
 * @property {boolean}                                               isSaving  Vrai pendant qu'un POST est en vol.
 * @property {?string}                                               error     Message d'erreur ou null.
 * @property {(id: string, payload: Object) => Promise<PresetEntry>} save      POST partiel sur une règle, retourne la version normalisée.
 * @property {() => void}                                            refetch   Relance manuelle de GET /presets.
 */

/**
 * @return {PresetsResult} État + actions.
 */
export function usePresets() {
	const [ presets, setPresets ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );

	const fetchPresets = useCallback( async ( signal ) => {
		setIsLoading( true );
		setError( null );
		try {
			const result = await api.presets.list();
			if ( signal && signal.cancelled ) {
				return;
			}
			setPresets( Array.isArray( result.presets ) ? result.presets : [] );
		} catch ( err ) {
			if ( signal && signal.cancelled ) {
				return;
			}
			setError(
				err && err.message ? String( err.message ) : 'unknown_error'
			);
			setPresets( [] );
		} finally {
			if ( ! signal || ! signal.cancelled ) {
				setIsLoading( false );
			}
		}
	}, [] );

	useEffect( () => {
		const signal = { cancelled: false };
		fetchPresets( signal );
		return () => {
			signal.cancelled = true;
		};
	}, [ fetchPresets ] );

	const save = useCallback( async ( id, payload ) => {
		setIsSaving( true );
		setError( null );
		try {
			const result = await api.presets.update( id, payload );
			const updated = result.preset;
			setPresets( ( prev ) => {
				if ( ! Array.isArray( prev ) ) {
					return prev;
				}
				return prev.map( ( entry ) =>
					entry.id === id ? updated : entry
				);
			} );
			return updated;
		} catch ( err ) {
			setError(
				err && err.message ? String( err.message ) : 'unknown_error'
			);
			throw err;
		} finally {
			setIsSaving( false );
		}
	}, [] );

	const refetch = useCallback(
		() => fetchPresets( { cancelled: false } ),
		[ fetchPresets ]
	);

	return { presets, isLoading, isSaving, error, save, refetch };
}
