<?php
/**
 * P6 — RemoveInlineStylesRule.
 *
 * Supprime les attributs `style="..."` du HTML.
 *
 * Option `keep_text_align` (booleen, defaut true) :
 *  - true : preserve la declaration `text-align: <valeur>` et drop tout le reste ;
 *           si seul `text-align` reste, l'attribut style est conserve avec uniquement cette declaration.
 *           Si rien ne reste, l'attribut est entierement supprime.
 *  - false : strip integralement l'attribut style sans exception.
 *
 * Cf. cahier section 3.1 F2.P6 et section 8 F2.P6.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;
use DOMElement;
use DOMXPath;

/**
 * Preset P6 : suppression des styles inline.
 */
final class RemoveInlineStylesRule implements RuleInterface {

	/**
	 * Conserver les declarations text-align.
	 *
	 * @var bool
	 */
	private bool $keep_text_align;

	/**
	 * Constructor.
	 *
	 * @param bool $keep_text_align Si true, preserve `text-align: ...`.
	 */
	public function __construct( bool $keep_text_align = true ) {
		$this->keep_text_align = $keep_text_align;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'P6';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Styles inline', '100son-html-normalizer' );
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

		$xpath    = new DOMXPath( $doc );
		$styled   = $xpath->query( './/*[@style]', $wrapper );
		$elements = array();
		if ( $styled !== false ) {
			foreach ( $styled as $node ) {
				if ( $node instanceof DOMElement ) {
					$elements[] = $node;
				}
			}
		}

		foreach ( $elements as $el ) {
			if ( ! $this->keep_text_align ) {
				$el->removeAttribute( 'style' );
				continue;
			}
			$filtered = self::keep_only_text_align( (string) $el->getAttribute( 'style' ) );
			if ( '' === $filtered ) {
				$el->removeAttribute( 'style' );
			} else {
				$el->setAttribute( 'style', $filtered );
			}
		}

		return DomHtml::serialize_fragment( $doc );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Compte les elements dont l'attribut style serait modifie ou supprime :
	 *  - keep_text_align=false : tous les elements avec un attribut style.
	 *  - keep_text_align=true  : seulement ceux dont au moins une declaration
	 *    n'est pas text-align (les style="text-align: …" purs sont preserves
	 *    a l'identique par apply() et ne sont donc PAS comptes).
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
		$xpath   = new DOMXPath( $doc );
		$styled  = $xpath->query( './/*[@style]', $wrapper );
		if ( false === $styled ) {
			return 0;
		}
		if ( ! $this->keep_text_align ) {
			return $styled->length;
		}
		$count = 0;
		foreach ( $styled as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			if ( self::has_non_text_align_declaration( (string) $node->getAttribute( 'style' ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Indique si un attribut style contient au moins une declaration autre
	 * que text-align (donc serait modifie par apply() en mode keep_text_align).
	 *
	 * @param string $style Valeur brute de l'attribut style.
	 * @return bool
	 */
	private static function has_non_text_align_declaration( string $style ): bool {
		foreach ( explode( ';', $style ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' === $declaration ) {
				continue;
			}
			$pos = strpos( $declaration, ':' );
			if ( false === $pos ) {
				continue;
			}
			$property = strtolower( trim( substr( $declaration, 0, $pos ) ) );
			$value    = trim( substr( $declaration, $pos + 1 ) );
			if ( '' === $value ) {
				continue;
			}
			if ( 'text-align' !== $property ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filtre une chaine `style="..."` pour ne garder que la declaration text-align.
	 *
	 * @param string $style Valeur brute de l'attribut style.
	 * @return string Style filtre, ou chaine vide si aucun text-align trouve.
	 */
	private static function keep_only_text_align( string $style ): string {
		$kept = array();
		foreach ( explode( ';', $style ) as $declaration ) {
			$declaration = trim( $declaration );
			if ( '' === $declaration ) {
				continue;
			}
			$pos = strpos( $declaration, ':' );
			if ( false === $pos ) {
				continue;
			}
			$property = strtolower( trim( substr( $declaration, 0, $pos ) ) );
			$value    = trim( substr( $declaration, $pos + 1 ) );
			if ( 'text-align' === $property && '' !== $value ) {
				$kept[] = 'text-align: ' . $value;
			}
		}
		return '' === implode( '; ', $kept ) ? '' : implode( '; ', $kept ) . ';';
	}
}
