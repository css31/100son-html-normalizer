<?php
/**
 * R17 — HeadingPromotionRule.
 *
 * Promeut d'un cran les titres `<h3>`–`<h6>` lorsque le fragment ne
 * contient **aucun** `<h2>`. Cascade descendante : `<h6>` → `<h5>`,
 * `<h5>` → `<h4>`, `<h4>` → `<h3>`, `<h3>` → `<h2>`. Tous les attributs
 * et le contenu interne (texte + inlines) sont préservés à l'identique
 * — seule la balise est renommée.
 *
 * Pattern ciblé (corpus MMM-2, ~22 articles dont 374 / 491 / 5866 /
 * 6150) :
 *
 *   - Article SiteOrigin avec un unique `<h2>` long en tête (chapô
 *     variant B) + une cascade de `<h3>` comme sous-titres de section.
 *   - R13 démote ce h2-chapô en `<p class="chapo">`.
 *   - À ce point, la hiérarchie commence à `<h3>` sans h2 racine — défaut
 *     sémantique : un h3 sans h2 brise la structure document outline
 *     HTML5.
 *
 * Une fois R17 appliquée, les sous-titres `<h3>` redeviennent
 * `<h2>` (vrais headings de section), et la hiérarchie est correcte.
 *
 * **Pourquoi ne pas promouvoir quand il y a déjà un `<h2>`** : le
 * scénario où h2 cohabite légitimement avec des h3 (sections + sous-
 * sections) doit rester intact. La promotion n'est faite que pour
 * réparer le défaut « hiérarchie qui commence trop bas ».
 *
 * **Pourquoi la cascade ascendante (h3→h2 d'abord, h6→h5 en dernier)**
 * : pour garantir une promotion exactement d'un cran. En partant du
 * haut, chaque niveau de départ (h3, h4, h5, h6) est capturé tel qu'il
 * existait dans le HTML d'origine — la liste des cibles est matérialisée
 * dans un tableau avant le renommage, donc les éléments créés par les
 * étapes précédentes (les nouveaux h2/h3/h4) ne sont pas re-traités.
 * Une cascade descendante (h6→h5 d'abord) ferait migrer un h6 jusqu'à
 * h2 en 4 étapes, ce qui casse l'invariant « 1 cran ».
 *
 * Position pipeline (cf. PresetRegistry::PRESETS) : entre R10 et R1.
 *  - **Après R13/R14** : la démotion du chapô-h2 a libéré la condition
 *    « aucun h2 ».
 *  - **Après R9, R11, R12, R10** : toutes les règles qui inspectent un
 *    niveau précis (`<h4>` légendes → `<figcaption>`, désencapsulation
 *    h/p autour d'images) ont déjà tourné — promouvoir les niveaux ne
 *    casse pas leur logique.
 *  - **Avant R1, R2** : si la promotion produit un `<h2>` vide
 *    (impossible en pratique, le contenu est préservé), R2 ferait le
 *    ménage.
 *
 * Cas écartés (préservation explicite) :
 *  - `<h2>` déjà présent → no-op (hiérarchie valide ou volontaire).
 *  - `<h1>` présent → ignoré (h1 = titre d'article, hors scope).
 *  - Fragment sans heading → no-op.
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

/**
 * Règle R17 : promotion en cascade h3..h6 quand aucun h2 n'est présent.
 */
final class HeadingPromotionRule implements RuleInterface {

	/**
	 * Niveaux à promouvoir, ordonnés du plus haut au plus bas.
	 * `h3` est traité en premier (devient `h2`), puis `h4` (devient
	 * `h3`), …, `h6` en dernier (devient `h5`). Cf. note d'en-tête.
	 *
	 * @var list<array{from: string, to: string}>
	 */
	private const CASCADE = array(
		array(
			'from' => 'h3',
			'to'   => 'h2',
		),
		array(
			'from' => 'h4',
			'to'   => 'h3',
		),
		array(
			'from' => 'h5',
			'to'   => 'h4',
		),
		array(
			'from' => 'h6',
			'to'   => 'h5',
		),
	);

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R17';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Promotion h3 → h2 (cascade sans h2)', '100son-html-normalizer' );
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

		if ( ! self::should_promote( $doc ) ) {
			return $html;
		}

		foreach ( self::CASCADE as $step ) {
			self::rename_all( $doc, $step['from'], $step['to'] );
		}

		return DomHtml::serialize_fragment( $doc );
	}

	/**
	 * {@inheritDoc}
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
		if ( ! self::should_promote( $doc ) ) {
			return 0;
		}

		$total = 0;
		foreach ( self::CASCADE as $step ) {
			$total += $doc->getElementsByTagName( $step['from'] )->length;
		}
		return $total;
	}

	/**
	 * Détermine si la cascade doit être appliquée.
	 *
	 *  - aucun `<h2>` présent ;
	 *  - au moins un `<h3>` présent (le déclencheur — sans h3, pas de
	 *    promotion à faire même si h4/h5/h6 existent isolément, car
	 *    promouvoir h4→h3 sans h3 ne corrige rien).
	 *
	 * @param DOMDocument $doc Document parsé.
	 * @return bool
	 */
	private static function should_promote( DOMDocument $doc ): bool {
		if ( $doc->getElementsByTagName( 'h2' )->length > 0 ) {
			return false;
		}
		return $doc->getElementsByTagName( 'h3' )->length > 0;
	}

	/**
	 * Renomme toutes les balises `$from` en `$to` dans le document,
	 * en préservant attributs et enfants.
	 *
	 * `getElementsByTagName` retourne une `DOMNodeList` **live** — toute
	 * mutation du DOM pendant l'itération corrompt l'index. On matérialise
	 * d'abord les éléments cibles dans un tableau, puis on les remplace.
	 *
	 * @param DOMDocument $doc  Document hôte.
	 * @param string      $from Tag source (ex. `h3`).
	 * @param string      $to   Tag cible (ex. `h2`).
	 * @return void
	 */
	private static function rename_all( DOMDocument $doc, string $from, string $to ): void {
		$targets = array();
		foreach ( $doc->getElementsByTagName( $from ) as $node ) {
			if ( $node instanceof DOMElement ) {
				$targets[] = $node;
			}
		}
		foreach ( $targets as $element ) {
			self::rename_element( $doc, $element, $to );
		}
	}

	/**
	 * Renomme un élément (crée un nouveau nœud avec la balise cible,
	 * copie les attributs et enfants, remplace dans le parent).
	 *
	 * @param DOMDocument $doc     Document hôte.
	 * @param DOMElement  $element Élément à renommer.
	 * @param string      $to      Nouvelle balise.
	 * @return void
	 */
	private static function rename_element( DOMDocument $doc, DOMElement $element, string $to ): void {
		$parent = $element->parentNode;
		if ( null === $parent ) {
			return;
		}

		$replacement = $doc->createElement( $to );

		// Copie des attributs (preserve style, id, class, etc.).
		if ( $element->hasAttributes() ) {
			foreach ( $element->attributes as $attr ) {
				$replacement->setAttribute( $attr->nodeName, $attr->nodeValue ?? '' );
			}
		}

		// Déplace tous les enfants (text nodes + inlines) tels quels.
		/** @var DOMNode|null $child */
		$child = $element->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$replacement->appendChild( $child );
			$child = $next;
		}

		$parent->replaceChild( $replacement, $element );
	}
}
