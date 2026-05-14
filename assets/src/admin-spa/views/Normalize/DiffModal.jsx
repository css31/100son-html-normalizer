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
import {
	useEffect,
	useState,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import { Modal, Button, Spinner } from '@wordpress/components';
import { lock, unlock, brush } from '@wordpress/icons';
import * as api from '../../api';
import { sanitizeForIframe } from '../../utils/sanitizeForIframe';
import {
	getRuleLabel,
	getRuleTooltip,
	compareRuleIdsByDisplayOrder,
} from '../../utils/ruleLabels';
import { MetricsDiffSummary, MetricsDiffTable } from './MetricsDiffBar';
import BuilderBadge from './BuilderBadge';
import HighlightedCode from './HighlightedCode';
import { PRISM_MAX_CHARS } from '../../utils/highlightHtml';
import { useDiffHighlighting } from '../../hooks/useDiffHighlighting';
import { useElapsedTime } from '../../hooks/useElapsedTime';
import { countDiffTokens } from '../../utils/countDiffTokens';
import { estimateDiffSeconds } from '../../utils/estimateDiffSeconds';

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
 * @property {string}                                        html_before              HTML avant normalisation.
 * @property {string}                                        html_after               HTML après normalisation.
 * @property {Object}                                        metrics_before           Snapshot avant.
 * @property {Object}                                        metrics_after            Snapshot après.
 * @property {string[]}                                      warnings                 Avertissements éventuels du Pipeline.
 * @property {boolean}                                       unchanged                Vrai si HTML identique.
 * @property {string}                                        [post_date]              Date de publication (`Y-m-d H:i:s`, vide si absente).
 * @property {string[]}                                      [categories]             Noms de catégories de l'article.
 * @property {?string}                                       [builder_type]           Type de constructeur (siteorigin / gutenberg / …) ou null.
 * @property {boolean}                                       [has_fossil_panels_data] Vrai si Gutenberg avec vestige `panels_data` (alimente le badge orange).
 * @property {Array<{rule_id: string, occurrences: number}>} [applied_rules]          Sous-ensemble des règles sélectionnées qui ont matché sur `html_before` (countMatches > 0), ordre canonique du pipeline.
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
	// HTML). **Activé par défaut** : c'est l'usage dominant de la modale
	// Diff (comparer deux passages alignés). L'utilisateur peut le
	// désactiver via le bouton cadenas si jamais il veut scroller un
	// panneau indépendamment.
	const [ scrollSync, setScrollSync ] = useState( true );

	// Toggle « surlignage stabylo » des suppressions/ajouts dans la vue
	// Code source. **Désactivé par défaut** — sur les articles SiteOrigin
	// les plus lourds, le calcul `diffWordsWithSpace` (Myers) peut prendre
	// jusqu'à une minute même déporté dans un Web Worker (cf. test sur
	// l'article #16020 du corpus MMM-2). En partant OFF, la modale ouvre
	// systématiquement avec une coloration Prism instantanée ; l'utilisateur
	// active explicitement le surlignage quand il en a besoin, et un
	// avertissement avec l'estimation du temps de calcul (cf.
	// `utils/estimateDiffSeconds.js`) s'affiche à côté du bouton pinceau
	// quand cette estimation dépasse 5 s.
	//
	// Sous le capot : toggle ON → `useDiffHighlighting` (Web Worker async),
	// qui produit du HTML **combinant** coloration Prism et `<mark>` diff
	// (les deux visibles simultanément, cf. `workers/mergePrismAndDiff.js`).
	// Toggle OFF → `highlightHtml` (Prism seul, ou escape brut au-dessus
	// de `PRISM_MAX_CHARS`).
	const [ showDiffMarks, setShowDiffMarks ] = useState( false );

	// Comptage des tokens et estimation de la durée du calcul de
	// surlignage. Les tokens sont utilisés à la fois comme prédicteur
	// du temps (cf. `utils/estimateDiffSeconds.js`) et comme row dans
	// le tableau métriques (informatif pour l'utilisateur). Mémoïsé sur
	// `payload` pour ne pas re-tokeniser à chaque render — coût O(N)
	// d'environ 5 ms par côté sur 28k chars.
	const tokenCounts = useMemo( () => {
		if ( ! payload ) {
			return { before: 0, after: 0 };
		}
		return {
			before: countDiffTokens( payload.html_before ),
			after: countDiffTokens( payload.html_after ),
		};
	}, [ payload ] );

	// Total des occurrences de règles applicables sur cet article —
	// sert au modèle de prédiction du temps de calcul. La mesure
	// empirique sur le corpus MMM-2 montre que la distance d'édition
	// Myers est très bien corrélée à ce total (`D ≈ 60 × occurrences`,
	// ±14 % sur 374, 16020, 6690 — cf. JSDoc d'`estimateDiffSeconds`).
	// `applied_rules` est null/absent si rien à appliquer → 0.
	const totalOccurrences = useMemo( () => {
		if ( ! payload || ! Array.isArray( payload.applied_rules ) ) {
			return 0;
		}
		return payload.applied_rules.reduce(
			( sum, entry ) => sum + ( Number( entry?.occurrences ) || 0 ),
			0
		);
	}, [ payload ] );

	// Estimation du temps de calcul du Web Worker en secondes, basée
	// sur la formule `c × (N+M) × D` où `D ≈ 60 × occurrences` et `c`
	// dépend du régime de taille (cf. JSDoc d'`estimateDiffSeconds`).
	// Sert à :
	//  1. Décider si on affiche l'avertissement « surlignage long »
	//     à côté du bouton pinceau (seuil 5 s en dessous duquel
	//     l'attente est imperceptible).
	//  2. Personnaliser le texte de l'avertissement avec la valeur
	//     estimée concrète au lieu d'une fourchette générique.
	const estimatedDiffSeconds = estimateDiffSeconds(
		tokenCounts.before + tokenCounts.after,
		totalOccurrences
	);
	const isLongDiffExpected = estimatedDiffSeconds >= 5;

	// On déporte **systématiquement** le calcul du surlignage dans le
	// Web Worker dès que le toggle est ON, peu importe la taille de
	// l'article. Sur des articles moyens (cf. #374, ~5 500 tokens),
	// la voie sync `diffWordsWithSpace` peut freezer ~7 s — inacceptable
	// même sur un article moyen. Le surcoût d'instancier un Worker
	// (~50 ms de startup + bundle déjà en cache après le premier usage)
	// est invisible à côté de cette latence. Le hook ne fait rien tant
	// que `enabled` est `false`.
	const workerEnabled = Boolean( payload ) && showDiffMarks;
	const {
		removedHtml: workerRemovedHtml,
		addedHtml: workerAddedHtml,
		isComputing: workerComputing,
		error: workerError,
	} = useDiffHighlighting(
		payload?.html_before ?? '',
		payload?.html_after ?? '',
		workerEnabled
	);

	// Chronomètre purement informatif : compte les secondes pendant le
	// calcul du worker, et fige la durée totale dans `lastDurationSeconds`
	// quand le calcul se termine. Affiché à côté du spinner / message
	// « calcul terminé » dans la toolbar (cf. plus bas, bloc __worker-status).
	const { elapsedSeconds, lastDurationSeconds } =
		useElapsedTime( workerComputing );

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
					{ /* Colonne 1 (gauche) : tableau métriques + résumé +
					     boutons. Wrapper flex pour conserver le comportement
					     d'origine (table à gauche, aside à droite, wrap si
					     écran étroit). Occupe naturellement la 1ʳᵉ cellule
					     de la grille parente 1fr/1fr.

					     On enrichit les snapshots avec `html_chars` (longueur
					     brute en caractères des deux chaînes HTML) pour
					     ajouter une row supplémentaire au tableau métriques.
					     Calcul O(1) (le `.length` JS est caché par le moteur)
					     — aucun impact perceptible sur le rendu. Cette clé
					     n'existe que dans le contexte `DiffModal` ; pour
					     `RegressionModal` qui n'a pas accès aux chaînes brutes,
					     elle reste absente et `computeRows` filtre la row. */ }
					<div className="htmln-diff-modal__metrics-row-left">
						<MetricsDiffTable
							before={ {
								...payload.metrics_before,
								html_chars: payload.html_before.length,
							} }
							after={ {
								...payload.metrics_after,
								html_chars: payload.html_after.length,
							} }
						/>

						<div className="htmln-diff-modal__metrics-aside">
							<MetricsDiffSummary
								before={ {
									...payload.metrics_before,
									html_chars: payload.html_before.length,
								} }
								after={ {
									...payload.metrics_after,
									html_chars: payload.html_after.length,
								} }
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
								{ /* Rangée 1 (au-dessus) : contrôles du surlignage
							     diff — bouton pinceau + avertissement pré-clic
							     pour les articles longs + chrono pendant le
							     calcul + message « calculé en N s » après.
							     Tous sémantiquement liés au calcul. */ }
								<div className="htmln-diff-modal__view-toggle-row">
									<Button
										icon={ brush }
										variant={
											showDiffMarks
												? 'primary'
												: 'secondary'
										}
										onClick={ () =>
											setShowDiffMarks(
												( prev ) => ! prev
											)
										}
										label={
											showDiffMarks
												? __(
														'Surlignage activé (avec coloration syntaxique conservée) — cliquer pour désactiver',
														'100son-html-normalizer'
												  )
												: __(
														'Surligner les suppressions (jaune) et les ajouts (vert) par-dessus la coloration syntaxique',
														'100son-html-normalizer'
												  )
										}
										showTooltip
										className="htmln-diff-modal__diff-marks-toggle"
									/>
									{ /* Avertissement pré-clic : visible quand
								     l'article est volumineux ET que le surlignage
								     n'a pas encore été activé. */ }
									{ payload &&
										isLongDiffExpected &&
										! showDiffMarks && (
											<span
												className="htmln-diff-modal__worker-status htmln-diff-modal__worker-status--warning"
												role="note"
											>
												<span aria-hidden="true">
													⚠
												</span>
												<span className="htmln-diff-modal__worker-status-label">
													{ sprintf(
														// translators: %d = secondes estimées pour le calcul du surlignage.
														__(
															'Surlignage estimé : ~%d s sur cet article.',
															'100son-html-normalizer'
														),
														Math.round(
															estimatedDiffSeconds
														)
													) }
												</span>
											</span>
										) }
									{ /* Calcul Worker en cours : spinner + chrono /
									     estimation. Affiche `elapsed / estimated s`
									     pour donner un repère de progression à
									     l'utilisateur pendant que le worker tourne.
									     Si l'estimation est nulle (article sans
									     règle applicable), on retombe sur le chrono
									     seul (cas pratique très rare puisque le
									     toggle pinceau ne déclenche un calcul que
									     s'il y a quelque chose à montrer). */ }
									{ workerComputing && (
										<span
											className="htmln-diff-modal__worker-status"
											role="status"
											aria-live="polite"
										>
											<Spinner />
											<span className="htmln-diff-modal__worker-status-label">
												{ estimatedDiffSeconds > 0
													? sprintf(
															// translators: 1 = secondes écoulées, 2 = secondes estimées au total.
															__(
																'Calcul du surlignage en cours… %1$d / %2$d s',
																'100son-html-normalizer'
															),
															elapsedSeconds,
															Math.round(
																estimatedDiffSeconds
															)
													  )
													: sprintf(
															// translators: %d = secondes écoulées depuis le début du calcul.
															__(
																'Calcul du surlignage en cours… %d s',
																'100son-html-normalizer'
															),
															elapsedSeconds
													  ) }
											</span>
										</span>
									) }
									{ /* Calcul terminé : ✓ + durée totale, persistant. */ }
									{ ! workerComputing &&
										null !== lastDurationSeconds &&
										null !== workerRemovedHtml && (
											<span
												className="htmln-diff-modal__worker-status htmln-diff-modal__worker-status--done"
												role="status"
											>
												<span aria-hidden="true">
													✓
												</span>
												<span className="htmln-diff-modal__worker-status-label">
													{ sprintf(
														// translators: %d = durée totale du calcul en secondes.
														__(
															'Surlignage calculé en %d s',
															'100son-html-normalizer'
														),
														lastDurationSeconds
													) }
												</span>
											</span>
										) }
								</div>
								{ /* Rangée 2 (en dessous) : contrôles de vue —
							     Code source / Rendu HTML + verrou de scroll. */ }
								<div className="htmln-diff-modal__view-toggle-row">
									<Button
										variant={
											VIEW.CODE === view
												? 'primary'
												: 'secondary'
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
										{ __(
											'Rendu HTML',
											'100son-html-normalizer'
										) }
									</Button>{ ' ' }
									<Button
										icon={ scrollSync ? lock : unlock }
										variant={
											scrollSync ? 'primary' : 'secondary'
										}
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
									/>
								</div>
							</div>
						</div>
					</div>

					{ /* Colonne 2 (droite) : tableau « Règles appliquées »
					     — liste les `applied_rules` du payload (rule_id
					     dont countMatches > 0). Placé en 2ᵉ enfant de la
					     grille parente `__metrics-row` (1fr/1fr identique
					     à `__pane-cols` en dessous), donc son bord gauche
					     s'aligne précisément sur celui du pane « Après ».
					     `align-items: start` sur la grille parente le
					     colle en haut. Trié par ordre d'affichage UI
					     (P1.1, P1.2, P2.1, P2.2, P3…). */ }
					{ Array.isArray( payload.applied_rules ) &&
						payload.applied_rules.length > 0 && (
							<div className="htmln-diff-modal__metrics-rules">
								<h3 className="htmln-diff-modal__metrics-rules-title">
									{ __(
										'Règles appliquées',
										'100son-html-normalizer'
									) }
								</h3>
								<table className="htmln-diff-modal__metrics-rules-table">
									<tbody>
										{ [ ...payload.applied_rules ]
											.sort( ( a, b ) =>
												compareRuleIdsByDisplayOrder(
													a.rule_id,
													b.rule_id
												)
											)
											.map( ( entry ) => (
												<tr key={ entry.rule_id }>
													<th scope="row">
														{ getRuleLabel(
															entry.rule_id
														) }
													</th>
													<td>
														{ getRuleTooltip(
															entry.rule_id
														) }
													</td>
													<td
														className="htmln-diff-modal__metrics-rules-occ"
														title={ __(
															'Occurrences',
															'100son-html-normalizer'
														) }
													>
														{ Number(
															entry.occurrences
														) || 0 }
													</td>
												</tr>
											) ) }
									</tbody>
								</table>
							</div>
						) }
				</div>

				{ VIEW.CODE === view && (
					<div className="htmln-diff-modal__pane htmln-diff-modal__pane--code">
						{ /* Bandeau d'info pour les articles volumineux. Trois
						     cas possibles :
						       - workerError : le Worker n'a pas pu démarrer
						         ou a planté → on tombe sur le fallback
						         « texte brut sans marks », et on prévient ;
						       - workerComputing : déjà signalé via le
						         spinner dans la toolbar, pas de Notice ici
						         pour ne pas alourdir ;
						       - !showDiffMarks + html > PRISM_MAX_CHARS :
						         Prism désactivé pour cause de taille. */ }
						{ workerEnabled && workerError && (
							<div className="notice notice-warning">
								<p>
									{ __(
										'Le calcul du surlignage a échoué. Le contenu est affiché sans marques. Détail technique :',
										'100son-html-normalizer'
									) }{ ' ' }
									<code>{ String( workerError ) }</code>
								</p>
							</div>
						) }
						{ ! showDiffMarks &&
							Math.max(
								payload.html_before.length,
								payload.html_after.length
							) > PRISM_MAX_CHARS && (
								<div className="notice notice-info">
									<p>
										{ __(
											'Article volumineux : la coloration syntaxique est désactivée pour préserver la réactivité du navigateur. Le contenu reste affiché en texte brut.',
											'100son-html-normalizer'
										) }
									</p>
								</div>
							) }
						<div className="htmln-diff-modal__pane-cols">
							<div className="htmln-diff-modal__col htmln-diff-modal__col--before">
								<h3 className="htmln-diff-modal__col-title">
									{ __( 'Avant', '100son-html-normalizer' ) }
								</h3>
								<HighlightedCode
									ref={ beforeScrollerRef }
									onScroll={ handleBeforeScroll }
									code={ payload.html_before }
									diffMode="removed"
									precomputedHtml={
										workerEnabled &&
										'string' === typeof workerRemovedHtml
											? workerRemovedHtml
											: null
									}
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
									diffMode="added"
									precomputedHtml={
										workerEnabled &&
										'string' === typeof workerAddedHtml
											? workerAddedHtml
											: null
									}
								/>
							</div>
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
