<?php
/**
 * P2 — EmptyHeadingsRule.
 *
 * Supprime les titres `<h1>` à `<h6>` vides ou ne contenant que du blanc.
 * Symétrique de P1 mais sur les headings.
 *
 * Cf. cahier §3.1 F2.P2 et §8 F2.P2.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;
use DOMElement;

/**
 * Préréglage P2 : suppression des titres vides.
 */
final class EmptyHeadingsRule implements RuleInterface {

	/**
	 * Tags ciblés.
	 *
	 * @var list<string>
	 */
	private const HEADING_TAGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'P2';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Titres vides', '100son-html-normalizer' );
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

		$headings = [];
		foreach ( self::HEADING_TAGS as $tag ) {
			foreach ( $doc->getElementsByTagName( $tag ) as $heading ) {
				$headings[] = $heading;
			}
		}

		foreach ( $headings as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}
			if ( self::is_empty_heading( $heading ) ) {
				$heading->parentNode?->removeChild( $heading );
			}
		}

		return DomHtml::serialize_fragment( $doc );
	}

	/**
	 * Indique si un heading est vide ou ne contient que du blanc / inline vides.
	 *
	 * Considère comme vide un heading dont le texte effectif (textContent
	 * normalisé) est vide ou ne contient que du blanc, ET qui ne contient
	 * aucun élément structurel (img, br…).
	 *
	 * @param DOMElement $heading Heading candidat.
	 * @return bool
	 */
	private static function is_empty_heading( DOMElement $heading ): bool {
		static $structural = [ 'img', 'iframe', 'video', 'audio', 'embed', 'object', 'picture', 'source' ];
		foreach ( $heading->getElementsByTagName( '*' ) as $descendant ) {
			if ( in_array( strtolower( $descendant->nodeName ), $structural, true ) ) {
				return false;
			}
		}

		$text = (string) $heading->textContent;
		$text = str_replace( "\xc2\xa0", ' ', $text );
		return '' === trim( $text );
	}
}
