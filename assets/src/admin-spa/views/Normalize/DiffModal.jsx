/* global HTMLIFrameElement, requestAnimationFrame */

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
import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { Modal, Button, Spinner } from '@wordpress/components';
import { lock, unlock, brush } from '@wordpress/icons';
import * as api from '../../api';
import { sanitizeForIframe } from '../../utils/sanitizeForIframe';
import { MetricsDiffSummary, MetricsDiffTable } from './MetricsDiffBar';
import BuilderBadge from './BuilderBadge';
import HighlightedCode from './HighlightedCode';

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
 * @property {string}   html_before              HTML avant normalisation.
 * @property {string}   html_after               HTML après normalisation.
 * @property {Object}   metrics_before           Snapshot avant.
 * @property {Object}   metrics_after            Snapshot après.
 * @property {string[]} warnings                 Avertissements éventuels du Pipeline.
 * @property {boolean}  unchanged                Vrai si HTML identique.
 * @property {string}   [post_date]              Date de publication (`Y-m-d H:i:s`, vide si absente).
 * @property {string[]} [categories]             Noms de catégories de l'article.
 * @property {?string}  [builder_type]           Type de constructeur (siteorigin / gutenberg / …) ou null.
 * @property {boolean}  [has_fossil_panels_data] Vrai si Gutenberg avec vestige `panels_data` (alimente le badge orange).
 */

/**
 * Formate une date `Y-m-d H:i:s` SQL vers une chaîne lisible (`12 mars 2024`).
 *
 * `new Date(sql)` est ambigu sur certains navigateurs (Safari interprète
 * `2024-03-12 14:30:00` comme invalide à cause de l'espace). On remplace
 * l'espace par un `T` pour obtenir un ISO 8601 sans timezone — interprété
 * en local time, ce qui correspond au comportement WP attendu (post_date
 * est l'heure locale du site).
 *
 * @param {string} sqlDate Chaîne `Y-m-d H:i:s` ou vide.
 * @return {string} Date formatée ou chaîne vide si parsing impossible.
 */
function formatPostDate( sqlDate ) {
	if ( ! sqlDate ) {
		return '';
	}
	const date = new Date( String( sqlDate ).replace( ' ', 'T' ) );
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}
	try {
		return new Intl.DateTimeFormat( 'fr-FR', {
			dateStyle: 'long',
		} ).format( date );
	} catch ( _err ) {
		return '';
	}
}

/**
 * @param {Object}       props
 * @param {number}       props.postId           Article concerné.
 * @param {string}       [props.postTitle]      Titre de l'article (affiché après l'ID dans le header).
 * @param {string[]}     props.ruleIds          Règles à appliquer pour le diff.
 * @param {?DiffPayload} [props.initialPayload] Si fourni, court-circuite le fetch (RegressionModal).
 * @param {() => void}   props.onClose          Callback fermeture.
 * @return {JSX.Element} Modale plein écran.
 */
export default function DiffModal( {
	postId,
	postTitle = '',
	ruleIds,
	initialPayload = null,
	onClose,
} ) {
	const [ payload, setPayload ] = useState( initialPayload );
	const [ isLoading, setIsLoading ] = useState( null === initialPayload );
	const [ error, setError ] = useState( null );
	const [ view, setView ] = useState( VIEW.CODE );

	// Verrou de synchronisation du défilement vertical entre les panneaux
	// « Avant » et « Après » — actif sur les deux vues (code source + rendu
	// HTML). Défaut désactivé : l'utilisateur l'active explicitement quand
	// il veut comparer deux passages alignés.
	const [ scrollSync, setScrollSync ] = useState( false );

	// Toggle « surlignage stabylo » des suppressions/ajouts dans la vue
	// Code source. **Activé par défaut** — c'est la valeur ajoutée centrale
	// de la modale Diff. Sous le capot, quand le toggle est ON,
	// `highlightHtmlWithDiff` produit du texte brut HTML-escaped + `<mark>`
	// (sans Prism). Quand le toggle est OFF, le chemin standard `highlightHtml`
	// applique Prism pour coloriser les tokens. L'utilisateur bascule selon
	// son besoin : lecture du diff (toggle ON) vs lecture du code (toggle OFF).
	const [ showDiffMarks, setShowDiffMarks ] = useState( true );
	const beforeScrollerRef = useRef( null );
	const afterScrollerRef = useRef( null );
	// Drapeau anti-boucle : `el.scrollTop = X` re-déclenche un événement
	// `scroll` sur la cible, qui sans ce flag re-syncerait la source vers
	// elle-même et la ferait osciller. Pattern : on lève le flag avant
	// d'écrire, on l'abaisse au prochain frame.
	const syncingRef = useRef( false );

	const syncFromTo = useCallback( ( source, target ) => {
		if ( ! source || ! target ) {
			return;
		}
		syncingRef.current = true;
		target.scrollTop = source.scrollTop;
		requestAnimationFrame( () => {
			syncingRef.current = false;
		} );
	}, [] );

	const handleBeforeScroll = useCallback( () => {
		if ( ! scrollSync || syncingRef.current ) {
			return;
		}
		syncFromTo( beforeScrollerRef.current, afterScrollerRef.current );
	}, [ scrollSync, syncFromTo ] );

	const handleAfterScroll = useCallback( () => {
		if ( ! scrollSync || syncingRef.current ) {
			return;
		}
		syncFromTo( afterScrollerRef.current, beforeScrollerRef.current );
	}, [ scrollSync, syncFromTo ] );

	// Vue « Rendu HTML » : les éléments scrollables ne sont pas les
	// `<iframe>` eux-mêmes mais leur `contentDocument`. On attache donc les
	// listeners dans un effet qui se redéclenche au changement de vue, de
	// payload, ou quand le verrou bascule. L'événement `load` est aussi
	// écouté pour ré-attacher si le `srcDoc` est réinjecté.
	useEffect( () => {
		if ( VIEW.RENDER !== view || ! payload ) {
			return undefined;
		}
		const beforeFrame = beforeScrollerRef.current;
		const afterFrame = afterScrollerRef.current;
		if (
			! ( beforeFrame instanceof HTMLIFrameElement ) ||
			! ( afterFrame instanceof HTMLIFrameElement )
		) {
			return undefined;
		}
		let detachers = [];
		const attach = () => {
			const beforeDoc = beforeFrame.contentDocument;
			const afterDoc = afterFrame.contentDocument;
			if ( ! beforeDoc || ! afterDoc ) {
				return;
			}
			const beforeScroller =
				beforeDoc.scrollingElement || beforeDoc.documentElement;
			const afterScroller =
				afterDoc.scrollingElement || afterDoc.documentElement;
			const onBefore = () => {
				if ( ! scrollSync || syncingRef.current ) {
					return;
				}
				syncFromTo( beforeScroller, afterScroller );
			};
			const onAfter = () => {
				if ( ! scrollSync || syncingRef.current ) {
					return;
				}
				syncFromTo( afterScroller, beforeScroller );
			};
			beforeDoc.addEventListener( 'scroll', onBefore );
			afterDoc.addEventListener( 'scroll', onAfter );
			detachers.push( () => {
				beforeDoc.removeEventListener( 'scroll', onBefore );
				afterDoc.removeEventListener( 'scroll', onAfter );
			} );
		};
		// `srcDoc` est généralement déjà chargé quand l'effet tourne, mais on
		// se câble aussi sur `load` pour couvrir les re-renders.
		attach();
		const onLoad = () => {
			detachers.forEach( ( fn ) => fn() );
			detachers = [];
			attach();
		};
		beforeFrame.addEventListener( 'load', onLoad );
		afterFrame.addEventListener( 'load', onLoad );
		return () => {
			detachers.forEach( ( fn ) => fn() );
			beforeFrame.removeEventListener( 'load', onLoad );
			afterFrame.removeEventListener( 'load', onLoad );
		};
	}, [ view, payload, scrollSync, syncFromTo ] );

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
				{ /* Layout 2 colonnes pour économiser de la hauteur verticale
				     et laisser le maximum de place à l'affichage du code en
				     dessous : table métriques à gauche, summary + view-toggle
				     empilés verticalement à droite (« même colonne » pour
				     reprendre la formulation de la demande). Sur écran étroit,
				     `flex-wrap` rebascule en empilement vertical. */ }
				<div className="htmln-diff-modal__metrics-row">
					<MetricsDiffTable
						before={ payload.metrics_before }
						after={ payload.metrics_after }
					/>

					<div className="htmln-diff-modal__metrics-aside">
						<MetricsDiffSummary
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
								variant={
									VIEW.CODE === view ? 'primary' : 'secondary'
								}
								onClick={ () => setView( VIEW.CODE ) }
							>
								{ __(
									'Code source',
									'100son-html-normalizer'
								) }
							</Button>{ ' ' }
							<Button
								variant={
									VIEW.RENDER === view
										? 'primary'
										: 'secondary'
								}
								onClick={ () => setView( VIEW.RENDER ) }
							>
								{ __( 'Rendu HTML', '100son-html-normalizer' ) }
							</Button>{ ' ' }
							<Button
								icon={ scrollSync ? lock : unlock }
								variant={ scrollSync ? 'primary' : 'secondary' }
								onClick={ () =>
									setScrollSync( ( prev ) => ! prev )
								}
								label={
									scrollSync
										? __(
												'Verrou de défilement activé — cliquer pour libérer les deux panneaux',
												'100son-html-normalizer'
										  )
										: __(
												'Verrouiller le défilement vertical des deux panneaux',
												'100son-html-normalizer'
										  )
								}
								showTooltip
								className="htmln-diff-modal__scroll-lock"
							/>{ ' ' }
							<Button
								icon={ brush }
								variant={
									showDiffMarks ? 'primary' : 'secondary'
								}
								onClick={ () =>
									setShowDiffMarks( ( prev ) => ! prev )
								}
								label={
									showDiffMarks
										? __(
												'Surlignage des suppressions/ajouts activé — cliquer pour désactiver',
												'100son-html-normalizer'
										  )
										: __(
												'Surligner les suppressions (jaune) et les ajouts (vert) dans la vue code source',
												'100son-html-normalizer'
										  )
								}
								showTooltip
								className="htmln-diff-modal__diff-marks-toggle"
							/>
						</div>
					</div>
				</div>

				{ VIEW.CODE === view && (
					<div className="htmln-diff-modal__pane htmln-diff-modal__pane--code">
						<div className="htmln-diff-modal__col htmln-diff-modal__col--before">
							<h3 className="htmln-diff-modal__col-title">
								{ __( 'Avant', '100son-html-normalizer' ) }
							</h3>
							<HighlightedCode
								ref={ beforeScrollerRef }
								onScroll={ handleBeforeScroll }
								code={ payload.html_before }
								diffAgainst={
									showDiffMarks ? payload.html_after : null
								}
								diffMode="removed"
							/>
						</div>
						<div className="htmln-diff-modal__col htmln-diff-modal__col--after">
							<h3 className="htmln-diff-modal__col-title">
								{ __( 'Après', '100son-html-normalizer' ) }
							</h3>
							<HighlightedCode
								ref={ afterScrollerRef }
								onScroll={ handleAfterScroll }
								code={ payload.html_after }
								diffAgainst={
									showDiffMarks ? payload.html_before : null
								}
								diffMode="added"
							/>
						</div>
					</div>
				) }

				{ VIEW.RENDER === view && (
					<div className="htmln-diff-modal__pane htmln-diff-modal__pane--render">
						<div className="htmln-diff-modal__col htmln-diff-modal__col--before">
							<h3 className="htmln-diff-modal__col-title">
								{ __( 'Avant', '100son-html-normalizer' ) }
							</h3>
							<iframe
								ref={ beforeScrollerRef }
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
						<div className="htmln-diff-modal__col htmln-diff-modal__col--after">
							<h3 className="htmln-diff-modal__col-title">
								{ __( 'Après', '100son-html-normalizer' ) }
							</h3>
							<iframe
								ref={ afterScrollerRef }
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

	// Titre composé : « Diff de l'article #ID — Titre de l'article »
	// (post-rc4 : on enrichit avec le titre pour aligner sur la page
	// V0.1 « Aperçu » qui le montrait déjà). Si le titre est absent
	// (cas dégradé), on retombe sur la version `#ID` seul.
	const modalTitle = postTitle
		? sprintf(
				// translators: 1 = identifiant article, 2 = titre de l'article.
				__(
					"Diff de l'article #%1$d — %2$s",
					'100son-html-normalizer'
				),
				postId,
				postTitle
		  )
		: sprintf(
				// translators: %d = identifiant article.
				__( "Diff de l'article #%d", '100son-html-normalizer' ),
				postId
		  );

	// Métadonnées injectées **à la suite du titre** dans le header de la
	// modale via `headerActions` (slot officiel de `@wordpress/components`
	// Modal, rendu entre le `<h1>` et le bouton de fermeture). On reste
	// sur la même ligne visuelle que le titre — le CSS du composant
	// (`.htmln-diff-modal .components-modal__header`) repasse le header
	// en `justify-content: flex-start` avec un gap, et pousse le bouton
	// X tout à droite via `margin-left: auto`.
	//
	// Tant que `payload` est null (fetch en cours / erreur), on omet le
	// bloc — pas de placeholder, pas de skeleton, le header reste sobre.
	const headerMeta = ( () => {
		if ( ! payload ) {
			return null;
		}
		const categoriesLabel = Array.isArray( payload.categories )
			? payload.categories.join( ', ' )
			: '';
		const formattedDate = formatPostDate( payload.post_date );
		const builderType = payload.builder_type ?? null;
		if (
			'' === categoriesLabel &&
			'' === formattedDate &&
			null === builderType
		) {
			return null;
		}
		return (
			<div className="htmln-diff-modal__header-meta">
				{ '' !== categoriesLabel && (
					<span className="htmln-diff-modal__header-meta-item">
						{ __( 'Cat. :', '100son-html-normalizer' ) }{ ' ' }
						{ categoriesLabel }
					</span>
				) }
				{ '' !== formattedDate && (
					<span className="htmln-diff-modal__header-meta-item">
						{ formattedDate }
					</span>
				) }
				{ null !== builderType && (
					<span className="htmln-diff-modal__header-meta-item">
						<BuilderBadge
							type={ builderType }
							hasFossilPanelsData={ Boolean(
								payload.has_fossil_panels_data
							) }
						/>
					</span>
				) }
			</div>
		);
	} )();

	return (
		<Modal
			title={ modalTitle }
			headerActions={ headerMeta }
			onRequestClose={ onClose }
			isFullScreen
			className="htmln-diff-modal"
		>
			{ renderBody() }
		</Modal>
	);
}
