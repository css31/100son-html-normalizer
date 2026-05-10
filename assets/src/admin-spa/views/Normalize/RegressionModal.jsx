/**
 * RegressionModal — modale de décision admin sur régression (F15).
 *
 * Cf. cahier §3.1 F15. Affichée par `Normalize.jsx` quand
 * `useStepRunner.regressionPending` est non null. Permet à l'admin
 * de décider :
 *   - « Confirmer quand même » → écriture forcée + reprise du pas ;
 *   - « Refuser cet article » → post_meta de relance + reprise sans cet article ;
 *   - « Voir le diff complet » → ouvre `DiffModal` côte-à-côte.
 *
 * Le hook `useStepRunner.confirmDecision` se charge d'appeler
 * `/steps/<uuid>/confirm-article` puis de reprendre la boucle des
 * chunks restants. La modale n'a qu'à invoquer le callback approprié.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Modal, Button } from '@wordpress/components';
import MetricsDiffBar from './MetricsDiffBar';
import DiffModal from './DiffModal';

/**
 * Format lisible d'une RegressionFailure individuelle.
 *
 * @param {Object} failure {metric_key, before, after, threshold, unit, loss, loss_pct}.
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
 * @param {Object}                                                                                  props
 * @param {{post_id: number, regression: ?Object, metrics_before: ?Object, metrics_after: ?Object}} props.pending     Article fautif.
 * @param {string[]}                                                                                props.ruleIds     Règles cochées (pour DiffModal).
 * @param {(d: 'confirm'|'refuse') => void}                                                         props.onDecision  Callback décision.
 * @param {boolean}                                                                                 [props.isPending] Vrai pendant l'appel REST de la décision.
 * @return {JSX.Element} Modale.
 */
export default function RegressionModal( {
	pending,
	ruleIds,
	onDecision,
	isPending = false,
} ) {
	const [ isDiffOpen, setIsDiffOpen ] = useState( false );

	const failures = pending?.regression?.failures ?? [];

	if ( isDiffOpen ) {
		return (
			<DiffModal
				postId={ pending.post_id }
				ruleIds={ ruleIds }
				onClose={ () => setIsDiffOpen( false ) }
			/>
		);
	}

	return (
		<Modal
			title={ sprintf(
				// translators: %d = identifiant article.
				__(
					'Régression détectée — article #%d',
					'100son-html-normalizer'
				),
				pending.post_id
			) }
			onRequestClose={ () => onDecision( 'refuse' ) }
			className="htmln-regression-modal"
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc={ false }
		>
			<p>
				{ __(
					"L'application des règles entraîne une perte de contenu au-delà des seuils γ configurés. Décidez si vous validez quand même cette modification.",
					'100son-html-normalizer'
				) }
			</p>

			<h3>
				{ sprintf(
					// translators: %d = nombre de métriques fautives.
					__(
						'%d métrique(s) en dépassement',
						'100son-html-normalizer'
					),
					failures.length
				) }
			</h3>
			<ul className="htmln-regression-modal__failures">
				{ failures.map( ( failure, idx ) => (
					<li key={ idx }>{ describeFailure( failure ) }</li>
				) ) }
			</ul>

			{ pending.metrics_before && pending.metrics_after && (
				<>
					<h3>
						{ __(
							'Métriques complètes avant / après',
							'100son-html-normalizer'
						) }
					</h3>
					<MetricsDiffBar
						before={ pending.metrics_before }
						after={ pending.metrics_after }
					/>
				</>
			) }

			<div className="htmln-regression-modal__actions">
				<Button
					variant="secondary"
					onClick={ () => setIsDiffOpen( true ) }
					disabled={ isPending }
				>
					{ __( 'Voir le diff complet', '100son-html-normalizer' ) }
				</Button>{ ' ' }
				<Button
					variant="secondary"
					onClick={ () => onDecision( 'refuse' ) }
					disabled={ isPending }
					isDestructive
				>
					{ __( 'Refuser cet article', '100son-html-normalizer' ) }
				</Button>{ ' ' }
				<Button
					variant="primary"
					onClick={ () => onDecision( 'confirm' ) }
					disabled={ isPending }
				>
					{ __( 'Confirmer quand même', '100son-html-normalizer' ) }
				</Button>
			</div>

			<p className="htmln-regression-modal__hint">
				{ __(
					"« Refuser » pose une post_meta de relance manuelle ; l'article reste inchangé. « Confirmer » écrit le résultat normalisé après création d'une révision WP (rollback natif disponible).",
					'100son-html-normalizer'
				) }
			</p>
		</Modal>
	);
}
