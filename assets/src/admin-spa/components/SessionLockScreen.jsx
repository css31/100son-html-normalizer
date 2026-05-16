/**
 * SessionLockScreen — écran bloquant affiché quand le verrou single-user
 * est détenu par un autre administrateur (ou par soi-même dans un autre
 * onglet). Présente le détenteur courant et propose un bouton
 * « Forcer la prise de contrôle » qui appelle `acquire(force=true)`.
 *
 * Cf. backend `Rest\Session\SessionLock::guard` (réponse 409 avec
 * `data.owner = {user_id, user_login, display_name, started_at,
 * last_seen_at}`).
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

/**
 * Formate un timestamp UNIX en heure locale `HH:MM` lisible. Retombe sur
 * « — » si la valeur est invalide ou absente.
 *
 * @param {?number} ts Timestamp UNIX en secondes.
 * @return {string} Heure formatée `HH:MM` ou « — ».
 */
function formatTime( ts ) {
	if ( ! ts || typeof ts !== 'number' ) {
		return '—';
	}
	const date = new Date( ts * 1000 );
	if ( Number.isNaN( date.getTime() ) ) {
		return '—';
	}
	return date.toLocaleTimeString( undefined, {
		hour: '2-digit',
		minute: '2-digit',
	} );
}

/**
 * @param {Object}           props
 * @param {?Object}          [props.owner]      Détenteur courant (peut être null pour `htmln_session_required`).
 * @param {string}           [props.message]    Message d'erreur serveur (fallback localisé fourni).
 * @param {string}           [props.code]       Code d'erreur (`htmln_session_locked` ou `htmln_session_required`).
 * @param {() => Promise<*>} props.onForce      Handler du bouton « Forcer la prise de contrôle ».
 * @param {() => void}       props.onReload     Handler du bouton « Recharger la page » (cas `session_required`).
 * @param {?(() => void)}    [props.onReadOnly] Handler du bouton « Continuer en lecture seule ». Si omis, le bouton n'est pas rendu (cas `session_required` ou erreur réseau).
 * @param {?string}          [props.forceError] Erreur survenue lors d'une tentative de force précédente.
 * @return {JSX.Element} Card centrée avec détails du verrou et bouton d'action.
 */
export default function SessionLockScreen( {
	owner,
	message,
	code,
	onForce,
	onReload,
	onReadOnly,
	forceError,
} ) {
	const [ isForcing, setIsForcing ] = useState( false );

	const handleForce = async () => {
		setIsForcing( true );
		try {
			await onForce();
		} finally {
			setIsForcing( false );
		}
	};

	const displayName = owner?.display_name || owner?.user_login || '';
	const startedAt = formatTime( owner?.started_at );
	const lastSeenAt = formatTime( owner?.last_seen_at );

	return (
		<div className="htmln-session-lock">
			<div className="htmln-session-lock__card">
				<h1 className="htmln-session-lock__title">
					{ __(
						'Extension en cours d’utilisation',
						'100son-html-normalizer'
					) }
				</h1>

				{ owner ? (
					<>
						<p className="htmln-session-lock__intro">
							{ sprintf(
								/* translators: %s: nom de l'administrateur qui détient le verrou. */
								__(
									'%s utilise actuellement HTML Normalizer dans un autre onglet ou navigateur.',
									'100son-html-normalizer'
								),
								displayName
							) }
						</p>
						<dl className="htmln-session-lock__meta">
							<dt>
								{ __(
									'Session ouverte à',
									'100son-html-normalizer'
								) }
							</dt>
							<dd>{ startedAt }</dd>
							<dt>
								{ __(
									'Dernière activité',
									'100son-html-normalizer'
								) }
							</dt>
							<dd>{ lastSeenAt }</dd>
						</dl>
					</>
				) : (
					<p className="htmln-session-lock__intro">
						{ message ||
							__(
								'Votre session a été perdue. Rechargez la page pour reprendre la main.',
								'100son-html-normalizer'
							) }
					</p>
				) }

				<p className="htmln-session-lock__explain">
					{ __(
						'HTML Normalizer ne supporte qu’un seul utilisateur actif à la fois pour éviter les conflits d’écriture pendant les scans et les normalisations.',
						'100son-html-normalizer'
					) }
				</p>

				{ forceError && (
					<Notice
						status="error"
						isDismissible={ false }
						className="htmln-session-lock__error"
					>
						{ forceError }
					</Notice>
				) }

				<div className="htmln-session-lock__actions">
					{ 'htmln_session_required' === code ? (
						<Button variant="primary" onClick={ onReload }>
							{ __(
								'Recharger la page',
								'100son-html-normalizer'
							) }
						</Button>
					) : (
						<>
							<Button
								variant="primary"
								isBusy={ isForcing }
								disabled={ isForcing }
								onClick={ handleForce }
							>
								{ isForcing
									? __(
											'Reprise en cours…',
											'100son-html-normalizer'
									  )
									: __(
											'Forcer la prise de contrôle',
											'100son-html-normalizer'
									  ) }
							</Button>
							{ onReadOnly && (
								<Button
									variant="secondary"
									disabled={ isForcing }
									onClick={ onReadOnly }
								>
									{ __(
										'Continuer en lecture seule',
										'100son-html-normalizer'
									) }
								</Button>
							) }
						</>
					) }
				</div>

				<p className="htmln-session-lock__warning">
					{ __(
						'Forcer la prise de contrôle interrompra l’autre session — les opérations en cours qui n’ont pas encore été confirmées seront perdues.',
						'100son-html-normalizer'
					) }
				</p>
			</div>
		</div>
	);
}
