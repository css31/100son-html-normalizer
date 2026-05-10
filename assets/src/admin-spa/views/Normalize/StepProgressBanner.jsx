/**
 * StepProgressBanner — bandeau de progression du pas en cours (F14.4).
 *
 * Affiche pendant qu'un pas tourne :
 *  - le numérateur / dénominateur d'articles traités ;
 *  - une barre visuelle ;
 *  - un avertissement « gardez cet onglet ouvert » (le hook
 *    `useBeforeunload` enclenché en parallèle déclenchera le dialogue
 *    natif du navigateur si l'utilisateur tente de fermer l'onglet).
 *
 * Phase 6.5 : le mode « régression détectée » a migré vers
 * `RegressionModal` (modale dédiée avec choix Confirmer/Refuser/Voir
 * le diff). Ce bandeau ne gère plus que les modes « en cours » et
 * « erreur fatale ».
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';

/**
 * @param {Object}     props
 * @param {?Object}    props.progress  {uuid, totalArticles, processedCount}.
 * @param {?string}    props.error     Message d'erreur fatal pour le pas.
 * @param {() => void} props.onAbandon Callback abandon du pas.
 * @return {?JSX.Element} Bandeau ou null si rien à afficher.
 */
export default function StepProgressBanner( { progress, error, onAbandon } ) {
	if ( ! progress ) {
		return null;
	}

	const total = progress.totalArticles ?? 0;
	const done = progress.processedCount ?? 0;
	const pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;

	// Mode erreur fatale : le serveur a renvoyé une erreur sur run/process/finalize.
	if ( error ) {
		return (
			<div
				className="htmln-step-banner htmln-step-banner--error notice notice-error"
				role="alert"
			>
				<p>
					<strong>
						{ __(
							'Erreur pendant le pas',
							'100son-html-normalizer'
						) }
					</strong>
					{ ' — ' }
					{ error }
				</p>
				<p>
					<Button variant="secondary" onClick={ onAbandon }>
						{ __( 'Terminer le pas', '100son-html-normalizer' ) }
					</Button>
				</p>
			</div>
		);
	}

	// Mode normal : progression.
	return (
		<div
			className="htmln-step-banner htmln-step-banner--running notice notice-info"
			role="status"
			aria-live="polite"
		>
			<p>
				<Spinner />{ ' ' }
				<strong>
					{ __( 'Pas en cours', '100son-html-normalizer' ) }
				</strong>
				{ ' — ' }
				{ sprintf(
					// translators: 1 = nombre traités, 2 = total, 3 = pourcentage.
					__(
						'%1$d / %2$d articles (%3$d %%)',
						'100son-html-normalizer'
					),
					done,
					total,
					pct
				) }
			</p>
			<div
				className="htmln-step-banner__progress"
				role="progressbar"
				aria-valuenow={ pct }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
			>
				<div
					className="htmln-step-banner__progress-fill"
					style={ { width: pct + '%' } }
				/>
			</div>
			<p className="htmln-step-banner__warning">
				{ __(
					'Important : gardez cet onglet ouvert. La fermeture interrompra le pas.',
					'100son-html-normalizer'
				) }
			</p>
		</div>
	);
}
