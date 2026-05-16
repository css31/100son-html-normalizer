/**
 * RollbackConfirmModal — modale en deux temps pour le rollback d'un step.
 *
 * Phase 1 (`plan`) : appel `POST /steps/<uuid>/rollback` avec `dry_run=true`
 * pour obtenir le plan d'action + la détection cascade (steps postérieurs
 * ayant remodifié les articles à rollback). Affiche un récap structuré que
 * l'admin valide ou annule.
 *
 * Phase 2 (`executed`) : sur confirmation, second appel sans `dry_run` pour
 * exécuter les `wp_restore_post_revision()`. Affiche le résultat et déclenche
 * `onComplete` (le parent refetch le détail du step).
 *
 * Pas de listing exhaustif des `skipped` quand il y en a beaucoup — on
 * synthétise par raison, sinon un step de 200 articles avec 195 skipped
 * remplirait l'écran inutilement. Le détail brut est disponible côté CLI
 * (`wp htmln steps rollback <uuid> --dry-run`).
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { Modal, Spinner, Button, Notice } from '@wordpress/components';
import * as api from '../../api';

const SKIP_REASON_LABELS = {
	no_per_article_result: __(
		'aucun résultat enregistré pour cet article',
		'100son-html-normalizer'
	),
	article_not_success: __(
		"l'article n'a pas été écrit (régression, refus ou erreur)",
		'100son-html-normalizer'
	),
	revision_not_captured: __(
		'révision non capturée (lot antérieur à la fonctionnalité rollback)',
		'100son-html-normalizer'
	),
	revision_purged: __(
		'révision purgée par WordPress (limite WP_POST_REVISIONS dépassée)',
		'100son-html-normalizer'
	),
	revision_parent_mismatch: __(
		'révision pointant sur un autre article (anomalie BDD)',
		'100son-html-normalizer'
	),
};

/**
 * @param {string} reason Raison brute renvoyée par le backend.
 * @return {string} Libellé localisé ou la raison brute si inconnue.
 */
function skipReasonLabel( reason ) {
	return SKIP_REASON_LABELS[ reason ] || String( reason );
}

/**
 * @param {Object}     props
 * @param {string}     props.uuid       UUID du step à rollback.
 * @param {?number[]}  props.postIds    Sous-ensemble cible, ou null = tout le step.
 * @param {() => void} props.onClose    Fermeture sans rollback (annulation).
 * @param {() => void} props.onComplete Rollback exécuté → parent refetch.
 * @return {JSX.Element} Modale.
 */
export default function RollbackConfirmModal( {
	uuid,
	postIds,
	onClose,
	onComplete,
} ) {
	// `null` = en cours de chargement du plan ; `Object` = plan reçu.
	const [ plan, setPlan ] = useState( null );
	const [ planError, setPlanError ] = useState( null );

	// Phase d'exécution : 'idle' | 'running' | 'done'.
	const [ executePhase, setExecutePhase ] = useState( 'idle' );
	const [ executeResult, setExecuteResult ] = useState( null );
	const [ executeError, setExecuteError ] = useState( null );

	// Fetch du plan (dry-run) au montage.
	useEffect( () => {
		const cancelled = { value: false };
		( async () => {
			try {
				const body = {
					dry_run: true,
					...( postIds && postIds.length > 0
						? { post_ids: postIds }
						: {} ),
				};
				const result = await api.steps.rollback( uuid, body );
				if ( ! cancelled.value ) {
					setPlan( result );
				}
			} catch ( err ) {
				if ( ! cancelled.value ) {
					setPlanError(
						err && err.message
							? String( err.message )
							: 'unknown_error'
					);
				}
			}
		} )();
		return () => {
			cancelled.value = true;
		};
	}, [ uuid, postIds ] );

	const handleConfirm = useCallback( async () => {
		setExecutePhase( 'running' );
		setExecuteError( null );
		try {
			const body = {
				...( postIds && postIds.length > 0
					? { post_ids: postIds }
					: {} ),
			};
			const result = await api.steps.rollback( uuid, body );
			setExecuteResult( result );
			setExecutePhase( 'done' );
		} catch ( err ) {
			setExecuteError(
				err && err.message ? String( err.message ) : 'unknown_error'
			);
			setExecutePhase( 'idle' );
		}
	}, [ uuid, postIds ] );

	const handleCloseAfterDone = useCallback( () => {
		onComplete();
	}, [ onComplete ] );

	// Bandeau de chargement du plan.
	if ( null === plan && null === planError ) {
		return (
			<Modal
				title={ __(
					'Préparation du rollback…',
					'100son-html-normalizer'
				) }
				onRequestClose={ onClose }
				className="htmln-rollback-modal"
			>
				<div className="htmln-rollback-modal__loading">
					<Spinner />{ ' ' }
					{ __(
						'Analyse du lot et détection des cascades…',
						'100son-html-normalizer'
					) }
				</div>
			</Modal>
		);
	}

	// Erreur de fetch du plan.
	if ( null !== planError ) {
		return (
			<Modal
				title={ __( 'Rollback impossible', '100son-html-normalizer' ) }
				onRequestClose={ onClose }
				className="htmln-rollback-modal"
			>
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						// translators: %s = message d'erreur.
						__(
							'Impossible de préparer le rollback : %s',
							'100son-html-normalizer'
						),
						planError
					) }
				</Notice>
				<div className="htmln-rollback-modal__footer">
					<Button variant="secondary" onClick={ onClose }>
						{ __( 'Fermer', '100son-html-normalizer' ) }
					</Button>
				</div>
			</Modal>
		);
	}

	// Phase 2 — exécution terminée, affichage du résultat.
	if ( 'done' === executePhase && executeResult ) {
		const sum = executeResult.summary || {};
		return (
			<Modal
				title={ __( 'Rollback effectué', '100son-html-normalizer' ) }
				onRequestClose={ handleCloseAfterDone }
				className="htmln-rollback-modal"
			>
				<Notice
					status={ sum.errors > 0 ? 'warning' : 'success' }
					isDismissible={ false }
				>
					{ sprintf(
						// translators: 1 = articles rollback-és, 2 = ignorés, 3 = erreurs.
						__(
							'%1$d article(s) restauré(s) · %2$d ignoré(s) · %3$d erreur(s).',
							'100son-html-normalizer'
						),
						Number( sum.rolled_back ) || 0,
						Number( sum.skipped ) || 0,
						Number( sum.errors ) || 0
					) }
				</Notice>
				<ActionsList actions={ executeResult.actions || [] } />
				<div className="htmln-rollback-modal__footer">
					<Button variant="primary" onClick={ handleCloseAfterDone }>
						{ __( 'Fermer', '100son-html-normalizer' ) }
					</Button>
				</div>
			</Modal>
		);
	}

	// Phase 1 — affichage du plan, confirmation par l'admin.
	const summary = plan.summary || {};
	const actions = plan.actions || [];
	const cascade = plan.cascade || {};
	const willRollback = Number( summary.rolled_back ) || 0;
	const cascadeCount = Object.keys( cascade ).length;
	const isRunning = 'running' === executePhase;
	const subsetLabel =
		postIds && postIds.length > 0
			? sprintf(
					// translators: %d = nombre d'articles sélectionnés.
					_n(
						'sur %d article sélectionné',
						'sur %d articles sélectionnés',
						postIds.length,
						'100son-html-normalizer'
					),
					postIds.length
			  )
			: __( 'sur tout le lot', '100son-html-normalizer' );

	return (
		<Modal
			title={ __( 'Confirmer le rollback', '100son-html-normalizer' ) }
			onRequestClose={ isRunning ? undefined : onClose }
			className="htmln-rollback-modal"
		>
			<p>
				{ sprintf(
					// translators: 1 = articles qui seront restaurés, 2 = portée (sur tout le lot / sur X articles sélectionnés).
					__(
						'%1$d article(s) seront restauré(s) à leur état antérieur au lot, %2$s.',
						'100son-html-normalizer'
					),
					willRollback,
					subsetLabel
				) }
			</p>

			{ cascadeCount > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<strong>
						{ __(
							'Attention — modifications ultérieures détectées :',
							'100son-html-normalizer'
						) }
					</strong>
					<p>
						{ sprintf(
							// translators: %d = articles ré-modifiés par des lots postérieurs.
							_n(
								'%d article a été modifié par un ou plusieurs lots postérieurs ; le rollback annulera aussi ces écritures.',
								'%d articles ont été modifiés par des lots postérieurs ; le rollback annulera aussi ces écritures.',
								cascadeCount,
								'100son-html-normalizer'
							),
							cascadeCount
						) }
					</p>
					<ul className="htmln-rollback-modal__cascade-list">
						{ Object.entries( cascade ).map(
							( [ postId, uuids ] ) => (
								<li key={ postId }>
									{ sprintf(
										// translators: 1 = post ID, 2 = nombre de steps postérieurs.
										_n(
											'#%1$s : %2$d lot postérieur',
											'#%1$s : %2$d lots postérieurs',
											uuids.length,
											'100son-html-normalizer'
										),
										postId,
										uuids.length
									) }
								</li>
							)
						) }
					</ul>
				</Notice>
			) }

			<ActionsList actions={ actions } />

			{ executeError && (
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						// translators: %s = message d'erreur.
						__(
							"Échec de l'exécution : %s",
							'100son-html-normalizer'
						),
						executeError
					) }
				</Notice>
			) }

			<div className="htmln-rollback-modal__footer">
				<Button
					variant="secondary"
					onClick={ onClose }
					disabled={ isRunning }
				>
					{ __( 'Annuler', '100son-html-normalizer' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					onClick={ handleConfirm }
					disabled={ isRunning || 0 === willRollback }
				>
					{ isRunning && <Spinner /> }{ ' ' }
					{ isRunning
						? __( 'Rollback en cours…', '100son-html-normalizer' )
						: __(
								'Confirmer le rollback',
								'100son-html-normalizer'
						  ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Liste des actions du plan / résultat, regroupées par catégorie.
 *
 * @param {Object}                                                                                            props
 * @param {Array<{post_id: number, status: string, reason?: string, revision_id?: number, message?: string}>} props.actions Actions brutes.
 * @return {JSX.Element|null} Liste structurée ou null si vide.
 */
function ActionsList( { actions } ) {
	if ( ! actions || 0 === actions.length ) {
		return null;
	}

	const rollback = actions.filter(
		( a ) => 'rolled_back' === a.status || 'would_rollback' === a.status
	);
	const skipped = actions.filter( ( a ) => 'skipped' === a.status );
	const errors = actions.filter( ( a ) => 'error' === a.status );

	// Skipped synthétisés par raison pour ne pas saturer l'écran.
	const skipByReason = skipped.reduce( ( acc, a ) => {
		const key = String( a.reason || 'unknown' );
		if ( ! acc[ key ] ) {
			acc[ key ] = 0;
		}
		acc[ key ] += 1;
		return acc;
	}, {} );

	return (
		<div className="htmln-rollback-modal__actions">
			{ rollback.length > 0 && (
				<details open>
					<summary>
						{ sprintf(
							// translators: %d = nombre d'articles à restaurer.
							__(
								'Articles à restaurer (%d)',
								'100son-html-normalizer'
							),
							rollback.length
						) }
					</summary>
					<ul className="htmln-rollback-modal__post-ids">
						{ rollback.map( ( a ) => (
							<li key={ a.post_id }>
								#{ a.post_id }
								{ a.revision_id && (
									<small>
										{ ' ' }
										→{ ' ' }
										{ sprintf(
											// translators: %d = ID de révision WP.
											__(
												'révision %d',
												'100son-html-normalizer'
											),
											Number( a.revision_id )
										) }
									</small>
								) }
							</li>
						) ) }
					</ul>
				</details>
			) }

			{ Object.keys( skipByReason ).length > 0 && (
				<details>
					<summary>
						{ sprintf(
							// translators: %d = nombre d'articles ignorés.
							__(
								'Articles ignorés (%d)',
								'100son-html-normalizer'
							),
							skipped.length
						) }
					</summary>
					<ul className="htmln-rollback-modal__reasons">
						{ Object.entries( skipByReason ).map(
							( [ reason, count ] ) => (
								<li key={ reason }>
									<strong>{ count }</strong>
									{ ' — ' }
									{ skipReasonLabel( reason ) }
								</li>
							)
						) }
					</ul>
				</details>
			) }

			{ errors.length > 0 && (
				<details open>
					<summary>
						{ sprintf(
							// translators: %d = nombre d'erreurs techniques.
							__( 'Erreurs (%d)', '100son-html-normalizer' ),
							errors.length
						) }
					</summary>
					<ul className="htmln-rollback-modal__errors">
						{ errors.map( ( a ) => (
							<li key={ a.post_id }>
								#{ a.post_id } —{ ' ' }
								<code>
									{ String( a.message || 'unknown' ) }
								</code>
							</li>
						) ) }
					</ul>
				</details>
			) }
		</div>
	);
}
