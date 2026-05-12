/**
 * Hook React — récupère et persiste la note libre riche (onglet Notes SPA).
 *
 * Cycle de vie :
 *  1. Au mount : `GET /notes` → renseigne `content` (block grammar brute,
 *     chaîne vide si jamais saisi).
 *  2. Sur `save(rawGrammar)` : `PUT /notes` → met à jour `content` avec la
 *     version renvoyée par le serveur (autorité de sanitization — si
 *     `wp_kses_post` a modifié quelque chose, on le reflète immédiatement
 *     pour éviter la dérive avec l'éditeur).
 *  3. Sur `clear()` : `DELETE /notes` → contenu remis à chaîne vide.
 *
 * Pourquoi pas dans le store `@wordpress/data` : seul l'onglet Notes consomme
 * cette donnée. Garder en local évite une indirection inutile (même pattern
 * que `useSettings`).
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} NotesResult
 * @property {?string}                        content     Contenu courant (block grammar) ou null pendant le fetch initial.
 * @property {boolean}                        isLoading   Vrai durant le fetch initial.
 * @property {boolean}                        isSaving    Vrai durant un PUT / DELETE.
 * @property {?string}                        error       Message d'erreur ou null.
 * @property {boolean}                        justSaved   Pulse court (true après save réussi, reset par clearStatus).
 * @property {(g: string) => Promise<string>} save        Persiste un payload, résout sur le contenu sanitizé.
 * @property {() => Promise<void>}            clear       Vide la note côté serveur.
 * @property {() => void}                     clearStatus Reset justSaved + error.
 */

/**
 * @return {NotesResult} État + actions.
 */
export function useNotes() {
	const [ content, setContent ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ justSaved, setJustSaved ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setIsLoading( true );
		setError( null );
		api.notes
			.getNotes()
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}
				setContent(
					typeof result.content === 'string' ? result.content : ''
				);
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError(
					err && err.message ? String( err.message ) : 'unknown_error'
				);
				// Fallback contenu vide : permet à l'éditeur de monter quand
				// même (on ne veut pas une SPA bloquée sur erreur réseau —
				// l'utilisateur peut taper, le save retentera la sauvegarde).
				setContent( '' );
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

	const save = useCallback( async ( grammar ) => {
		setIsSaving( true );
		setError( null );
		setJustSaved( false );
		try {
			const result = await api.notes.saveNotes( grammar );
			const next =
				typeof result.content === 'string' ? result.content : '';
			setContent( next );
			setJustSaved( true );
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

	const clear = useCallback( async () => {
		setIsSaving( true );
		setError( null );
		setJustSaved( false );
		try {
			await api.notes.clearNotes();
			setContent( '' );
			setJustSaved( true );
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
		setJustSaved( false );
	}, [] );

	return {
		content,
		isLoading,
		isSaving,
		error,
		justSaved,
		save,
		clear,
		clearStatus,
	};
}
