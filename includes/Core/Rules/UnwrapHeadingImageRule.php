<?php
/**
 * P9 — UnwrapHeadingImageRule.
 *
 * Désencapsule les `<h1>` à `<h6>` qui ne contiennent qu'une image (sans
 * texte) — typique des contenus WP migrés où un éditeur visuel a wrappé
 * une image dans un titre par erreur. Exemple :
 *
 *   <h2><img src="..." class="aligncenter wp-image-14157" ...></h2>
 *
 *   devient :
 *
 *   <img src="..." class="aligncenter wp-image-14157" ...>
 *
 * Le `<img>` (ou son éventuel wrapper `<a>`/`<figure>`/etc.) est préservé
 * intact ; seules les balises de titre sont retirées. Le contenu textuel
 * (s'il existe) bloque le matching — un titre légitime avec image et
 * légende texte n'est pas désencapsulé.
 *
 * Symétrique de P2 (titres vides) mais ciblé sur les titres « pseudo-vides »
 * qui sont en fait des wrappers d'image. P2 préserve ces titres
 * volontairement (présence d'`<img>` = élément structurel) — c'est P9
 * qui les nettoie.
 *
 * Pipeline : placé après P5 (split br→p) et avant P1/P2 (cleanup final).
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
 * Préréglage P9 : désencapsulation des titres autour d'images.
 */
final class UnwrapHeadingImageRule implements RuleInterface {

	/**
	 * Tags ciblés (idem P2 pour cohérence).
	 *
	 * @var list<string>
	 */
	private const HEADING_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'P9';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Titres autour d\'images', '100son-html-normalizer' );
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
		// itération est risqué (cf. P1/P2 pour le même pattern).
		$headings = array();
		foreach ( self::HEADING_TAGS as $tag ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $heading ) {
				$headings[] = $heading;
			}
		}

		foreach ( $headings as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}
			if ( ! self::is_image_wrapping_heading( $heading ) ) {
				continue;
			}
			self::unwrap( $heading );
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
		foreach ( self::HEADING_TAGS as $tag ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $heading ) {
				if ( $heading instanceof DOMElement && self::is_image_wrapping_heading( $heading ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Indique si un heading est un wrapper « pseudo-vide » d'image.
	 *
	 * Critères cumulatifs :
	 *  1. contient au moins un descendant `<img>` (à n'importe quel niveau,
	 *     pour gérer `<h2><a><img></a></h2>` et `<h2><figure><img></figure></h2>`) ;
	 *  2. son `textContent` (après normalisation NBSP→espace et trim) est vide
	 *     — pas de légende texte autour de l'image.
	 *
	 * L'attribut `alt` de l'image n'est PAS dans textContent (c'est un
	 * attribut, pas un text node), donc `<h2><img alt="Légende"></h2>` est
	 * bien matché — l'alt reste préservé dans l'img après unwrap.
	 *
	 * @param DOMElement $heading Heading candidat.
	 * @return bool
	 */
	private static function is_image_wrapping_heading( DOMElement $heading ): bool {
		$has_img = false;
		foreach ( $heading->getElementsByTagName( 'img' ) as $img ) {
			$has_img = true;
			break;
		}
		if ( ! $has_img ) {
			return false;
		}

		$text = (string) $heading->textContent;
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
	 * @param DOMElement $heading Élément à désencapsuler.
	 * @return void
	 */
	private static function unwrap( DOMElement $heading ): void {
		$parent = $heading->parentNode;
		if ( null === $parent ) {
			return;
		}

		// Déplace chaque enfant vers le parent, juste avant le heading lui-même.
		// `firstChild` boucle automatiquement car insertBefore détache le node.
		/** @var DOMNode|null $child */
		$child = $heading->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$parent->insertBefore( $child, $heading );
			$child = $next;
		}

		$parent->removeChild( $heading );
	}
}
