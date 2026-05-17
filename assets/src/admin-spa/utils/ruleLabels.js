/**
 * Labels, tooltips et ordre d'affichage des rule_ids côté SPA.
 *
 * Les IDs internes (R1..R14) sont **stables côté code et base de
 * données** — DiagnosticRecord, son100_htmln_diagnostics.matching_rules,
 * son100_htmln_steps.applied_rules, l'option son100_htmln_presets et
 * les routes REST utilisent tous ces IDs bruts. Ne JAMAIS modifier
 * ces IDs (migration data coûteuse + perte de traçabilité historique).
 *
 * **Régime actuel** (post 2026-05-15) : l'affichage reproduit l'ID
 * interne tel quel. Plus de remapping famille (`R1.1`, `R1.2`, `R2.1`,
 * `R2.2`) qui avait été introduit aux versions rc2/rc3 puis retiré
 * faute de bénéfice tangible — la cohabitation `R1.1` côté SPA vs `R1`
 * partout ailleurs (README, CHANGELOG, tests PHP, PresetRegistry,
 * BDD, REST) créait une charge cognitive sans contrepartie.
 *
 * `RULE_DISPLAY_LABELS` est conservé vide pour préserver l'API
 * publique (`getRuleLabel`) au cas où un futur besoin justifierait
 * un remapping ponctuel.
 *
 * L'ordre d'EXÉCUTION du pipeline (cf. `PresetRegistry::PRESETS` côté
 * PHP) reste `R3 → R4 → R8 → R13 → R14 → R6 → R7 → R5 → R9 → R12 → R11 → R10 → R1 → R2`.
 * `RULE_DISPLAY_ORDER` ci-dessous gouverne l'affichage UI uniquement
 * (ordre naturel R1..R14).
 */

/**
 * Map ID interne → label affiché.
 *
 * Vide par défaut : `getRuleLabel('R9')` retourne `'R9'`. Cette
 * indirection est préservée pour permettre un remapping futur sans
 * toucher aux composants consommateurs.
 *
 * @type {Object<string, string>}
 */
export const RULE_DISPLAY_LABELS = {};

/**
 * Map ID interne → titre humain court (plain text, pour `title=`
 * attribute des `<span>` qui rendent les rule_ids dans le tableau
 * Normaliser et ailleurs).
 *
 * Aligné sur les labels de `PresetRegistry::get_all_presets_metadata()`
 * côté PHP — en plain text (pas de balises HTML : un `title=` ne
 * supporte que du texte).
 *
 * @type {Object<string, string>}
 */
export const RULE_TOOLTIPS = {
	R1: 'Paragraphes vides',
	R2: 'Titres vides',
	R3: 'Shortcodes Shareaholic',
	R4: 'Artefacts Pinterest',
	R5: '<br> excessifs',
	R6: 'Styles inline',
	R7: 'Listes ASCII',
	R8: 'Récupération sémantique des styles',
	R9: "Titres autour d'images",
	R10: "Paragraphes autour d'images",
	R11: 'Disposition des h4 (légende / crédit / gras)',
	R12: 'Titres mixtes image + légende',
	R13: 'Promotion h2-chapô',
	R14: 'Marquage chapô (1er p + crédits)',
	R15: 'Fusion balises inline en double',
	R16: 'Préfixes de titre (numéros, puces)',
	R17: 'Promotion h3 → h2 (cascade sans h2)',
};

/**
 * Map ID interne → ordre d'affichage (entier, ASC). Détermine la
 * position dans la liste des cards Règles + dans toutes les listes
 * triées par display order.
 *
 * Ordre naturel R1..R14.
 *
 * @type {Object<string, number>}
 */
export const RULE_DISPLAY_ORDER = {
	R1: 1,
	R2: 2,
	R3: 3,
	R4: 4,
	R5: 5,
	R6: 6,
	R7: 7,
	R8: 8,
	R9: 9,
	R10: 10,
	R11: 11,
	R12: 12,
	R13: 13,
	R14: 14,
	R15: 15,
	R16: 16,
	R17: 17,
};

/**
 * Retourne le label à afficher pour un rule_id donné.
 *
 * `RULE_DISPLAY_LABELS` est vide par défaut : retombe sur l'ID lui-même.
 *
 * @param {string} id ID interne (ex. `R9`).
 * @return {string} Label (par défaut identique à `id`).
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
 * Retourne le titre humain à utiliser dans le tooltip d'une cellule
 * rendant un rule_id. Retombe sur l'ID lui-même si inconnu (filet
 * pour custom rules futures).
 *
 * @param {string} id ID interne.
 * @return {string} Titre court plain text.
 */
export function getRuleTooltip( id ) {
	const str = String( id ?? '' );
	return RULE_TOOLTIPS[ str ] ?? str;
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
 * Ex. `[{rule_id:'R9'}, {rule_id:'R1'}]` → `'R1, R9'`.
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
