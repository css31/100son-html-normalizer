/**
 * ScanBar — barre d'actions au-dessus de TabsHeader dans l'onglet Normaliser.
 *
 * Deux états visuels :
 *  - **Idle** : un bouton « Lancer un scan complet » + éventuel message
 *    d'erreur du dernier scan échoué (dismissible).
 *  - **Scan en cours** : libellé d'état + barre de progression remplie
 *    selon `processed / total`, bouton désactivé.
 *
 * Pas de modale ni de confirm — le scan est non-destructif (upsert
 * idempotent dans `son100_htmln_diagnostics`). L'utilisateur peut
 * relancer autant de fois qu'il veut.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

/**
 * @param {Object}                              props
 * @param {boolean}                             props.isScanning     Scan en cours.
 * @param {?{processed: number, total: number}} props.progress       Avancement.
 * @param {?string}                             props.error          Message d'erreur du dernier scan (ou null).
 * @param {boolean}                             props.disabled       Bloque le bouton (ex. pas en cours).
 * @param {() => void}                          props.onScan         Déclenche le scan.
 * @param {() => void}                          props.onDismissError Reset l'erreur affichée.
 * @return {JSX.Element} Barre.
 */
export default function ScanBar( {
	isScanning,
	progress,
	error,
	disabled,
	onScan,
	onDismissError,
} ) {
	const processed = progress?.processed ?? 0;
	const total = progress?.total ?? 0;
	const pct =
		total > 0
			? Math.min( 100, Math.round( ( processed / total ) * 100 ) )
			: 0;

	return (
		<div className="htmln-scan-bar">
			<div className="htmln-scan-bar__main">
				<Button
					variant="secondary"
					onClick={ onScan }
					disabled={ isScanning || disabled }
					isBusy={ isScanning }
				>
					{ isScanning
						? __( 'Scan en cours…', '100son-html-normalizer' )
						: __( 'Scanner le corpus', '100son-html-normalizer' ) }
				</Button>

				{ isScanning && (
					<div className="htmln-scan-bar__status">
						<span className="htmln-scan-bar__count">
							{ sprintf(
								// translators: 1 = articles traités, 2 = total.
								__(
									'%1$d / %2$d articles',
									'100son-html-normalizer'
								),
								processed,
								total
							) }
						</span>
						<div className="htmln-scan-bar__progress">
							<div
								className="htmln-scan-bar__progress-fill"
								style={ { width: `${ pct }%` } }
							/>
						</div>
					</div>
				) }

				{ ! isScanning && (
					<p className="htmln-scan-bar__hint description">
						{ __(
							'Recalcule le diagnostic pour tous les articles publiés (post_type=post). Idempotent.',
							'100son-html-normalizer'
						) }
					</p>
				) }
			</div>

			{ error && ! isScanning && (
				<Notice
					status="error"
					onRemove={ onDismissError }
					isDismissible
				>
					{ sprintf(
						// translators: %s = message d'erreur.
						__( 'Le scan a échoué : %s', '100son-html-normalizer' ),
						error
					) }
				</Notice>
			) }
		</div>
	);
}
