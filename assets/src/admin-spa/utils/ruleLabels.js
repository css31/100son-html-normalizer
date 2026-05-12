/**
 * Renommage d'affichage des rule_ids côté SPA.
 *
 * Les IDs internes (P1..P9) sont **stables côté code et base de
 * données** — DiagnosticRecord, son100_htmln_diagnostics.matching_rules,
 * son100_htmln_steps.applied_rules, l'option son100_htmln_presets et
 * les routes REST utilisent tous ces IDs bruts. Ne JAMAIS modifier
 * ces IDs (migration data coûteuse + perte de traçabilité historique).
 *
 * Cette table fait juste le mapping `ID interne → label affiché` que
 * la SPA applique dans toutes les cellules où un rule_id apparaît
 * (onglet Règles, colonne Règles applicables du tableau Normaliser,
 * footer de sélection, drawer d'historique, modales Diff/Régression).
 *
 * Choix éditoriaux :
 *  - `P2` (titres vides) → **P2.1** car P9 (titres autour d'images)
 *    couvre une famille adjacente sur les `<hN>` — regrouper sous P2.x
 *    rend l'arbre des règles plus lisible.
 *  - `P9` → **P2.2** : déplacé directement après P2.1 dans l'ordre
 *    d'affichage (cf. `RULE_DISPLAY_ORDER`).
 *
 * L'ordre d'EXÉCUTION du pipeline (cf. `PresetRegistry::PRESETS` côté
 * PHP) reste P3 → P4 → P8 → P6 → P7 → P5 → P9 → P1 → P2. C'est l'ordre
 * d'affichage UI qui change, pas l'ordre d'exécution.
 */

/**
 * Map ID interne → label affiché.
 *
 * Tout ID absent retombe sur lui-même via `getRuleLabel`.
 *
 * @type {Object<string, string>}
 */
export const RULE_DISPLAY_LABELS = {
	P1: 'P1',
	P2: 'P2.1',
	P3: 'P3',
	P4: 'P4',
	P5: 'P5',
	P6: 'P6',
	P7: 'P7',
	P8: 'P8',
	P9: 'P2.2',
};

/**
 * Map ID interne → ordre d'affichage (entier, ASC). Détermine la
 * position dans la liste des cards Règles + dans toutes les listes
 * triées par display order.
 *
 * Ordre choisi : P1, P2(=P2.1), P9(=P2.2), P3, P4, P5, P6, P7, P8.
 * Les ressemblances P2.1/P2.2 sont contiguës ; les autres restent
 * dans l'ordre naturel.
 *
 * @type {Object<string, number>}
 */
export const RULE_DISPLAY_ORDER = {
	P1: 1,
	P2: 2,
	P9: 3,
	P3: 4,
	P4: 5,
	P5: 6,
	P6: 7,
	P7: 8,
	P8: 9,
};

/**
 * Retourne le label à afficher pour un rule_id donné.
 *
 * @param {string} id ID interne (ex. `P9`).
 * @return {string} Label (ex. `P2.2`), ou `id` lui-même si inconnu.
 */
export function getRuleLabel( id ) {
	const str = String( id ?? '' );
	return RULE_DISPLAY_LABELS[ str ] ?? str;
}

/**
 * Retourne le rang d'affichage pour un rule_id donné. IDs inconnus
 * trient en fin via la sentinelle `Infinity` (tous les inconnus en
 * fin, ordre indéterminé entre eux).
 *
 * @param {string} id ID interne.
 * @return {number} Rang ASC.
 */
export function getRuleOrder( id ) {
	const str = String( id ?? '' );
	return RULE_DISPLAY_ORDER[ str ] ?? Infinity;
}

/**
 * Comparator prêt à l'emploi pour `Array.prototype.sort` quand on
 * trie une liste de strings d'IDs.
 *
 * @param {string} a ID a.
 * @param {string} b ID b.
 * @return {number} Négatif si a < b, positif si a > b.
 */
export function compareRuleIdsByDisplayOrder( a, b ) {
	return getRuleOrder( a ) - getRuleOrder( b );
}

/**
 * Formate une liste de rule_ids (ou d'objets `{rule_id}`) en chaîne
 * lisible triée selon l'ordre d'affichage, avec labels mappés.
 * Ex. `[{rule_id:'P9'}, {rule_id:'P1'}]` → `'P1, P2.2'`.
 *
 * @param {Array<string|{rule_id?: string}>} entries Liste mixte d'IDs ou d'objets matching_rules.
 * @return {string} Chaîne formatée ou `—` si la liste est vide.
 */
export function formatRuleIdList( entries ) {
	if ( ! Array.isArray( entries ) || 0 === entries.length ) {
		return '—';
	}
	const ids = entries
		.map( ( entry ) =>
			'string' === typeof entry ? entry : String( entry?.rule_id ?? '' )
		)
		.filter( ( id ) => '' !== id )
		.sort( compareRuleIdsByDisplayOrder );
	if ( 0 === ids.length ) {
		return '—';
	}
	return ids.map( getRuleLabel ).join( ', ' );
}
