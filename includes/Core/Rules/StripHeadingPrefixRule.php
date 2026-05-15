<?php
/**
 * R16 — StripHeadingPrefixRule.
 *
 * Retire les **préfixes typographiques** (numéro d'ordre ou puce/tiret)
 * placés en tête de contenu d'un `<h1>`–`<h6>`. Exemples corrigés :
 *
 *   <h2>1. Pourquoi bioclimatique ?</h2>
 *   →
 *   <h2>Pourquoi bioclimatique ?</h2>
 *
 *   <h2>• Spécialiste de la terrasse en bois</h2>
 *   →
 *   <h2>Spécialiste de la terrasse en bois</h2>
 *
 * Convention sémantique : un heading porte un titre, pas une marque
 * de liste. La numérotation appartient soit à une vraie `<ol>` (si
 * les sections sont courtes), soit au thème CSS via `counter-reset`
 * + `::before`. De même les puces appartiennent à `<ul>`.
 *
 * Préfixes ciblés :
 *  - **Numéro** : 1 ou 2 chiffres + `.` / `)` / `°` + espace.
 *    Exemples : « 1. », « 23) », « 5° ». Refusés : « 100. »
 *    (trois chiffres = peu probable pour un heading) et « 1.X »
 *    (pas d'espace = probablement un decimal ou une référence
 *    « 1.0 » volontaire).
 *  - **Puce** : `•` `‣` `►` `▸` `*` + espace.
 *  - **Tiret** : `-` `–` `—` + espace.
 *
 * Le strip s'applique à la première occurrence du préfixe en tête
 * du `textContent` (NBSP normalisé). Si le préfixe est emballé dans
 * un inline (`<h2><strong>1.</strong> Texte</h2>`), le walk DOM le
 * trouve et le retire ; un wrapper devenu vide reste en place
 * (sera nettoyé par R6 / R15 si nécessaire).
 *
 * Position pipeline : entre R15 et R9. Place :
 *  - **Après R15** : la fusion d'inlines a déjà tassé d'éventuels
 *    `<strong></strong>` adjacents au préfixe.
 *  - **Avant R9** : si un `<h4>` était `« 1. <img> »` (préfixe + image
 *    isolée), strip puis R9 unwrap → l'image sort naturellement.
 *  - **Avant R11/R12** : R11 promote / démote des h4 ; mieux qu'ils
 *    reçoivent un texte propre sans préfixe.
 *  - **Avant R2** : R2 retire un heading devenu vide après strip.
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
 * Règle R16 : strip des préfixes ordinaux/puces sur les headings.
 */
final class StripHeadingPrefixRule implements RuleInterface {

	/**
	 * Tags ciblés. h1-h6 inclus — y compris h4 (même si R11/R12/R9
	 * les transforment plus tard, on ne veut pas que le préfixe
	 * survive dans le `<figcaption>` ou le `<p class="chapo">` qui
	 * naît du h4).
	 *
	 * @var list<string>
	 */
	private const HEADING_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/**
	 * Regex unifié de détection de préfixe. Capture le préfixe ENTIER
	 * (espaces de séparation compris) dans le groupe 0. Unicode-aware.
	 *
	 *  - `\d{1,2}\s*[.)°]\s+` : ordinaux numériques (1. / 23) / 5°)
	 *  - `[•‣►▸*]\s+`        : puces (U+2022, U+2023, U+25BA, U+25B8, *)
	 *  - `[-–—]\s+`           : tirets ASCII / demi-cadratin / cadratin
	 */
	private const PREFIX_REGEX = '/^(?:\d{1,2}\s*[.)°]\s+|[\x{2022}\x{2023}\x{25BA}\x{25B8}\*]\s+|[-\x{2013}\x{2014}]\s+)/u';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R16';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Préfixes de titre (numéros, puces)', '100son-html-normalizer' );
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

		foreach ( self::HEADING_TAGS as $tag ) {
			// Snapshot avant mutation.
			$headings = array();
			foreach ( $doc->getElementsByTagName( $tag ) as $heading ) {
				$headings[] = $heading;
			}
			foreach ( $headings as $heading ) {
				if ( $heading instanceof DOMElement && null !== $heading->parentNode ) {
					self::strip_prefix_if_any( $heading );
				}
			}
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
				if ( ! $heading instanceof DOMElement ) {
					continue;
				}
				if ( null !== self::detect_prefix_length( $heading ) ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/**
	 * Détecte un préfixe strippable en tête d'un heading. Retourne
	 * le nombre de caractères Unicode à retirer (incluant les
	 * espaces de séparation), ou null si pas de préfixe.
	 *
	 * @param DOMElement $heading Heading testé.
	 * @return int|null
	 */
	private static function detect_prefix_length( DOMElement $heading ): ?int {
		$text       = (string) $heading->textContent;
		$normalized = str_replace( "\xc2\xa0", ' ', $text );
		$ltrimmed   = ltrim( $normalized );
		if ( '' === $ltrimmed ) {
			return null;
		}
		if ( ! preg_match( self::PREFIX_REGEX, $ltrimmed, $m ) ) {
			return null;
		}
		$leading_ws    = mb_strlen( $normalized ) - mb_strlen( $ltrimmed );
		$prefix_length = mb_strlen( $m[0] );
		return $leading_ws + $prefix_length;
	}

	/**
	 * Retire le préfixe d'un heading s'il en a un.
	 *
	 * @param DOMElement $heading Heading cible.
	 * @return void
	 */
	private static function strip_prefix_if_any( DOMElement $heading ): void {
		$count = self::detect_prefix_length( $heading );
		if ( null === $count ) {
			return;
		}
		self::consume_leading_chars( $heading, $count );
	}

	/**
	 * Walk DOM en pré-ordre pour consommer les `$count` premiers
	 * caractères Unicode des text nodes descendants. Retourne le
	 * solde de caractères restant à consommer (0 quand le travail
	 * est terminé).
	 *
	 * @param DOMNode $node  Sous-arbre à parcourir.
	 * @param int     $count Nombre de caractères Unicode à retirer.
	 * @return int Solde restant.
	 */
	private static function consume_leading_chars( DOMNode $node, int $count ): int {
		if ( 0 === $count ) {
			return 0;
		}
		if ( $node instanceof DOMText ) {
			$text = (string) $node->nodeValue;
			$len  = mb_strlen( $text );
			$take = min( $count, $len );
			$node->nodeValue = mb_substr( $text, $take );
			return $count - $take;
		}
		if ( ! $node instanceof DOMElement ) {
			return $count;
		}
		// Snapshot avant mutation (le strip peut retirer un text node
		// vide via une autre passe, mais ici on se contente de
		// modifier la valeur).
		$children = array();
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			$count = self::consume_leading_chars( $child, $count );
			if ( 0 === $count ) {
				return 0;
			}
		}
		return $count;
	}
}
