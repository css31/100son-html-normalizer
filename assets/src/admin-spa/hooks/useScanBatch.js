/**
 * Hook React — pilote un scan diagnostic complet depuis la SPA.
 *
 * Workflow équivalent du `wp htmln scan` CLI mais déclenché depuis le
 * navigateur :
 *
 *   1. POST /diagnostics/run                  → batch_id + total + post_ids + chunk_size
 *   2. Pour chaque chunk de `post_ids`, POST /diagnostics/run/chunk
 *      jusqu'à épuisement de la liste.
 *   3. À la fin, on appelle `onComplete()` (fourni par la vue) pour
 *      relancer le refetch des compteurs et de la liste.
 *
 * Le serveur retourne `processed` = count du chunk courant (pas cumul).
 * Le cumul est maintenu côté client dans `progress.processed`.
 *
 * Si l'utilisateur ferme l'onglet pendant le scan, les chunks déjà
 * envoyés sont persistés en base — les suivants ne partiront pas. Le
 * scan peut être relancé : `DiagnosticBatchRunner::start_batch` réénumère
 * la liste, le diagnostic existant est écrasé via upsert.
 *
 * @param {() => void} [onComplete] Callback déclenché en fin de scan réussi.
 *                                  Typiquement `refetchStats` + `refetchList`.
 * @return {{
 *   isScanning: boolean,
 *   progress: ?{processed: number, total: number},
 *   error: ?string,
 *   startScan: () => Promise<void>,
 *   reset: () => void,
 * }}
 */

import { useCallback, useState } from '@wordpress/element';
import * as api from '../api';

export function useScanBatch( onComplete ) {
	const [ isScanning, setIsScanning ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ error, setError ] = useState( null );

	const startScan = useCallback( async () => {
		if ( isScanning ) {
			return;
		}
		setIsScanning( true );
		setError( null );
		setProgress( { processed: 0, total: 0 } );

		try {
			// 1. Démarre le batch — récupère post_ids + chunk_size côté serveur.
			const batch = await api.diagnostics.runBatch();
			const total = Number( batch.total_articles ?? 0 );
			const chunkSize = Math.max( 1, Number( batch.chunk_size ?? 20 ) );
			const postIds = Array.isArray( batch.post_ids )
				? batch.post_ids
				: [];
			const jobId = batch.job_id ?? null;

			setProgress( { processed: 0, total } );

			if ( 0 === total ) {
				// Rien à diagnostiquer (corpus vide).
				onComplete?.();
				return;
			}

			// 2. Boucle de chunks. On envoie les requêtes en séquentiel pour
			//    ne pas saturer FPM (qui sur DevKinsta a peu de workers) et
			//    pour conserver un ordre prévisible du `progress`.
			let processed = 0;
			for ( let i = 0; i < postIds.length; i += chunkSize ) {
				const chunk = postIds.slice( i, i + chunkSize );
				const result = await api.diagnostics.runChunk( {
					job_id: jobId,
					chunk_post_ids: chunk,
				} );
				processed += Number( result.processed ?? chunk.length );
				setProgress( { processed, total } );
			}

			onComplete?.();
		} catch ( err ) {
			setError(
				err && err.message ? String( err.message ) : 'unknown_error'
			);
		} finally {
			setIsScanning( false );
		}
	}, [ isScanning, onComplete ] );

	const reset = useCallback( () => {
		setError( null );
		setProgress( null );
	}, [] );

	return { isScanning, progress, error, startScan, reset };
}
