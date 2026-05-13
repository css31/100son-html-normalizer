/**
 * MetricsDiffBar — bandeau métriques avant/après (F14.3).
 *
 * Réutilisé par DiffModal (preview à la volée) et RegressionModal
 * (article en régression). Affiche les 7 métriques γ avec leur delta
 * absolu + delta relatif (en %), un marqueur visuel quand la métrique
 * a perdu (delta < 0), et une **phrase résumé** en haut qui donne le
 * verdict en un coup d'œil (« ✓ Aucune perte » vs « ⚠ Perte de X % de
 * paragraphes »).
 *
 * Choix de design (post-rc4) :
 *  - Les `headings` (h1..h6) sont **sommés** en une seule ligne « Titres »
 *    pour réduire le bruit visuel (un article typique a h2 seul, les 4
 *    autres niveaux à 0 produisent du bruit). Le détail par niveau reste
 *    dans le payload REST si besoin futur.
 *  - Les lignes `before === 0 && after === 0` sont masquées (ex. pas de
 *    listes ni d'images dans cet article).
 *  - Le delta est formaté en `+N (+X.X %)` pour donner immédiatement le
 *    contexte relatif — « perdre 1 paragraphe sur 2 » ≠ « perdre 1 sur 100 ».
 *
 * Sémantique alignée sur `MetricsSnapshot` côté PHP (chars, words,
 * paragraphs, headings (h1..h6), images, links, lists).
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Formateur localisé pour les entiers (espaces fines comme séparateur
 * de milliers : « 4 532 »). Cache module-level pour éviter de recréer
 * l'instance à chaque render.
 *
 * @type {Intl.NumberFormat}
 */
const NUMBER_FORMAT = new Intl.NumberFormat( 'fr-FR' );

/**
 * Ordre canonique des lignes du tableau. La métrique `headings` est
 * un agrégat — son `getValue` somme les 6 niveaux. Toutes les autres
 * lisent directement le snapshot.
 *
 * @type {Array<{key: string, label: string}>}
 */
function getRowDefs() {
	return [
		{ key: 'chars', label: __( 'Caractères', '100son-html-normalizer' ) },
		{ key: 'words', label: __( 'Mots', '100son-html-normalizer' ) },
		{
			key: 'paragraphs',
			label: __( 'Paragraphes', '100son-html-normalizer' ),
		},
		{ key: 'headings', label: __( 'Titres', '100son-html-normalizer' ) },
		{ key: 'images', label: __( 'Images', '100son-html-normalizer' ) },
		{ key: 'links', label: __( 'Liens', '100son-html-normalizer' ) },
		{ key: 'lists', label: __( 'Listes', '100son-html-normalizer' ) },
	];
}

/**
 * Récupère la valeur d'une métrique dans un snapshot. La clé spéciale
 * `headings` somme h1..h6 (cf. choix de design en docblock).
 *
 * @param {?Object} snapshot MetricsSnapshot ou null.
 * @param {string}  key      Clé canonique de métrique.
 * @return {number} Valeur entière (0 si absente).
 */
function getValue( snapshot, key ) {
	if ( ! snapshot || 'object' !== typeof snapshot ) {
		return 0;
	}
	if ( 'headings' === key ) {
		const h = snapshot.headings;
		if ( ! h || 'object' !== typeof h ) {
			return 0;
		}
		return [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].reduce(
			( sum, lvl ) => sum + ( Number( h[ lvl ] ) || 0 ),
			0
		);
	}
	return Number( snapshot[ key ] ) || 0;
}

/**
 * @typedef {Object} RowData
 * @property {string}                  key    Clé canonique de métrique (ex. `chars`).
 * @property {string}                  label  Libellé localisé pour la cellule label.
 * @property {number}                  before Valeur de la métrique avant normalisation.
 * @property {number}                  after  Valeur de la métrique après normalisation.
 * @property {number}                  delta  Delta absolu (after − before).
 * @property {number}                  pct    Pourcentage relatif (positif = gain, négatif = perte).
 * @property {boolean}                 isNew  `before === 0 && after > 0` — le « % » n'a pas de sens, on n'affichera que `+N`.
 * @property {'gain'|'loss'|'neutral'} sev    Sévérité visuelle pour la classe CSS du delta.
 */

/**
 * Construit une ligne de tableau avec ses dérivées (delta, pct, sévérité).
 *
 * @param {string} key    Clé canonique de métrique.
 * @param {string} label  Libellé localisé.
 * @param {number} before Valeur avant.
 * @param {number} after  Valeur après.
 * @return {RowData} Ligne enrichie prête à être rendue.
 */
function buildRow( key, label, before, after ) {
	const delta = after - before;
	let sev = 'neutral';
	let pct = 0;
	let isNew = false;

	if ( delta > 0 ) {
		sev = 'gain';
		if ( before > 0 ) {
			pct = ( delta / before ) * 100;
		} else {
			isNew = true;
		}
	} else if ( delta < 0 ) {
		sev = 'loss';
		// Si `before === 0`, on ne peut pas avoir delta < 0 (after >= 0).
		// Donc before > 0 forcément ici — pas de division par zéro.
		pct = ( delta / before ) * 100;
	}

	return { key, label, before, after, delta, pct, sev, isNew };
}

/**
 * Formate le delta + pourcentage dans la 4e colonne.
 *
 *  - `delta === 0` → `—`
 *  - `isNew` (avant = 0) → `+N` seul (le `%` n'a pas de sens)
 *  - sinon → `+N (+X.X %)` ou `-N (-X.X %)` (`%%` = `%` littéral en sprintf)
 *
 * Précision : 1 décimale si |pct| < 10, sinon entier — équilibre lisibilité.
 *
 * @param {RowData} row Ligne avec ses dérivées (delta, pct, isNew).
 * @return {string} Chaîne formatée à afficher.
 */
function formatDelta( row ) {
	if ( 0 === row.delta ) {
		return '—';
	}
	const sign = row.delta > 0 ? '+' : '';
	const deltaStr = `${ sign }${ row.delta }`;
	if ( row.isNew ) {
		return deltaStr;
	}
	const absPct = Math.abs( row.pct );
	const pctStr =
		absPct >= 10 ? `${ Math.round( row.pct ) }` : row.pct.toFixed( 1 );
	const pctSign = row.pct > 0 ? '+' : '';
	return sprintf(
		// translators: 1 = delta absolu signé, 2 = pourcentage signé.
		__( '%1$s (%2$s%3$s %%)', '100son-html-normalizer' ),
		deltaStr,
		pctSign,
		pctStr
	);
}

/**
 * Construit le message « garde-fou » au-dessus du tableau.
 *
 * Stratégie :
 *  - Aucune ligne en perte → message vert « Aucune perte de contenu ».
 *  - Sinon → on prend la perte **la plus sévère en relatif** (|%| max),
 *    départage par |delta| absolu. C'est le risque principal à signaler
 *    à l'utilisateur avant qu'il ne valide l'application.
 *
 * @param {RowData[]} rows Lignes du tableau (déjà filtrées des zéros).
 * @return {{kind: 'ok'|'warning', message: string}} Verdict + texte localisé.
 */
function buildSummary( rows ) {
	const losses = rows.filter( ( r ) => r.delta < 0 );
	if ( 0 === losses.length ) {
		return {
			kind: 'ok',
			message: __(
				'✓ Aucune perte de contenu détectée.',
				'100son-html-normalizer'
			),
		};
	}

	const worst = losses.reduce( ( best, cur ) => {
		const bestAbs = Math.abs( best.pct );
		const curAbs = Math.abs( cur.pct );
		if ( curAbs > bestAbs ) {
			return cur;
		}
		if (
			curAbs === bestAbs &&
			Math.abs( cur.delta ) > Math.abs( best.delta )
		) {
			return cur;
		}
		return best;
	} );

	const absPct =
		Math.abs( worst.pct ) >= 10
			? Math.round( Math.abs( worst.pct ) )
			: Math.abs( worst.pct ).toFixed( 1 );
	const absDelta = Math.abs( worst.delta );

	return {
		kind: 'warning',
		message: sprintf(
			/* translators: 1 = pourcentage perdu, 2 = libellé métrique en minuscules, 3 = unités perdues, 4 = total avant. */
			__(
				'⚠ Perte de %1$s %% de %2$s (-%3$d sur %4$d).',
				'100son-html-normalizer'
			),
			absPct,
			worst.label.toLowerCase(),
			absDelta,
			worst.before
		),
	};
}

/**
 * Calcule les lignes du tableau métriques (rows non-nulles avant/après) pour
 * un couple de snapshots. Logique extraite du composant pour partager entre
 * les exports `MetricsDiffSummary` et `MetricsDiffTable` (qui peuvent être
 * rendus séparément côté caller, ex. layout 2 colonnes dans `DiffModal`).
 *
 * @param {?Object} before MetricsSnapshot avant ou null.
 * @param {?Object} after  MetricsSnapshot après ou null.
 * @return {RowData[]} Lignes prêtes à être rendues, dans l'ordre canonique.
 */
function computeRows( before, after ) {
	return getRowDefs()
		.map( ( { key, label } ) =>
			buildRow(
				key,
				label,
				getValue( before, key ),
				getValue( after, key )
			)
		)
		.filter( ( r ) => ! ( 0 === r.before && 0 === r.after ) );
}

/**
 * Phrase « garde-fou » au-dessus du tableau métriques, exportée séparément
 * pour qu'un caller (ex. `DiffModal`) puisse la placer dans une colonne
 * adjacente au tableau plutôt qu'au-dessus — gain de hauteur verticale qui
 * laisse plus d'espace à l'affichage du code source / rendu HTML.
 *
 * @param {Object}  props
 * @param {?Object} props.before MetricsSnapshot avant.
 * @param {?Object} props.after  MetricsSnapshot après.
 * @return {JSX.Element|null} `<p>` summary ou null si rien à montrer.
 */
export function MetricsDiffSummary( { before, after } ) {
	const rows = computeRows( before, after );
	if ( 0 === rows.length ) {
		return null;
	}
	const summary = buildSummary( rows );
	return (
		<p
			className={ `htmln-metrics-diff__summary htmln-metrics-diff__summary--${ summary.kind }` }
			role="status"
		>
			{ summary.message }
		</p>
	);
}

/**
 * Tableau des métriques avant/après seul (sans la phrase summary), exporté
 * pour la même raison que `MetricsDiffSummary` — composer un layout 2
 * colonnes côté caller.
 *
 * @param {Object}  props
 * @param {?Object} props.before MetricsSnapshot avant.
 * @param {?Object} props.after  MetricsSnapshot après.
 * @return {JSX.Element|null} `<table>` ou null si rien à montrer.
 */
export function MetricsDiffTable( { before, after } ) {
	const rows = computeRows( before, after );
	if ( 0 === rows.length ) {
		return null;
	}
	return (
		<table
			className="htmln-metrics-diff"
			aria-label={ __(
				'Métriques avant / après',
				'100son-html-normalizer'
			) }
		>
			<thead>
				<tr>
					<th scope="col">
						{ __( 'Métrique', '100son-html-normalizer' ) }
					</th>
					<th scope="col" className="htmln-metrics-diff__num">
						{ __( 'Avant', '100son-html-normalizer' ) }
					</th>
					<th scope="col" className="htmln-metrics-diff__num">
						{ __( 'Après', '100son-html-normalizer' ) }
					</th>
					<th scope="col" className="htmln-metrics-diff__num">
						{ __( 'Δ', '100son-html-normalizer' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.key }>
						<th scope="row">{ row.label }</th>
						<td className="htmln-metrics-diff__num">
							{ NUMBER_FORMAT.format( row.before ) }
						</td>
						<td className="htmln-metrics-diff__num">
							{ NUMBER_FORMAT.format( row.after ) }
						</td>
						<td
							className={ `htmln-metrics-diff__num htmln-metrics-diff__delta htmln-metrics-diff__delta--${ row.sev }` }
						>
							{ formatDelta( row ) }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Composé par défaut : summary au-dessus, tableau en-dessous, dans un wrapper.
 * Layout vertical historique conservé pour `RegressionModal` qui n'a pas
 * besoin de la disposition 2 colonnes.
 *
 * Pour le layout 2 colonnes (ex. `DiffModal` post-rc4), consommer
 * `MetricsDiffSummary` et `MetricsDiffTable` séparément.
 *
 * @param {Object}  props
 * @param {?Object} props.before MetricsSnapshot avant.
 * @param {?Object} props.after  MetricsSnapshot après.
 * @return {JSX.Element|null} Bandeau ou null si les snapshots sont vides.
 */
export default function MetricsDiffBar( { before, after } ) {
	const rows = computeRows( before, after );
	if ( 0 === rows.length ) {
		return null;
	}
	return (
		<div className="htmln-metrics-diff-wrap">
			<MetricsDiffSummary before={ before } after={ after } />
			<MetricsDiffTable before={ before } after={ after } />
		</div>
	);
}
