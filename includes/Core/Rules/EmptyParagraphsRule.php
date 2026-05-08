<?php
/**
 * P1 — EmptyParagraphsRule.
 *
 * Supprime les `<p>` vides ou contenant uniquement du blanc / `&nbsp;`.
 *
 * Cf. cahier §3.1 F2.P1 et §8 F2.P1.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;
use DOMElement;

/**
 * Préset P1 : suppression des paragraphes vides.
 */
final class EmptyParagraphsRule implements RuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'P1';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Paragraphes vides', '100son-html-normalizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $html, array $context = [] ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$doc     = DomHtml::parse_fragment( $html );
		$wrapper = DomHtml::get_root_wrapper( $doc );
		if ( null === $wrapper ) {
			return $html;
		}

		// Collecter avant suppression — modifier en cours d'itération sur DOMNodeList est risqué.
		$paragraphs = [];
		foreach ( $doc->getElementsByTagName( 'p' ) as $p ) {
			$paragraphs[] = $p;
		}

		foreach ( $paragraphs as $p ) {
			if ( ! $p instanceof DOMElement ) {
				continue;
			}
			if ( self::is_empty_paragraph( $p ) ) {
				$p->parentNode?->removeChild( $p );
			}
		}

		return DomHtml::serialize_fragment( $doc );
	}

	/**
	 * Indique si un `<p>` est vide ou ne contient que du blanc.
	 *
	 * Considère comme vide un `<p>` dont le texte effectif (text content) est
	 * vide ou ne contient que des espaces, retours-ligne, tabulations,
	 * `&nbsp;` (U+00A0) — et qui ne contient AUCUN élément structurel
	 * (img, hr, br, iframe, video, audio, embed, object, picture, source).
	 *
	 * @param DOMElement $p Paragraphe candidat.
	 * @return bool
	 */
	private static function is_empty_paragraph( DOMElement $p ): bool {
		// 1. Si le <p> contient un élément non-textuel structurel, il n'est pas "vide".
		static $structural = [
			'img', 'hr', 'br', 'iframe', 'video', 'audio', 'embed', 'object', 'picture', 'source',
		];
		foreach ( $p->getElementsByTagName( '*' ) as $descendant ) {
			if ( in_array( strtolower( $descendant->nodeName ), $structural, true ) ) {
				return false;
			}
		}

		// 2. Texte effectif (textContent inclut nbsp en U+00A0, à traiter comme blanc).
		$text = (string) $p->textContent;
		// Remplace U+00A0 (nbsp) par espace ordinaire pour le test de vide.
		$text = str_replace( "\xc2\xa0", ' ', $text );
		return '' === trim( $text );
	}
}
