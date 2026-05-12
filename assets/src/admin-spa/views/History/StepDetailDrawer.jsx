/**
 * StepDetailDrawer — modale plein écran détaillant un pas (F16).
 *
 * Affiche :
 *  - en-tête : UUID, dates, statut, compteurs synthétiques ;
 *  - liste `applied_rules` ;
 *  - tableau `per_article_results` détaillé par article :
 *      - post_id (lien edit_post natif WP) ;
 *      - status (badge) ;
 *      - régression résumée (si applicable, depuis le RegressionReport) ;
 *      - message d'erreur (si status='error') ;
 *  - bloc progression si le pas est encore en cours (non finalisé).
 *
 * Le composant est purement présentation — la vue parente (History)
 * fournit `step` et `progress` via le hook `useStepDetail`. Pas d'appel
 * REST direct.
 *
 * Pas d'actions destructives sur l'historique en V1.0 (cf. cahier §13 —
 * « Historique en lecture seule »).
 */

import { __, sprintf } from '@wordpress/i18n';
import { Modal, Spinner, Button } from '@wordpress/components';
import { formatRuleIdList } from '../../utils/ruleLabels';

/**
 * Format lisible d'une RegressionFailure (cf. RegressionFailure::to_array()).
 *
 * Helper local — dupliqué volontairement depuis `RegressionModal.jsx`
 * pour ne pas élargir le périmètre 6.6 (un futur refactor pourra le
 * sortir en `utils/describeFailure.js` si un 3ème site d'appel apparaît).
 *
 * @param {{metric_key?: string, before?: number, after?: number, threshold?: number, unit?: string, loss?: number, loss_pct?: number}} failure RegressionFailure sérialisée.
 * @return {string} Phrase descriptive.
 */
function describeFailure( failure ) {
	if ( ! failure || 'object' !== typeof failure ) {
		return '';
	}
	const before = Number( failure.before ) || 0;
	const after = Number( failure.after ) || 0;
	const loss = Number( failure.loss ) || 0;
	const lossPct = Number( failure.loss_pct );
	const threshold = Number( failure.threshold ) || 0;
	const unit = String( failure.unit || 'absolute' );
	const key = String( failure.metric_key || 'inconnu' );

	if ( 'pct' === unit ) {
		const pct = Number.isFinite( lossPct ) ? lossPct.toFixed( 2 ) : '?';
		return sprintf(
			// translators: 1 = clé métrique, 2 = avant, 3 = après, 4 = perte %, 5 = seuil %.
			__(
				'%1$s : %2$d → %3$d (perte %4$s %%, seuil %5$d %%)',
				'100son-html-normalizer'
			),
			key,
			before,
			after,
			pct,
			threshold
		);
	}
	return sprintf(
		// translators: 1 = clé métrique, 2 = avant, 3 = après, 4 = perte abs, 5 = seuil abs.
		__(
			'%1$s : %2$d → %3$d (perte %4$d, seuil %5$d)',
			'100son-html-normalizer'
		),
		key,
		before,
		after,
		loss,
		threshold
	);
}

/**
 * Libellé court pour un status d'ArticleResult.
 *
 * @param {string} status Un des `success`, `dry_run`, `regression_pending`, `refused`, `error`.
 * @return {string} Libellé localisé.
 */
function statusLabel( status ) {
	const labels = {
		success: __( 'Validé', '100son-html-normalizer' ),
		dry_run: __( 'Simulation', '100son-html-normalizer' ),
		regression_pending: __(
			'Régression en attente',
			'100son-html-normalizer'
		),
		refused: __( 'Refusé', '100son-html-normalizer' ),
		error: __( 'Erreur', '100son-html-normalizer' ),
	};
	return labels[ status ] || String( status );
}

/**
 * Classe CSS pour la pastille de status.
 *
 * @param {string}  status        Status brut.
 * @param {boolean} hasRegression Vrai si une régression a été confirmée.
 * @return {string} Classe complète `htmln-badge htmln-badge--*`.
 */
function statusBadgeClass( status, hasRegression ) {
	if ( 'error' === status ) {
		return 'htmln-badge htmln-badge--to_improve';
	}
	if ( 'refused' === status ) {
		return 'htmln-badge htmln-badge--stale';
	}
	if ( 'regression_pending' === status ) {
		return 'htmln-badge htmln-badge--to_improve';
	}
	if ( 'success' === status && hasRegression ) {
		// Succès post-confirmation — on signale visuellement que le succès
		// est venu d'une régression confirmée.
		return 'htmln-badge htmln-badge--normal htmln-badge--confirmed';
	}
	return 'htmln-badge htmln-badge--normal';
}

/**
 * Lien vers l'édition d'un article (post.php?post=ID&action=edit) calculé
 * à partir d'`admin_url()` exposé via les globals JS. En V1.0 on ne dispose
 * pas encore d'un `htmlnAdminConfig.adminUrl` — donc on construit un URL
 * relatif `post.php?post=…&action=edit` qui résout correctement en page
 * `/wp-admin/...`.
 *
 * @param {number} postId Identifiant article.
 * @return {string} URL.
 */
function editPostUrl( postId ) {
	return `post.php?post=${ encodeURIComponent( postId ) }&action=edit`;
}

/**
 * Bloc progression d'un pas non finalisé. `progress` provient du retour
 * REST `GET /steps/<uuid>` (cf. `useStepDetail`).
 *
 * @param {Object}                                                                                         props
 * @param {{total_articles: number, processed: number[], regression_pending: number[], pending: number[]}} props.progress Snapshot resume_progress.
 * @return {JSX.Element|null} Bloc résumé ou null si pas exploitable.
 */
function ProgressBlock( { progress } ) {
	if ( ! progress || 'object' !== typeof progress ) {
		return null;
	}
	const total = Number( progress.total_articles ) || 0;
	const processed = Array.isArray( progress.processed )
		? progress.processed.length
		: 0;
	const pending = Array.isArray( progress.pending )
		? progress.pending.length
		: 0;
	const regression = Array.isArray( progress.regression_pending )
		? progress.regression_pending.length
		: 0;
	return (
		<div className="htmln-step-detail__progress notice notice-warning inline">
			<p>
				<strong>
					{ __( 'Lot non finalisé', '100son-html-normalizer' ) }
				</strong>{ ' ' }
				{ sprintf(
					// translators: 1 = traités, 2 = total, 3 = en attente, 4 = bloqués sur régression.
					__(
						'%1$d / %2$d traités · %3$d en attente · %4$d bloqués sur régression.',
						'100son-html-normalizer'
					),
					processed,
					total,
					pending,
					regression
				) }
			</p>
		</div>
	);
}

/**
 * @param {Object}     props
 * @param {?Object}    props.step      StepRecord serialisé (cf. StepsController::step_to_array).
 * @param {?Object}    props.progress  Snapshot resume_progress ou null si finalisé.
 * @param {boolean}    props.isLoading Vrai durant le fetch.
 * @param {?string}    props.error     Message d'erreur ou null.
 * @param {() => void} props.onClose   Callback fermeture.
 * @return {JSX.Element} Modale plein écran.
 */
export default function StepDetailDrawer( {
	step,
	progress,
	isLoading,
	error,
	onClose,
} ) {
	const uuid = step?.uuid ?? '';

	return (
		<Modal
			title={
				uuid
					? sprintf(
							// translators: %s = UUID v4 complet du lot.
							__( 'Détail du lot %s', '100son-html-normalizer' ),
							uuid
					  )
					: __( 'Détail du lot', '100son-html-normalizer' )
			}
			onRequestClose={ onClose }
			className="htmln-step-detail-modal"
			isFullScreen
		>
			{ isLoading && ! step && (
				<div className="htmln-step-detail__loading">
					<Spinner />{ ' ' }
					{ __( 'Chargement du détail…', '100son-html-normalizer' ) }
				</div>
			) }

			{ error && (
				<div className="notice notice-error">
					<p>
						{ sprintf(
							// translators: %s = message d'erreur.
							__(
								'Impossible de charger le détail : %s',
								'100son-html-normalizer'
							),
							error
						) }
					</p>
				</div>
			) }

			{ step && (
				<div className="htmln-step-detail">
					<dl className="htmln-step-detail__meta">
						<dt>{ __( 'UUID', '100son-html-normalizer' ) }</dt>
						<dd>
							<code className="htmln-uuid">{ step.uuid }</code>
						</dd>

						<dt>
							{ __( 'Démarré le', '100son-html-normalizer' ) }
						</dt>
						<dd>
							<time dateTime={ String( step.started_at ?? '' ) }>
								{ String( step.started_at ?? '—' ) }
							</time>
						</dd>

						<dt>
							{ __( 'Terminé le', '100son-html-normalizer' ) }
						</dt>
						<dd>
							{ step.finished_at ? (
								<time dateTime={ String( step.finished_at ) }>
									{ String( step.finished_at ) }
								</time>
							) : (
								<em>
									{ __(
										'lot non finalisé',
										'100son-html-normalizer'
									) }
								</em>
							) }
						</dd>

						<dt>{ __( 'Articles', '100son-html-normalizer' ) }</dt>
						<dd>
							{ sprintf(
								// translators: 1 = total, 2 = validés, 3 = refusés, 4 = erreurs.
								__(
									'%1$d au total · ✓ %2$d validés · ✗ %3$d refusés · ⚠ %4$d en erreur',
									'100son-html-normalizer'
								),
								Number( step.total_articles ) || 0,
								Number( step.successful_articles ) || 0,
								Number( step.refused_articles ) || 0,
								Number( step.errored_articles ) || 0
							) }
						</dd>

						<dt>
							{ __(
								'Règles appliquées',
								'100son-html-normalizer'
							) }
						</dt>
						<dd>{ formatRuleIdList( step.applied_rules ) }</dd>
					</dl>

					{ ! step.is_finished && (
						<ProgressBlock progress={ progress } />
					) }

					<h3 className="htmln-step-detail__heading">
						{ __( 'Détail par article', '100son-html-normalizer' ) }
					</h3>
					<PerArticleResults
						perArticle={ step.per_article_results }
					/>

					<div className="htmln-step-detail__footer">
						<Button variant="secondary" onClick={ onClose }>
							{ __( 'Fermer', '100son-html-normalizer' ) }
						</Button>
					</div>
				</div>
			) }
		</Modal>
	);
}

/**
 * Tableau détaillé des résultats par article.
 *
 * @param {Object}                                                                props
 * @param {Object<string, {status: string, regression?: Object, error?: string}>} props.perArticle Map post_id → résultat.
 * @return {JSX.Element} Tableau ou message « vide ».
 */
function PerArticleResults( { perArticle } ) {
	if ( ! perArticle || 'object' !== typeof perArticle ) {
		return (
			<p className="htmln-empty">
				{ __(
					'Aucun article traité dans ce lot.',
					'100son-html-normalizer'
				) }
			</p>
		);
	}
	const entries = Object.entries( perArticle );
	if ( 0 === entries.length ) {
		return (
			<p className="htmln-empty">
				{ __(
					'Aucun article traité dans ce lot.',
					'100son-html-normalizer'
				) }
			</p>
		);
	}

	// Tri pour stabilité d'affichage : par post_id croissant.
	entries.sort( ( [ a ], [ b ] ) => Number( a ) - Number( b ) );

	return (
		<table className="wp-list-table widefat striped htmln-per-article-table">
			<thead>
				<tr>
					<th scope="col">
						{ __( 'Article', '100son-html-normalizer' ) }
					</th>
					<th scope="col">
						{ __( 'Statut', '100son-html-normalizer' ) }
					</th>
					<th scope="col">
						{ __( 'Détail', '100son-html-normalizer' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ entries.map( ( [ postId, entry ] ) => {
					const status = String( entry?.status ?? '' );
					const hasRegression =
						!! entry?.regression &&
						'object' === typeof entry.regression;
					const failures =
						hasRegression &&
						Array.isArray( entry.regression.failures )
							? entry.regression.failures
							: [];
					return (
						<tr key={ postId }>
							<th scope="row">
								<a
									href={ editPostUrl( Number( postId ) ) }
									target="_blank"
									rel="noopener noreferrer"
								>
									#{ postId }
								</a>
							</th>
							<td>
								<span
									className={ statusBadgeClass(
										status,
										hasRegression
									) }
								>
									{ statusLabel( status ) }
									{ hasRegression && 'success' === status && (
										<em className="htmln-badge__confirmed">
											{ ' ' }
											{ __(
												'(régression confirmée)',
												'100son-html-normalizer'
											) }
										</em>
									) }
								</span>
							</td>
							<td>
								{ entry?.error && (
									<p className="htmln-per-article-table__error">
										<code>{ String( entry.error ) }</code>
									</p>
								) }
								{ failures.length > 0 && (
									<ul className="htmln-per-article-table__failures">
										{ failures.map( ( failure, idx ) => (
											<li key={ idx }>
												{ describeFailure( failure ) }
											</li>
										) ) }
									</ul>
								) }
								{ ! entry?.error &&
									0 === failures.length &&
									'—' }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}
