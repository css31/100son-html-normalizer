<?php
/**
 * R10 — UnwrapParagraphImageRule.
 *
 * Désencapsule les `<p>` qui ne contiennent qu'une image (sans texte) —
 * cas typique du HTML migré depuis Word, Classic Editor ou SiteOrigin
 * où une image isolée a été automatiquement enveloppée dans un `<p>`.
 * Exemple :
 *
 *   <p><img class="aligncenter wp-image-19036" src="..." alt="..." width="700" height="485"></p>
 *
 *   devient :
 *
 *   <img class="aligncenter wp-image-19036" src="..." alt="..." width="700" height="485">
 *
 * Le `<img>` (et son éventuel wrapper `<a>`/`<figure>`/etc.) est préservé
 * intact ; seule la balise `<p>` est retirée. Le contenu textuel autour
 * de l'image (s'il existe) bloque le matching — un paragraphe légitime
 * avec image et texte n'est pas désencapsulé.
 *
 * Symétrique de R1 (paragraphes vides) qui **préserve volontairement**
 * ces paragraphes (présence d'élément structurel = pas "vide") — c'est
 * R10 qui les nettoie. Et symétrique cousine de R9 (`UnwrapHeadingImageRule`)
 * qui fait la même chose sur les `<h1>`-`<h6>`.
 *
 * Pipeline : placé juste après R9 — cohérence sémantique (deux règles de
 * désencapsulation d'images) et même invariant (s'exécute avant le cleanup
 * final R1/R2).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;
use DOMElement;
use DOMNode;

/**
 * Règle R10 : désencapsulation des paragraphes autour d'images.
 */
final class UnwrapParagraphImageRule implements RuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R10';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Paragraphes autour d\'images', '100son-html-normalizer' );
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

		// Collecter avant modification — modifier la live NodeList pendant
		// itération est risqué (cf. R1/R2/R9 pour le même pattern).
		$paragraphs = array();
		foreach ( $doc->getElementsByTagName( 'p' ) as $p ) {
			$paragraphs[] = $p;
		}

		foreach ( $paragraphs as $p ) {
			if ( ! $p instanceof DOMElement ) {
				continue;
			}
			if ( ! self::is_image_wrapping_paragraph( $p ) ) {
				continue;
			}
			self::unwrap( $p );
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

		$count = 0;
		foreach ( $doc->getElementsByTagName( 'p' ) as $p ) {
			if ( $p instanceof DOMElement && self::is_image_wrapping_paragraph( $p ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Indique si un paragraphe est un wrapper « pseudo-vide » d'image.
	 *
	 * Critères cumulatifs (alignés sur R9) :
	 *  1. contient au moins un descendant `<img>` (à n'importe quel niveau,
	 *     pour gérer `<p><a><img></a></p>` et `<p><figure><img></figure></p>`) ;
	 *  2. son `textContent` (après normalisation NBSP→espace et trim) est vide
	 *     — pas de légende texte autour de l'image.
	 *
	 * L'attribut `alt` de l'image n'est PAS dans `textContent` (c'est un
	 * attribut, pas un text node), donc `<p><img alt="Légende"></p>` est
	 * bien matché — l'alt reste préservé dans l'img après unwrap.
	 *
	 * @param DOMElement $p Paragraphe candidat.
	 * @return bool
	 */
	private static function is_image_wrapping_paragraph( DOMElement $p ): bool {
		$has_img = false;
		foreach ( $p->getElementsByTagName( 'img' ) as $img ) {
			$has_img = true;
			break;
		}
		if ( ! $has_img ) {
			return false;
		}

		$text = (string) $p->textContent;
		$text = str_replace( "\xc2\xa0", ' ', $text );
		return '' === trim( $text );
	}

	/**
	 * Désencapsule un élément : déplace ses enfants à la même position
	 * dans le parent, puis supprime l'élément.
	 *
	 * Préserve l'ordre et la structure des descendants (le `<a>` ou
	 * `<figure>` qui pourrait wrapper l'image reste intact).
	 *
	 * @param DOMElement $p Élément à désencapsuler.
	 * @return void
	 */
	private static function unwrap( DOMElement $p ): void {
		$parent = $p->parentNode;
		if ( null === $parent ) {
			return;
		}

		/** @var DOMNode|null $child */
		$child = $p->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$parent->insertBefore( $child, $p );
			$child = $next;
		}

		$parent->removeChild( $p );
	}
}
