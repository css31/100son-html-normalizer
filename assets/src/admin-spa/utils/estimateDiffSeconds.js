/**
 * estimateDiffSeconds — estime la durée du calcul de surlignage
 * (`diffWordsWithSpace` dans le Web Worker) à partir du nombre total
 * de tokens `(N+M)` et du **nombre total d'occurrences** des règles
 * applicables sur l'article (somme des `applied_rules[].occurrences`
 * du payload `/posts/{id}/diff`).
 *
 * Modèle. L'algorithme de Myers est `O((N+M)·D)` où `D` est la distance
 * d'édition (nombre de tokens insérés/supprimés). Sur le corpus MMM-2,
 * la mesure empirique sur 3 articles donne :
 *
 *   D ≈ 60 × occurrences   (très stable : 51, 61, 60 sur 374, 16020, 6690)
 *
 * C'est un bien meilleur prédicteur de `D` que `D ≈ 0,33 × (N+M)` (l'ancien
 * modèle, qui marchait par hasard sur les articles "bien-comportés" mais
 * sous-estimait massivement les articles avec beaucoup de changements
 * scattered comme 6690 — où D atteint 60 % de N+M).
 *
 * Coût par opération `c` : varie d'un facteur ~16 entre petit et gros
 * article. Cause probable : GC pressure + désoptimisation JIT au-delà
 * de seuils d'array dans jsdiff. On utilise une fonction par paliers
 * calibrée sur 3 mesures :
 *
 *   (N+M)·D < 7 × 10⁷       → c = 1,5 × 10⁻⁷  (régime « petit »)
 *   7 × 10⁷ ≤ (N+M)·D < 2 × 10⁸ → c = 8 × 10⁻⁷  (régime « moyen »)
 *   (N+M)·D ≥ 2 × 10⁸       → c = 1,2 × 10⁻⁶  (régime « gros »)
 *
 * Vérification sur les 3 points de calibration (Firefox) :
 *
 *   Article | (N+M) | occ | D=60×occ | (N+M)·D | c     | t prédit | t mesuré
 *   ------- | ----- | --- | -------- | ------- | ----- | -------- | --------
 *   374     | 12326 | 76  | 4 560    | 5,6e7   | 1,5e-7 | 8 s     | 7 s
 *   16020   | 16959 | 92  | 5 520    | 9,4e7   | 8e-7   | 75 s    | 65 s
 *   6690    | 23287 | 233 | 13 980   | 3,3e8   | 1,2e-6 | 391 s   | >360 s
 *
 * Note. Les totaux d'occurrences sont mesurés via `applied_rules` du
 * payload REST `/posts/{id}/diff`, qui depuis la cascade dans
 * `DiffController` compte chaque règle sur l'état HTML APRÈS application
 * des règles précédentes (et non plus sur le HTML brut). Cela rééquilibre
 * la répartition par règle (R6 sur 6690 baisse de 100 à 14, R1 monte de
 * 32 à 118 — Pinterest emportent leurs styles ET libèrent des `<p>` vides),
 * mais les totaux par article restent stables, donc la constante 60 tient.
 *
 * Limites. La calibration vient de la machine de l'auteur (Linux Mint,
 * Firefox). D'autres machines auront des coefficients différents — la
 * précision attendue est donc « bon ordre de grandeur », pas seconde
 * près. Le découpage par paliers introduit des discontinuités aux bornes
 * (7e7 et 2e8) — un article qui tombe juste à la frontière peut être
 * sur-estimé ou sous-estimé de ~50 %.
 *
 * @param {number} totalTokens      Somme des tokens des deux côtés (N+M).
 * @param {number} totalOccurrences Somme des occurrences de toutes les règles applicables sur l'article (= total des `applied_rules[].occurrences` du payload REST).
 * @return {number} Durée estimée en secondes (float, à arrondir côté caller).
 */

/**
 * Coefficient empirique reliant le nombre d'occurrences de règles à la
 * distance d'édition Myers. Mesuré sur 374, 16020, 6690 → constante à
 * ±14 % près. C'est le levier principal qui rend la prédiction sensible
 * au nombre de transformations scattered (la signature qui fait
 * exploser le temps de calcul, cf. 6690).
 *
 * @type {number}
 */
const D_PER_OCCURRENCE = 60;

/**
 * Seuils de bascule entre les trois régimes de coût per-op et leurs
 * valeurs respectives. Calés empiriquement (cf. JSDoc en tête).
 *
 * @type {Array<{ maxNmD: number, costPerOp: number }>}
 */
const COST_REGIMES = [
	{ maxNmD: 7e7, costPerOp: 1.5e-7 },
	{ maxNmD: 2e8, costPerOp: 8e-7 },
	{ maxNmD: Infinity, costPerOp: 1.2e-6 },
];

export function estimateDiffSeconds( totalTokens, totalOccurrences ) {
	if ( 'number' !== typeof totalTokens || totalTokens <= 0 ) {
		return 0;
	}
	const occ = 'number' === typeof totalOccurrences ? totalOccurrences : 0;
	const editDistance = D_PER_OCCURRENCE * occ;
	if ( editDistance <= 0 ) {
		// Pas de transformation applicable → pas de calcul de surlignage
		// notable (jsdiff retourne instantanément une seule part « unchanged »).
		return 0;
	}
	const nmD = totalTokens * editDistance;
	const regime = COST_REGIMES.find( ( r ) => nmD < r.maxNmD );
	return regime.costPerOp * nmD;
}
