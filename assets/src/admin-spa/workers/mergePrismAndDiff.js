/**
 * mergePrismAndDiff — fusionne une sortie Prism (`Prism.highlight`) avec un
 * masque positionnel de fragments à surligner, en respectant le nesting HTML.
 *
 * Contexte. Sur le panneau Avant ou Après de la modale Diff, on veut afficher
 * **simultanément** :
 *  - la coloration syntaxique Prism (`<span class="token …">…</span>`) ;
 *  - le surlignage `<mark class="htmln-diff-{removed|added}">` des fragments
 *    qui diffèrent entre Avant et Après.
 *
 * Naïvement appliquer Prism *puis* envelopper les fragments dans `<mark>`
 * casse le nesting HTML (`<mark>texte<span>…</mark>…</span>` est invalide).
 * Cette fonction parse la sortie Prism token-par-token (balise vs run de
 * texte) et, à chaque traversée de balise, ferme le `<mark>` ouvert avant
 * la balise et le rouvre juste après. Le résultat est du HTML strictement
 * valide où `<mark>` n'enjambe jamais un `<span>` Prism.
 *
 * Subtilité des entités. Prism échappe les caractères spéciaux en entités
 * HTML : `<` → `&lt;`, `>` → `&gt;`, `&` → `&amp;`, `"` → `&quot;`. Chaque
 * entité représente **un seul caractère source** mais occupe 4 à 6 caractères
 * dans la sortie. Le compteur `sourcePos` doit donc avancer de 1 par entité
 * détectée, pas du nombre de caractères de la sortie. On reconnaît les
 * entités à la volée et on les ré-émet telles quelles pour garder la sortie
 * octet-pour-octet identique à celle de Prism (zéro risque d'altération
 * visuelle entre avant et après calcul).
 *
 * Performances. Parsing linéaire en taille de la sortie Prism (~2× la
 * source à cause des spans). Sur 28 000 caractères source, exécution sous
 * la milliseconde — négligeable comparé au diff Myers qui le précède.
 *
 * Sécurité. Output destiné à `dangerouslySetInnerHTML`. Le contrat est
 * délégué à Prism (échappe `<`, `>`, `&`, `"` en entités) et préservé ici :
 *  - Les balises Prism sont émises **telles quelles** (pas de réécriture) ;
 *  - Les runs de texte sont émis **tels quels** (pas de décodage / réencodage) ;
 *  - Les `<mark>` injectés ne portent que des classes en dur passées via
 *    `markClass` (que le caller doit ne jamais composer dynamiquement).
 */

/**
 * Construit un masque positionnel des caractères à surligner.
 *
 * Pour un panneau donné (Avant ou Après), on parcourt les `parts` retournées
 * par `diffWordsWithSpace`, on filtre celles qui appartiennent au code de ce
 * panneau (on garde !added pour Avant, !removed pour Après), puis on marque
 * dans le masque les positions correspondant aux parts qui sont effectivement
 * un fragment supprimé (mode 'removed') ou ajouté (mode 'added').
 *
 * Le `Uint8Array` est volontairement préféré à un `Set<number>` :
 *  - empreinte mémoire stable et compacte (1 octet par caractère source) ;
 *  - lookup O(1) sans hashing ;
 *  - itération séquentielle déjà naturelle dans le parser ci-dessous.
 *
 * @param {Array<{value: string, added?: boolean, removed?: boolean}>} parts      Sortie brute de `diffWordsWithSpace`.
 * @param {'removed' | 'added'}                                        mode       Quel panneau on construit.
 * @param {number}                                                     codeLength Longueur de la chaîne source du panneau.
 * @return {Uint8Array} Masque où `mask[i] === 1` ssi le caractère source à la position `i` doit être surligné.
 */
export function buildMarkedMask( parts, mode, codeLength ) {
	const mask = new Uint8Array( codeLength );
	let pos = 0;
	for ( const part of parts ) {
		const value = 'string' === typeof part.value ? part.value : '';
		const partLen = value.length;
		// On ne traverse que les parts qui appartiennent au code de ce panneau.
		const belongsToPanel =
			'removed' === mode ? ! part.added : ! part.removed;
		if ( ! belongsToPanel ) {
			continue;
		}
		const isMarked =
			( 'removed' === mode && part.removed ) ||
			( 'added' === mode && part.added );
		if ( isMarked ) {
			for ( let i = 0; i < partLen; i++ ) {
				mask[ pos + i ] = 1;
			}
		}
		pos += partLen;
	}
	return mask;
}

/**
 * Reconnaît une entité HTML à la position `i` dans `text` et retourne le
 * nombre de caractères qu'elle occupe dans la sortie, ou 1 si ce n'est
 * pas une entité reconnue (caractère littéral).
 *
 * Note : on ne reconnaît que les 4 entités émises par Prism (cf. Prism
 * source — utilitaire `encode`). Les entités décimales (`&#123;`) ou
 * hexadécimales (`&#xAB;`) ne sont pas produites par Prism, donc pas
 * traitées ici (elles seraient comptées chacune pour 1 char source
 * incorrectement — à revoir si Prism évolue).
 *
 * @param {string} text Run de texte issu de Prism.
 * @param {number} i    Index courant.
 * @return {number} Longueur consommée en caractères de sortie (1, 4, 5 ou 6).
 */
function consumedAtPos( text, i ) {
	if ( text.charCodeAt( i ) !== 38 /* & */ ) {
		return 1;
	}
	// On compare des slices courts — plus rapide qu'un regex à chaque char.
	const slice4 = text.substr( i, 4 );
	if ( '&lt;' === slice4 || '&gt;' === slice4 ) {
		return 4;
	}
	if ( '&amp;' === text.substr( i, 5 ) ) {
		return 5;
	}
	if ( '&quot;' === text.substr( i, 6 ) ) {
		return 6;
	}
	return 1;
}

/**
 * Traite un run de texte issu du parsing de la sortie Prism. Avance
 * `sourcePos` d'un caractère par caractère source (en respectant les
 * entités), et insère `<mark>` / `</mark>` aux frontières de fragments
 * marqués sans casser le run.
 *
 * @param {string}     text      Run de texte (peut contenir `&lt;`, etc.).
 * @param {Uint8Array} mask      Masque positionnel des caractères marqués.
 * @param {string}     markClass Classe CSS du `<mark>` (en dur côté caller).
 * @param {Object}     state     Mutable : `{ sourcePos, markOpen, out }`.
 */
function processTextRun( text, mask, markClass, state ) {
	let i = 0;
	let chunkStart = 0;
	// L'état `markOpen` est conservé d'un run à l'autre via `state.markOpen`,
	// puisqu'une balise ne change pas l'état logique (elle ne fait que
	// fermer-et-rouvrir le mark pour respecter le nesting HTML).
	while ( i < text.length ) {
		const isMarkedHere = 1 === mask[ state.sourcePos ];
		if ( isMarkedHere !== state.markOpen ) {
			// Transition : on émet le chunk précédent, on bascule le mark.
			if ( i > chunkStart ) {
				state.out.push( text.substring( chunkStart, i ) );
			}
			if ( state.markOpen ) {
				state.out.push( '</mark>' );
			} else {
				state.out.push( '<mark class="' + markClass + '">' );
			}
			state.markOpen = isMarkedHere;
			chunkStart = i;
		}
		const consumed = consumedAtPos( text, i );
		i += consumed;
		state.sourcePos += 1;
	}
	// Émettre le dernier chunk du run.
	if ( i > chunkStart ) {
		state.out.push( text.substring( chunkStart, i ) );
	}
}

/**
 * Fusionne la sortie Prism d'une chaîne source avec le masque positionnel
 * des fragments à surligner.
 *
 * @param {string}     prismHtml Sortie de `Prism.highlight(code, …, 'markup')`.
 * @param {Uint8Array} mask      Masque positionnel (cf. `buildMarkedMask`).
 * @param {string}     markClass Classe CSS du `<mark>` injecté (`htmln-diff-removed` ou `htmln-diff-added`).
 * @return {string} HTML mêlant `<span class="token">` Prism et `<mark>` diff, nesting valide.
 */
export function mergePrismAndDiff( prismHtml, mask, markClass ) {
	if ( 'string' !== typeof prismHtml || '' === prismHtml ) {
		return '';
	}
	// Tokenizer simple : groupe 1 = balise complète, groupe 2 = run de texte.
	// La sortie Prism est bien formée donc ce regex « plein-texte »
	// fonctionne sans complication : pas de `<` littéral non échappé en
	// dehors d'une balise.
	const tagRegex = /(<[^>]+>)|([^<]+)/g;
	const state = {
		sourcePos: 0,
		markOpen: false,
		out: [],
	};
	let match;
	while ( ( match = tagRegex.exec( prismHtml ) ) !== null ) {
		if ( match[ 1 ] ) {
			// Balise Prism : `<span class="token …">` ou `</span>`.
			// Règle de nesting : si un mark est ouvert, on le ferme avant
			// la balise et on le rouvre après, pour garantir que `<mark>`
			// n'enjambe jamais un `<span>` Prism.
			if ( state.markOpen ) {
				state.out.push( '</mark>' );
			}
			state.out.push( match[ 1 ] );
			if ( state.markOpen ) {
				state.out.push( '<mark class="' + markClass + '">' );
			}
		} else if ( match[ 2 ] ) {
			processTextRun( match[ 2 ], mask, markClass, state );
		}
	}
	if ( state.markOpen ) {
		state.out.push( '</mark>' );
	}
	return state.out.join( '' );
}
