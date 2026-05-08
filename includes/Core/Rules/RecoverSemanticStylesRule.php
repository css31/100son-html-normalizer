<?php
/**
 * P8 — RecoverSemanticStylesRule.
 *
 * Recupere le sens semantique de styles inline de presentation AVANT que P6
 * ne les strippe :
 *  - font-weight: bold (ou >= 700) -> enrobe le contenu dans <strong>
 *  - font-style: italic            -> enrobe dans <em>
 *
 * Comportement chirurgical : seule la declaration semantique est retiree
 * du style, les autres declarations (text-align, color, font-size...)
 * restent intactes pour P6. Si plusieurs mappings detectes : ordre fige
 * <strong> exterieur, <em> interieur.
 *
 * Cf. cahier section 3.1 F2.P8, section 8 F2.P8, section 14 hyp. 23.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Preset P8 : recuperation semantique des styles inline.
 */
final class RecoverSemanticStylesRule implements RuleInterface {

	/**
	 * Activer le mapping font-weight -> <strong>.
	 *
	 * @var bool
	 */
	private bool $map_bold;

	/**
	 * Activer le mapping font-style: italic -> <em>.
	 *
	 * @var bool
	 */
	private bool $map_italic;

	/**
	 * Constructor.
	 *
	 * @param bool $map_bold   Activer le mapping bold.
	 * @param bool $map_italic Activer le mapping italic.
	 */
	public function __construct( bool $map_bold = true, bool $map_italic = true ) {
		$this->map_bold   = $map_bold;
		$this->map_italic = $map_italic;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'P8';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Recuperation semantique des styles', '100son-html-normalizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $html, array $context = [] ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}
		if ( ! $this->map_bold && ! $this->map_italic ) {
			return $html;
		}

		$doc     = DomHtml::parse_fragment( $html );
		$wrapper = DomHtml::get_root_wrapper( $doc );
		if ( null === $wrapper ) {
			return $html;
		}

		$xpath    = new DOMXPath( $doc );
		$styled   = $xpath->query( './/*[@style]', $wrapper );
		$elements = [];
		if ( $styled !== false ) {
			foreach ( $styled as $node ) {
				if ( $node instanceof DOMElement ) {
					$elements[] = $node;
				}
			}
		}

		foreach ( $elements as $el ) {
			$this->process_element( $el );
		}

		return DomHtml::serialize_fragment( $doc );
	}

	/**
	 * Traite un element : detecte les declarations semantiques, retire-les
	 * du style et enrobe le contenu dans <strong>/<em>.
	 *
	 * @param DOMElement $el Element a traiter.
	 * @return void
	 */
	private function process_element( DOMElement $el ): void {
		$style = (string) $el->getAttribute( 'style' );

		[ $kept, $is_bold, $is_italic ] = self::scan_declarations( $style );

		// Filtrage selon mappings actifs.
		$wrap_bold   = $this->map_bold && $is_bold;
		$wrap_italic = $this->map_italic && $is_italic;

		if ( ! $wrap_bold && ! $wrap_italic ) {
			// Aucune action sur cet element (style intact).
			return;
		}

		// Mettre a jour l'attribut style (sans les declarations recuperees).
		// Important : on ne retire de $kept QUE les declarations dont le mapping est ACTIF.
		// Si le mapping bold est OFF mais que l'element a font-weight:bold, la declaration
		// reste dans $kept et n'est donc pas perdue.
		$leftovers = self::rebuild_style( $kept, $wrap_bold, $wrap_italic );
		if ( '' === $leftovers ) {
			$el->removeAttribute( 'style' );
		} else {
			$el->setAttribute( 'style', $leftovers );
		}

		// Enrobage du contenu : italic d'abord (innermost), puis bold (outermost).
		// Resultat fige : <strong><em>...</em></strong>.
		if ( $wrap_italic ) {
			self::wrap_children( $el, 'em' );
		}
		if ( $wrap_bold ) {
			self::wrap_children( $el, 'strong' );
		}
	}

	/**
	 * Parse les declarations d'un style attr et detecte semantique.
	 *
	 * @param string $style Valeur brute de l'attribut style.
	 * @return array{0: list<array{property: string, value: string}>, 1: bool, 2: bool}
	 *         [declarations parsees, est_bold, est_italic]
	 */
	private static function scan_declarations( string $style ): array {
		$kept      = [];
		$is_bold   = false;
		$is_italic = false;

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

			if ( 'font-weight' === $property && self::is_bold_value( $value ) ) {
				$is_bold = true;
			} elseif ( 'font-style' === $property && 'italic' === strtolower( $value ) ) {
				$is_italic = true;
			}

			$kept[] = [
				'property' => $property,
				'value'    => $value,
			];
		}

		return [ $kept, $is_bold, $is_italic ];
	}

	/**
	 * Determine si une valeur de font-weight equivaut a "bold".
	 *
	 * Couvre : 'bold' (insensible a la casse), 'bolder', et toute valeur
	 * numerique >= 700.
	 *
	 * @param string $value Valeur brute.
	 * @return bool
	 */
	private static function is_bold_value( string $value ): bool {
		$normalized = strtolower( trim( $value ) );
		if ( 'bold' === $normalized || 'bolder' === $normalized ) {
			return true;
		}
		if ( ctype_digit( $normalized ) && (int) $normalized >= 700 ) {
			return true;
		}
		return false;
	}

	/**
	 * Reconstruit la chaine style en omettant les declarations recuperees.
	 *
	 * @param list<array{property: string, value: string}> $declarations Declarations parsees.
	 * @param bool                                         $omit_bold    Omet font-weight si bold.
	 * @param bool                                         $omit_italic  Omet font-style si italic.
	 * @return string
	 */
	private static function rebuild_style( array $declarations, bool $omit_bold, bool $omit_italic ): string {
		$parts = [];
		foreach ( $declarations as $decl ) {
			$prop  = $decl['property'];
			$value = $decl['value'];

			if ( $omit_bold && 'font-weight' === $prop && self::is_bold_value( $value ) ) {
				continue;
			}
			if ( $omit_italic && 'font-style' === $prop && 'italic' === strtolower( $value ) ) {
				continue;
			}
			$parts[] = $prop . ': ' . $value;
		}
		return '' === implode( '; ', $parts ) ? '' : implode( '; ', $parts ) . ';';
	}

	/**
	 * Enrobe tous les enfants directs d'un element dans un nouveau tag.
	 *
	 * @param DOMElement $el  Element parent.
	 * @param string     $tag Nom du tag d'enrobage (strong, em, ...).
	 * @return void
	 */
	private static function wrap_children( DOMElement $el, string $tag ): void {
		$doc = $el->ownerDocument;
		if ( null === $doc ) {
			return;
		}
		// Snapshot des enfants actuels (sinon iteration invalidee par appendChild).
		$children = [];
		foreach ( $el->childNodes as $child ) {
			$children[] = $child;
		}
		if ( [] === $children ) {
			return;
		}

		$wrapper = $doc->createElement( $tag );
		foreach ( $children as $child ) {
			if ( $child instanceof DOMNode ) {
				// Detache du parent et l'attache au wrapper.
				$wrapper->appendChild( $child );
			}
		}
		$el->appendChild( $wrapper );
	}
}
