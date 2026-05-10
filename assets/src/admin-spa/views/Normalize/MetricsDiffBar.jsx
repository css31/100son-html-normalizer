/**
 * MetricsDiffBar — bandeau métriques avant/après (F14.3).
 *
 * Réutilisé par DiffModal (preview à la volée) et RegressionModal
 * (article en régression). Affiche les 7 métriques γ avec leur
 * delta et un marqueur visuel quand la métrique a perdu (delta < 0).
 *
 * Sémantique aligned sur `MetricsSnapshot` côté PHP (chars, words,
 * paragraphs, headings (h1..h6), images, links, lists). Les
 * `headings` sont éclatés en 6 lignes h1..h6 pour visibilité fine.
 */

import { __ } from '@wordpress/i18n';

/**
 * Description en français des clés de métriques. Sert au libellé
 * de chaque ligne du tableau.
 *
 * @return {Object<string, string>} Map clé → libellé.
 */
function getLabels() {
	return {
		chars: __( 'Caractères', '100son-html-normalizer' ),
		words: __( 'Mots', '100son-html-normalizer' ),
		paragraphs: __( 'Paragraphes', '100son-html-normalizer' ),
		images: __( 'Images', '100son-html-normalizer' ),
		links: __( 'Liens', '100son-html-normalizer' ),
		lists: __( 'Listes', '100son-html-normalizer' ),
	};
}

/**
 * Aplatit un MetricsSnapshot en `{key: number}` pour comparaison
 * homogène. `headings` est éclaté en `headings.h1`, `headings.h2`, …
 *
 * @param {?Object} snapshot MetricsSnapshot ou null.
 * @return {Object<string, number>} Métriques aplaties.
 */
function flatten( snapshot ) {
	if ( ! snapshot || 'object' !== typeof snapshot ) {
		return {};
	}
	const out = {};
	[ 'chars', 'words', 'paragraphs', 'images', 'links', 'lists' ].forEach(
		( key ) => {
			out[ key ] = Number( snapshot[ key ] ) || 0;
		}
	);
	if ( snapshot.headings && 'object' === typeof snapshot.headings ) {
		[ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ].forEach( ( level ) => {
			out[ `headings.${ level }` ] =
				Number( snapshot.headings[ level ] ) || 0;
		} );
	}
	return out;
}

/**
 * @param {Object}  props
 * @param {?Object} props.before MetricsSnapshot avant.
 * @param {?Object} props.after  MetricsSnapshot après.
 * @return {JSX.Element} Tableau des deltas.
 */
export default function MetricsDiffBar( { before, after } ) {
	const flatBefore = flatten( before );
	const flatAfter = flatten( after );
	const labels = getLabels();

	const orderedKeys = [
		'chars',
		'words',
		'paragraphs',
		'headings.h1',
		'headings.h2',
		'headings.h3',
		'headings.h4',
		'headings.h5',
		'headings.h6',
		'images',
		'links',
		'lists',
	];

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
					<th scope="col">
						{ __( 'Avant', '100son-html-normalizer' ) }
					</th>
					<th scope="col">
						{ __( 'Après', '100son-html-normalizer' ) }
					</th>
					<th scope="col">
						{ __( 'Delta', '100son-html-normalizer' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ orderedKeys.map( ( key ) => {
					const labelKey = key.startsWith( 'headings.' )
						? `${ __( 'Titres', '100son-html-normalizer' ) } ${ key
								.split( '.' )[ 1 ]
								.toUpperCase() }`
						: labels[ key ] ?? key;
					const valBefore = flatBefore[ key ] ?? 0;
					const valAfter = flatAfter[ key ] ?? 0;
					const delta = valAfter - valBefore;
					let deltaClass = 'htmln-metrics-diff__delta--neutral';
					if ( delta < 0 ) {
						deltaClass = 'htmln-metrics-diff__delta--loss';
					} else if ( delta > 0 ) {
						deltaClass = 'htmln-metrics-diff__delta--gain';
					}
					return (
						<tr key={ key }>
							<th scope="row">{ labelKey }</th>
							<td>{ valBefore }</td>
							<td>{ valAfter }</td>
							<td className={ deltaClass }>
								{ delta > 0 ? `+${ delta }` : String( delta ) }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}
