<?php
/**
 * R15 — MergeAdjacentInlineTagsRule.
 *
 * Fusionne deux éléments inline **adjacents** (uniquement séparés
 * par des espaces / nœuds blancs) qui partagent **exactement** le
 * même nom de balise ET les mêmes attributs. Élimine le doublement
 * sémantiquement redondant produit par les éditeurs visuels (typique
 * SiteOrigin / Word collage) :
 *
 *   <em>foo</em> <em>bar</em>             →  <em>foo bar</em>
 *   <strong>A</strong><strong>B</strong>   →  <strong>AB</strong>
 *   <span style="font-size:14pt">X</span><span style="font-size:14pt">Y</span>
 *                                          →  <span style="font-size:14pt">XY</span>
 *
 * Sont **explicitement exclus** (intentionnels, structurels ou
 * sémantiquement distincts) :
 *  - `<p>` : un split paragraphe est volontaire ;
 *  - `<div>`, `<section>`, `<article>` et autres conteneurs bloc ;
 *  - `<h1>`-`<h6>` : sections distinctes ;
 *  - `<a>` : deux liens même href + même target restent deux zones
 *    cliquables distinctes intentionnellement ;
 *  - `<li>`, `<td>`, `<tr>`, `<th>` : structures de listes/tableaux ;
 *  - éléments void (`<br>`, `<img>`, `<hr>`, etc.) : pas de contenu
 *    à fusionner.
 *
 * Sont **ciblés** (whitelist d'inline formatting) :
 *
 *   em, strong, i, b, u, s, sub, sup, small, big, tt, mark, ins, del,
 *   code, kbd, samp, var, cite, q, abbr, dfn, time, data, output,
 *   bdi, bdo, font, span
 *
 * Comparaison des attributs : **stricte**. Tous les attributs doivent
 * être présents des deux côtés et porter exactement la même valeur.
 * Si l'un a un attribut que l'autre n'a pas, OU si une valeur diffère
 * (même par un espace), pas de fusion. Cas typique préservé :
 *
 *   <span style="color:red">A</span><span style="color:blue">B</span>
 *   → inchangé.
 *
 * Itération **multi-passes** : après une première vague de fusions,
 * de nouvelles paires adjacentes peuvent apparaître (chaînage). On
 * répète jusqu'à stabilisation (ou plafond `MAX_PASSES` pour
 * sécurité).
 *
 * Position pipeline : après R6 (strip styles) — ainsi deux
 * `<span style="X">` devenus `<span>` puis vidés de leur style peuvent
 * être fusionnés en un seul. Avant R9/R10/R11/R12/R13/R14 et le
 * cleanup final R1/R2.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Règle R15 : fusion d'inlines adjacents identiques.
 */
final class MergeAdjacentInlineTagsRule implements RuleInterface {

	/**
	 * Tags inline ciblés (whitelist). Tout tag absent de cette liste
	 * n'est jamais fusionné, même si deux éléments adjacents portent
	 * exactement les mêmes attributs.
	 *
	 * @var list<string>
	 */
	private const MERGEABLE_TAGS = array(
		'em', 'strong', 'i', 'b', 'u', 's',
		'sub', 'sup', 'small', 'big', 'tt',
		'mark', 'ins', 'del',
		'code', 'kbd', 'samp', 'var',
		'cite', 'q',
		'abbr', 'dfn', 'time', 'data', 'output',
		'bdi', 'bdo', 'font',
		'span',
	);

	/**
	 * Nombre maximal de passes (garde-fou anti-boucle infinie en cas
	 * d'anomalie). En pratique 2-3 passes suffisent même pour les
	 * chaînes les plus longues.
	 */
	private const MAX_PASSES = 10;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R15';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Fusion balises inline en double', '100son-html-normalizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $html, array $context = array() ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$doc     = DomHtml::parse_fragment( $html );
		$wrapper = DomHtml::get_root_wrapper( $doc );
		if ( null === $wrapper ) {
			return $html;
		}

		// Boucle multi-passes jusqu'à stabilisation.
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			if ( 0 === self::run_pass( $doc ) ) {
				break;
			}
		}

		return DomHtml::serialize_fragment( $doc );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Compte une simulation : le nombre de fusions que `apply()`
	 * effectuerait sur le HTML d'entrée (toutes passes confondues,
	 * comme `apply` lui-même).
	 */
	public function countMatches( string $html, array $context = array() ): int {
		if ( '' === trim( $html ) ) {
			return 0;
		}
		$doc     = DomHtml::parse_fragment( $html );
		$wrapper = DomHtml::get_root_wrapper( $doc );
		if ( null === $wrapper ) {
			return 0;
		}

		$total = 0;
		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			$n = self::run_pass( $doc );
			if ( 0 === $n ) {
				break;
			}
			$total += $n;
		}
		return $total;
	}

	/**
	 * Effectue une passe unique de fusion sur tout le document.
	 * Retourne le nombre de fusions appliquées.
	 *
	 * @param DOMDocument $doc Document hôte.
	 * @return int
	 */
	private static function run_pass( DOMDocument $doc ): int {
		$count = 0;
		foreach ( self::MERGEABLE_TAGS as $tag ) {
			// Snapshot avant mutation : la NodeList est live.
			$elements = array();
			foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
				$elements[] = $el;
			}
			foreach ( $elements as $el ) {
				if ( ! $el instanceof DOMElement ) {
					continue;
				}
				// L'élément a pu être supprimé par une fusion précédente
				// dans cette même boucle ; on vérifie qu'il est encore
				// rattaché.
				if ( null === $el->parentNode ) {
					continue;
				}
				if ( self::try_merge_with_next( $el ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Tente de fusionner `$current` avec son frère élément suivant
	 * (en sautant les nœuds blancs intercalaires) si :
	 *  - le frère existe et est un élément ;
	 *  - même nom de balise ;
	 *  - mêmes attributs (exact match nom + valeur).
	 *
	 * En cas de succès, le contenu (et les nœuds blancs intercalaires)
	 * sont déplacés dans `$current`, le frère suivant est retiré.
	 *
	 * @param DOMElement $current Candidat « gauche » de la fusion.
	 * @return bool Vrai si une fusion a eu lieu.
	 */
	private static function try_merge_with_next( DOMElement $current ): bool {
		$whitespace_nodes = array();
		$sibling          = $current->nextSibling;
		while ( null !== $sibling ) {
			if ( XML_TEXT_NODE === $sibling->nodeType ) {
				$txt = str_replace( "\xc2\xa0", ' ', (string) $sibling->nodeValue );
				if ( '' === trim( $txt ) ) {
					$whitespace_nodes[] = $sibling;
					$sibling            = $sibling->nextSibling;
					continue;
				}
				return false; // Texte non-blanc entre les deux : pas de fusion.
			}
			if ( XML_COMMENT_NODE === $sibling->nodeType ) {
				// Un commentaire entre deux inlines bloque la fusion
				// (signal éditorial intentionnel).
				return false;
			}
			break;
		}

		if ( ! $sibling instanceof DOMElement ) {
			return false;
		}
		if ( strtolower( $sibling->nodeName ) !== strtolower( $current->nodeName ) ) {
			return false;
		}
		if ( ! self::same_attributes( $current, $sibling ) ) {
			return false;
		}

		// Fusion : déplace les nœuds blancs puis le contenu du sibling
		// dans `$current`, puis supprime le sibling.
		foreach ( $whitespace_nodes as $ws ) {
			$current->appendChild( $ws );
		}
		/** @var DOMNode|null $child */
		$child = $sibling->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$current->appendChild( $child );
			$child = $next;
		}
		$parent = $sibling->parentNode;
		if ( null !== $parent ) {
			$parent->removeChild( $sibling );
		}
		return true;
	}

	/**
	 * Compare strictement les attributs de deux éléments. Retourne
	 * vrai si chaque attribut présent dans `$a` est également présent
	 * dans `$b` avec la même valeur, ET vice-versa.
	 *
	 * @param DOMElement $a Premier élément.
	 * @param DOMElement $b Second élément.
	 * @return bool
	 */
	private static function same_attributes( DOMElement $a, DOMElement $b ): bool {
		$attrs_a = self::collect_attributes( $a );
		$attrs_b = self::collect_attributes( $b );
		if ( count( $attrs_a ) !== count( $attrs_b ) ) {
			return false;
		}
		foreach ( $attrs_a as $name => $value ) {
			if ( ! array_key_exists( $name, $attrs_b ) ) {
				return false;
			}
			if ( $attrs_b[ $name ] !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Sérialise les attributs d'un élément en tableau associatif
	 * `[name => value]`. Les noms d'attributs sont normalisés en
	 * minuscules (HTML est case-insensitive pour les attributs).
	 *
	 * @param DOMElement $element Élément.
	 * @return array<string, string>
	 */
	private static function collect_attributes( DOMElement $element ): array {
		$attrs = array();
		foreach ( $element->attributes as $attr ) {
			$attrs[ strtolower( $attr->nodeName ) ] = (string) $attr->nodeValue;
		}
		return $attrs;
	}
}
