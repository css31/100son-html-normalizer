<?php
/**
 * R4 — PinterestArtifactsRule.
 *
 * Supprime tous les vestiges Pinterest dans le HTML :
 *  - Forme A : `<span data-pin-do>`, `<span data-pin-id>`, tout `<span data-pin-*>`
 *  - Forme B : `<span style="…z-index: 8675309…">…</span>` (signature canonique
 *    du bouton « Save » Pinterest, vérifiée 0 faux positif sur le corpus MMM —
 *    cf. PLUGIN_CONTEXT.md §6.7 et cahier §3.1 F2.R4 / §8 F2.R4).
 *
 * Le `<p>` parent éventuellement vidé sera ramassé par R1 en fin de pipeline.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;
use DOMElement;

/**
 * Règle R4 : suppression des artefacts Pinterest (formes A et B).
 *
 * Marquée `LossyRule` : retire physiquement le bouton « Save » et les
 * snippets `data-pin-*` du HTML, ce qui réduit `chars` et `words`. Sans
 * le marker, tout article touché par R4 passerait en `regression_pending`
 * sous le seuil `text_loss_pct = 0` (default).
 */
final class PinterestArtifactsRule implements RuleInterface, LossyRule {

	/**
	 * Signature canonique du bouton Pinterest dans l'attribut style.
	 */
	private const ZINDEX_SIGNATURE = 'z-index: 8675309';

	/**
	 * Préfixe d'attribut data-* spécifique Pinterest (forme A).
	 */
	private const DATA_ATTR_PREFIX = 'data-pin-';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R4';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Artefacts Pinterest', '100son-html-normalizer' );
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

		// Collecter avant suppression — modifier en cours d'itération sur DOMNodeList est risqué.
		$candidates = array();
		foreach ( $doc->getElementsByTagName( 'span' ) as $span ) {
			$candidates[] = $span;
		}

		foreach ( $candidates as $span ) {
			if ( ! $span instanceof DOMElement ) {
				continue;
			}
			if ( self::is_pinterest_artifact( $span ) ) {
				$span->parentNode?->removeChild( $span );
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
		foreach ( $doc->getElementsByTagName( 'span' ) as $span ) {
			if ( $span instanceof DOMElement && self::is_pinterest_artifact( $span ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Indique si un span est un artefact Pinterest (forme A ou B).
	 *
	 * @param DOMElement $span Span candidat.
	 * @return bool
	 */
	private static function is_pinterest_artifact( DOMElement $span ): bool {
		// Forme A : présence d'un attribut data-pin-*.
		if ( $span->hasAttributes() ) {
			foreach ( $span->attributes as $attr ) {
				$name = strtolower( (string) $attr->nodeName );
				if ( str_starts_with( $name, self::DATA_ATTR_PREFIX ) ) {
					return true;
				}
			}
		}

		// Forme B : signature z-index dans l'attribut style.
		if ( $span->hasAttribute( 'style' ) ) {
			$style = (string) $span->getAttribute( 'style' );
			// Comparaison insensible à la casse, tolérante aux espaces autour du `:`.
			$normalized = strtolower( (string) preg_replace( '/\s*:\s*/', ': ', $style ) );
			if ( str_contains( $normalized, self::ZINDEX_SIGNATURE ) ) {
				return true;
			}
		}

		return false;
	}
}
