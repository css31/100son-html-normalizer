/**
 * SessionGate — wrapper de l'App qui orchestre le verrou single-user.
 *
 * Cycle de vie :
 *  1. Au mount : tente `acquire`. En cas de succès, démarre un heartbeat
 *     toutes les `HEARTBEAT_INTERVAL_MS`. En cas de 409, bascule en mode
 *     bloquant avec écran `<SessionLockScreen>`.
 *  2. Pendant la session : un middleware apiFetch (cf. `api/client.js`)
 *     écoute les 409 sur toutes les routes ; s'il en détecte un, signale
 *     le gate via `onSessionLockLost` → bascule en mode bloquant sans
 *     attendre le prochain heartbeat.
 *  3. Au unload : libère le verrou via `navigator.sendBeacon` (fiable au
 *     unload contrairement à `fetch`).
 *  4. Bouton « Forcer la prise de contrôle » → `acquire(force=true)`.
 *
 * Trois états visuels :
 *  - `pending` : skeleton minimal pendant le premier acquire (typiquement
 *    < 100 ms — peut être invisible).
 *  - `owned`   : App rendue, heartbeat actif.
 *  - `locked`  : SessionLockScreen rendue à la place de l'App.
 */

import { useEffect, useRef, useState, useCallback } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Snackbar } from '@wordpress/components';
import {
	acquire as acquireLock,
	heartbeat as heartbeatLock,
	releaseBeacon,
} from '../api/session';
import {
	onSessionLockLost,
	onSecondaryWriteBlocked,
	onTakeOverRequest,
} from '../api/client';
import { clearStoredSessionId } from '../session/sessionId';
import { STORE_NAME } from '../store';
import SessionLockScreen from './SessionLockScreen';

/**
 * Intervalle entre deux heartbeats (ms). Choisi < TTL/5 pour garder une
 * large marge en cas de retard réseau ou de tab throttling navigateur.
 * Backend TTL = 300 s, ici 60 s → 5 heartbeats par TTL.
 */
const HEARTBEAT_INTERVAL_MS = 60_000;

/**
 * États du gate.
 *
 *  - `pending`    : premier acquire en cours (skeleton bref).
 *  - `owned`      : verrou détenu, App rendue, heartbeat actif (mode primary).
 *  - `read_only`  : verrou détenu par un autre, l'utilisateur a choisi
 *                   « Continuer en lecture seule » — App rendue, **pas** de
 *                   heartbeat ni release au unload (on n'a pas le verrou),
 *                   les boutons mutatifs sont désactivés via `useIsReadOnly`.
 *  - `locked`     : écran bloquant (cas `htmln_session_required` ou erreur
 *                   réseau au boot).
 */
const STATE_PENDING = 'pending';
const STATE_OWNED = 'owned';
const STATE_READ_ONLY = 'read_only';
const STATE_LOCKED = 'locked';

/**
 * @param {Object}      props
 * @param {JSX.Element} props.children Application à monter quand le verrou est détenu.
 * @return {JSX.Element} `children`, le `<SessionLockScreen>` ou un skeleton pending.
 */
export default function SessionGate( { children } ) {
	const [ state, setState ] = useState( STATE_PENDING );
	const [ lockInfo, setLockInfo ] = useState( {
		owner: null,
		code: null,
		message: '',
	} );
	const [ forceError, setForceError ] = useState( null );
	const [ blockedToast, setBlockedToast ] = useState( null );
	const { setSessionMode } = useDispatch( STORE_NAME );

	// Ref vers l'ID d'interval du heartbeat — accessible depuis le cleanup
	// useEffect même si le composant re-render entre temps.
	const heartbeatIntervalRef = useRef( null );

	/**
	 * Démarre le heartbeat périodique. Idempotent : un appel ultérieur
	 * réinitialise le timer sans en empiler.
	 */
	const startHeartbeat = useCallback( () => {
		if ( heartbeatIntervalRef.current ) {
			clearInterval( heartbeatIntervalRef.current );
		}
		heartbeatIntervalRef.current = window.setInterval( () => {
			heartbeatLock().catch( ( err ) => {
				// Le middleware apiFetch a déjà notifié `onSessionLockLost`
				// si c'est un 409 — donc inutile de basculer ici. Pour les
				// autres erreurs (réseau, 5xx), on garde la session active
				// et on retentera au prochain tick.
				// eslint-disable-next-line no-console
				console.warn( '[htmln-spa] heartbeat error', err );
			} );
		}, HEARTBEAT_INTERVAL_MS );
	}, [] );

	/**
	 * Stoppe le heartbeat.
	 */
	const stopHeartbeat = useCallback( () => {
		if ( heartbeatIntervalRef.current ) {
			clearInterval( heartbeatIntervalRef.current );
			heartbeatIntervalRef.current = null;
		}
	}, [] );

	/**
	 * Tente d'acquérir le verrou. Met à jour `state` / `lockInfo`.
	 *
	 * @param {boolean} [force=false] Forcer la prise.
	 * @return {Promise<boolean>} true si acquis.
	 */
	const tryAcquire = useCallback(
		async ( force = false ) => {
			setForceError( null );
			try {
				await acquireLock( force );
				setLockInfo( { owner: null, code: null, message: '' } );
				setState( STATE_OWNED );
				setSessionMode( 'primary' );
				startHeartbeat();
				return true;
			} catch ( err ) {
				const status = err?.data?.status ?? null;
				if ( 409 === status ) {
					const code = err?.code ?? 'htmln_session_locked';
					setLockInfo( {
						owner: err?.data?.owner ?? null,
						code,
						message: err?.message ?? '',
					} );
					stopHeartbeat();
					// `htmln_session_required` n'offre pas le mode lecture
					// seule (le caller doit recharger pour récupérer un
					// session_id valide). Sinon, on retombe sur STATE_LOCKED
					// avec choix Forcer / Lecture seule via le composant.
					setState( STATE_LOCKED );
					if ( force ) {
						setForceError(
							err?.message ||
								__(
									'Impossible de reprendre la main.',
									'100son-html-normalizer'
								)
						);
					}
					return false;
				}
				// Erreur réseau / autre — log et affiche écran bloquant
				// générique pour éviter de laisser la SPA dans un état
				// incertain (utilisateur croit qu'il a la main alors que
				// le serveur ignore son existence).
				// eslint-disable-next-line no-console
				console.error( '[htmln-spa] acquire error', err );
				setLockInfo( {
					owner: null,
					code: 'htmln_session_required',
					message:
						err?.message ||
						__(
							'Impossible de joindre le serveur pour initialiser la session.',
							'100son-html-normalizer'
						),
				} );
				stopHeartbeat();
				setState( STATE_LOCKED );
				return false;
			}
		},
		[ startHeartbeat, stopHeartbeat, setSessionMode ]
	);

	/**
	 * Bascule la session en mode lecture seule. Appelé depuis le bouton
	 * « Continuer en lecture seule » du `<SessionLockScreen>`. Pas de
	 * heartbeat ni de release au unload — on ne détient pas le verrou.
	 * Le store est mis à jour pour que `useIsReadOnly` réponde true partout.
	 */
	const enterReadOnly = useCallback( () => {
		stopHeartbeat();
		setForceError( null );
		setSessionMode( 'secondary' );
		setState( STATE_READ_ONLY );
	}, [ stopHeartbeat, setSessionMode ] );

	// Acquire initial au mount + abonnement aux 409 globaux + cleanup.
	useEffect( () => {
		tryAcquire( false );

		const unsubscribe = onSessionLockLost( ( payload ) => {
			setLockInfo( {
				owner: payload?.owner ?? null,
				code: payload?.code ?? 'htmln_session_locked',
				message: payload?.message ?? '',
			} );
			stopHeartbeat();
			setState( STATE_LOCKED );
		} );

		// Toast non bloquant pour les 409 mutatifs en mode secondaire.
		// L'auto-dismiss après 5 s suffit — l'utilisateur n'a pas besoin
		// d'agir, juste de comprendre pourquoi son action a été ignorée.
		const unsubscribeBlocked = onSecondaryWriteBlocked( () => {
			setBlockedToast(
				__(
					'Action non autorisée en lecture seule. Prenez la main pour modifier.',
					'100son-html-normalizer'
				)
			);
			window.setTimeout( () => setBlockedToast( null ), 5000 );
		} );

		// Le badge `Session secondaire` émet ce signal quand l'utilisateur
		// clique « Prendre la main ». La logique d'acquire reste
		// centralisée ici (heartbeat à redémarrer, mode store à mettre à
		// jour, gestion d'erreur cohérente).
		const unsubscribeTakeOver = onTakeOverRequest( () => {
			tryAcquire( true );
		} );

		// Libération best-effort au unload. `pagehide` est plus fiable
		// que `beforeunload` sur Firefox/mobile (back-forward cache),
		// `beforeunload` couvre les vieux cas. Les deux sont idempotents
		// côté serveur (release no-op si pas owner).
		const handleUnload = () => {
			releaseBeacon();
		};
		window.addEventListener( 'pagehide', handleUnload );
		window.addEventListener( 'beforeunload', handleUnload );

		return () => {
			unsubscribe();
			unsubscribeBlocked();
			unsubscribeTakeOver();
			stopHeartbeat();
			window.removeEventListener( 'pagehide', handleUnload );
			window.removeEventListener( 'beforeunload', handleUnload );
			// Tentative finale au démontage React (rare en pratique — l'App
			// est montée pour toute la durée de vie de la page admin).
			releaseBeacon();
			clearStoredSessionId();
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( STATE_OWNED === state || STATE_READ_ONLY === state ) {
		// L'App est montée dans les deux cas — c'est `useIsReadOnly` (lu
		// depuis le store) qui pilote la désactivation des boutons mutatifs
		// au cas par cas. Le verrou serveur reste de toute façon en filet
		// final (409 sur toute écriture en mode secondaire).
		return (
			<>
				{ children }
				{ blockedToast && (
					<div className="htmln-session-toast">
						<Snackbar onRemove={ () => setBlockedToast( null ) }>
							{ blockedToast }
						</Snackbar>
					</div>
				) }
			</>
		);
	}

	if ( STATE_LOCKED === state ) {
		// « Continuer en lecture seule » n'a de sens que si un détenteur
		// existe (cas `htmln_session_locked`). Pour les autres erreurs
		// (`htmln_session_required`, panne réseau au boot), on n'expose
		// pas l'option — le bouton « Recharger » couvre déjà le cas.
		const allowReadOnly = 'htmln_session_locked' === lockInfo.code;
		return (
			<SessionLockScreen
				owner={ lockInfo.owner }
				code={ lockInfo.code }
				message={ lockInfo.message }
				forceError={ forceError }
				onForce={ () => tryAcquire( true ) }
				onReload={ () => window.location.reload() }
				onReadOnly={ allowReadOnly ? enterReadOnly : null }
			/>
		);
	}

	// État `pending` — skeleton minimal. Pas de spinner pour éviter le
	// flash si l'acquire est instantané (cas commun).
	return <div className="htmln-session-gate htmln-session-gate--pending" />;
}
