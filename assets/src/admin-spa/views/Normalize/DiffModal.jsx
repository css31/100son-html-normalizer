/**
 * DiffModal — modale plein écran de visualisation diff F14.3.
 *
 * Ouverte sur deux scénarios :
 *  1. Bouton « Voir le diff » sur une ligne du tableau (preview à la
 *     volée) — appelle `POST /posts/<id>/diff` avec les rule_ids
 *     actuellement cochés dans la sidebar, sans toucher à post_content ;
 *  2. Bouton « Voir le diff complet » dans `RegressionModal` — utilise
 *     les `metrics_before`/`metrics_after` déjà fournis par le résultat
 *     d'article + un fetch /diff pour récupérer le HTML.
 *
 * V1.0 : affichage simple côte-à-côte (pas de diff ligne-à-ligne
 * rouge/vert — différé V1.1). Deux blocs de code `<pre>` + deux
 * `<iframe sandbox="allow-same-origin">` (sans `allow-scripts`,
 * cf. cahier §13). Toggle pour basculer entre les vues.
 *
 * Sécurité (§13) :
 *  - sandbox iframe sans `allow-scripts` ;
 *  - `srcdoc` injecté après `sanitizeForIframe` (helper maison
 *    qui supprime <script>, on*, javascript:) — défense en profondeur.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { Modal, Button, Spinner } from '@wordpress/components';
import * as api from '../../api';
import { sanitizeForIframe } from '../../utils/sanitizeForIframe';
import MetricsDiffBar from './MetricsDiffBar';

/**
 * Modes d'affichage de la modale.
 *
 * @type {{CODE: string, RENDER: string}}
 */
const VIEW = {
	CODE: 'code',
	RENDER: 'render',
};

/**
 * @typedef {Object} DiffPayload
 * @property {string}   html_before    HTML avant normalisation.
 * @property {string}   html_after     HTML après normalisation.
 * @property {Object}   metrics_before Snapshot avant.
 * @property {Object}   metrics_after  Snapshot après.
 * @property {string[]} warnings       Avertissements éventuels du Pipeline.
 * @property {boolean}  unchanged      Vrai si HTML identique.
 */

/**
 * @param {Object}       props
 * @param {number}       props.postId           Article concerné.
 * @param {string[]}     props.ruleIds          Règles à appliquer pour le diff.
 * @param {?DiffPayload} [props.initialPayload] Si fourni, court-circuite le fetch (RegressionModal).
 * @param {() => void}   props.onClose          Callback fermeture.
 * @return {JSX.Element} Modale plein écran.
 */
export default function DiffModal( {
	postId,
	ruleIds,
	initialPayload = null,
	onClose,
} ) {
	const [ payload, setPayload ] = useState( initialPayload );
	const [ isLoading, setIsLoading ] = useState( null === initialPayload );
	const [ error, setError ] = useState( null );
	const [ view, setView ] = useState( VIEW.CODE );

	const fetchDiff = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		try {
			const result = await api.posts.diff( postId, {
				rule_ids: ruleIds,
			} );
			setPayload( result );
		} catch ( err ) {
			const msg =
				err && err.message ? String( err.message ) : 'diff_failed';
			setError( msg );
		} finally {
			setIsLoading( false );
		}
	}, [ postId, ruleIds ] );

	useEffect( () => {
		if ( ! initialPayload ) {
			fetchDiff();
		}
	}, [ initialPayload, fetchDiff ] );

	const renderBody = () => {
		if ( error ) {
			return (
				<div className="notice notice-error">
					<p>
						{ sprintf(
							// translators: %s = message d'erreur.
							__(
								'Erreur lors du calcul du diff : %s',
								'100son-html-normalizer'
							),
							error
						) }
					</p>
				</div>
			);
		}
		if ( isLoading || ! payload ) {
			return (
				<p className="htmln-diff-modal__loading">
					<Spinner />{ ' ' }
					{ __( 'Calcul du diff…', '100son-html-normalizer' ) }
				</p>
			);
		}

		return (
			<>
				<MetricsDiffBar
					before={ payload.metrics_before }
					after={ payload.metrics_after }
				/>

				{ payload.unchanged && (
					<div className="notice notice-info">
						<p>
							{ __(
								'Aucun changement : les règles cochées ne modifient pas cet article.',
								'100son-html-normalizer'
							) }
						</p>
					</div>
				) }

				<div className="htmln-diff-modal__view-toggle">
					<Button
						variant={ VIEW.CODE === view ? 'primary' : 'secondary' }
						onClick={ () => setView( VIEW.CODE ) }
					>
						{ __( 'Code source', '100son-html-normalizer' ) }
					</Button>{ ' ' }
					<Button
						variant={
							VIEW.RENDER === view ? 'primary' : 'secondary'
						}
						onClick={ () => setView( VIEW.RENDER ) }
					>
						{ __( 'Rendu HTML', '100son-html-normalizer' ) }
					</Button>
				</div>

				{ VIEW.CODE === view && (
					<div className="htmln-diff-modal__pane htmln-diff-modal__pane--code">
						<div className="htmln-diff-modal__col">
							<h3>{ __( 'Avant', '100son-html-normalizer' ) }</h3>
							<pre className="htmln-diff-modal__code">
								{ payload.html_before }
							</pre>
						</div>
						<div className="htmln-diff-modal__col">
							<h3>{ __( 'Après', '100son-html-normalizer' ) }</h3>
							<pre className="htmln-diff-modal__code">
								{ payload.html_after }
							</pre>
						</div>
					</div>
				) }

				{ VIEW.RENDER === view && (
					<div className="htmln-diff-modal__pane htmln-diff-modal__pane--render">
						<div className="htmln-diff-modal__col">
							<h3>{ __( 'Avant', '100son-html-normalizer' ) }</h3>
							<iframe
								title={ __(
									'Rendu avant normalisation',
									'100son-html-normalizer'
								) }
								sandbox="allow-same-origin"
								srcDoc={ sanitizeForIframe(
									payload.html_before
								) }
								className="htmln-diff-modal__iframe"
							/>
						</div>
						<div className="htmln-diff-modal__col">
							<h3>{ __( 'Après', '100son-html-normalizer' ) }</h3>
							<iframe
								title={ __(
									'Rendu après normalisation',
									'100son-html-normalizer'
								) }
								sandbox="allow-same-origin"
								srcDoc={ sanitizeForIframe(
									payload.html_after
								) }
								className="htmln-diff-modal__iframe"
							/>
						</div>
					</div>
				) }

				{ payload.warnings && payload.warnings.length > 0 && (
					<details className="htmln-diff-modal__warnings">
						<summary>
							{ sprintf(
								// translators: %d = nombre d'avertissements.
								__(
									'%d avertissement(s) du moteur',
									'100son-html-normalizer'
								),
								payload.warnings.length
							) }
						</summary>
						<ul>
							{ payload.warnings.map( ( w, i ) => (
								<li key={ i }>{ String( w ) }</li>
							) ) }
						</ul>
					</details>
				) }
			</>
		);
	};

	return (
		<Modal
			title={ sprintf(
				// translators: %d = identifiant article.
				__( "Diff de l'article #%d", '100son-html-normalizer' ),
				postId
			) }
			onRequestClose={ onClose }
			isFullScreen
			className="htmln-diff-modal"
		>
			{ renderBody() }
		</Modal>
	);
}
