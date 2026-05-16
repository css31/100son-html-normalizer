/**
 * Helpers de parsing/formatage des dates manipulées par la SPA.
 *
 * **Contexte timezone** : tous les datetimes persistés en BDD via le plugin
 * (colonnes `diagnosed_at`, `started_at`, `finished_at`, etc.) sont écrits
 * par PHP avec `gmdate( 'Y-m-d H:i:s' )` — donc en UTC, sans suffixe de
 * timezone. La string brute ressemble à `2026-05-16 14:30:00`.
 *
 * Problème : `new Date( '2026-05-16 14:30:00' )` interprète la chaîne comme
 * **heure locale** côté navigateur. Sur un poste en Europe/Paris (CEST,
 * UTC+2 en été), ça produit un Date objet décalé de −2 h par rapport au
 * vrai instant UTC. Affiché tel quel, l'utilisateur voit « 14:30 » alors
 * que le scan a vraiment tourné à « 16:30 » locale.
 *
 * Fix : convertir la string MySQL UTC en ISO 8601 explicite avec suffixe
 * `Z`, ce qui force JS à parser en UTC. `.toLocaleString()` re-formate
 * alors correctement en heure locale du navigateur.
 *
 * Pour les dates **déjà en heure locale** (typiquement `post_date` de
 * `wp_posts`, écrit par WP avec le `wp_timezone()` du site), utiliser
 * `parseLocalDatetime()` qui n'ajoute pas le `Z`.
 */

/**
 * Parse une string MySQL DATETIME stockée en UTC (`gmdate()`) en objet
 * `Date` correct. Retourne `null` si l'entrée est vide ou invalide.
 *
 * @param {?string} sqlStr Format `'Y-m-d H:i:s'` UTC, ou `null` / `''`.
 * @return {?Date} Date parsée ou null.
 */
export function parseUtcDatetime( sqlStr ) {
	if ( ! sqlStr || typeof sqlStr !== 'string' ) {
		return null;
	}
	const date = new Date( sqlStr.replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( date.getTime() ) ? null : date;
}

/**
 * Parse une string MySQL DATETIME stockée en **heure locale du site**
 * (sans timezone) en objet `Date`. Utilisé pour `wp_posts.post_date` qui
 * est écrit par WP au fuseau du site via `wp_date()` ou équivalent.
 *
 * Note : le résultat est interprété comme heure locale **du navigateur**,
 * pas du site. Si le visiteur charge la SPA depuis un autre fuseau, il y
 * aura un décalage. Pour la cible admin (le webmaster est toujours au
 * fuseau du site), c'est acceptable.
 *
 * @param {?string} sqlStr Format `'Y-m-d H:i:s'` local, ou `null` / `''`.
 * @return {?Date} Date parsée ou null.
 */
export function parseLocalDatetime( sqlStr ) {
	if ( ! sqlStr || typeof sqlStr !== 'string' ) {
		return null;
	}
	const date = new Date( sqlStr.replace( ' ', 'T' ) );
	return Number.isNaN( date.getTime() ) ? null : date;
}

/**
 * Formate une string MySQL UTC en date+heure locales lisibles. Combine
 * `parseUtcDatetime()` + `toLocaleString( 'fr-FR', ... )`. Si l'entrée est
 * vide ou invalide, retourne le fallback (défaut : `'—'`).
 *
 * Le format par défaut (`dateStyle: 'long'`, `timeStyle: 'short'`) produit
 * par exemple « 16 mai 2026 à 16:30 » en français. Pour un format compact
 * (ex. tableau dense), passer `{ dateStyle: 'short', timeStyle: 'short' }`
 * → « 16/05/2026 16:30 ».
 *
 * @param {?string}                                 sqlStr                      String MySQL UTC.
 * @param {Object}                                  [options]                   Options surcharge.
 * @param {Intl.DateTimeFormatOptions['dateStyle']} [options.dateStyle='long']  Style date.
 * @param {Intl.DateTimeFormatOptions['timeStyle']} [options.timeStyle='short'] Style heure.
 * @param {string}                                  [options.locale='fr-FR']    Locale i18n.
 * @param {string}                                  [options.fallback='—']      Texte si parse échoue.
 * @return {string} Date formatée ou fallback.
 */
export function formatLocalDateTime( sqlStr, options = {} ) {
	const {
		dateStyle = 'long',
		timeStyle = 'short',
		locale = 'fr-FR',
		fallback = '—',
	} = options;
	const date = parseUtcDatetime( sqlStr );
	if ( null === date ) {
		return fallback;
	}
	return date.toLocaleString( locale, { dateStyle, timeStyle } );
}
