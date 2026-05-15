<?php
/**
 * R1 — EmptyParagraphsRule.
 *
 * Supprime les `<p>` vides ou contenant uniquement du blanc / `&nbsp;`.
 *
 * **Cas particulier Gutenberg** : quand le `<p>` vide est encadre par les
 * commentaires de bloc `<!-- wp:paragraph -->` ... `<!-- /wp:paragraph -->`,
 * on retire aussi ces deux commentaires (et le whitespace residuel entre
 * eux). Sinon le bloc Gutenberg « squelette » reste dans `post_content`
 * et provoque un bloc fantome (visible comme une ligne vide invalide
 * dans l'editeur).
 *
 * Cf. cahier §3.1 F2.R1 et §8 F2.R1.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;
use DOMComment;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Règle R1 : suppression des paragraphes vides.
 */
final class EmptyParagraphsRule implements RuleInterface {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R1';
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
		$paragraphs = array();
		foreach ( $doc->getElementsByTagName( 'p' ) as $p ) {
			$paragraphs[] = $p;
		}

		foreach ( $paragraphs as $p ) {
			if ( ! $p instanceof DOMElement ) {
				continue;
			}
			if ( ! self::is_empty_paragraph( $p ) ) {
				continue;
			}

			// Si le <p> vide est encadre par les commentaires Gutenberg de
			// bloc paragraph, on supprime tout le bloc (commentaire ouvrant
			// + <p> + commentaire fermant + whitespace residuel entre eux)
			// pour eviter un bloc squelette inerte.
			$opening = self::find_preceding_gutenberg_open_comment( $p );
			$closing = self::find_following_gutenberg_close_comment( $p );
			if ( null !== $opening && null !== $closing ) {
				self::remove_range_inclusive( $opening, $closing );
				continue;
			}

			// Sinon, suppression isolee du <p>.
			$p->parentNode?->removeChild( $p );
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
			if ( $p instanceof DOMElement && self::is_empty_paragraph( $p ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Trouve le commentaire Gutenberg `<!-- wp:paragraph -->` (ou variante
	 * avec attrs JSON `<!-- wp:paragraph {"align":"center"} -->`) qui
	 * precede directement le `<p>` dans le DOM, en sautant uniquement les
	 * noeuds texte de whitespace pur. Retourne null sinon.
	 *
	 * @param DOMNode $p Paragraphe vide candidat.
	 * @return ?DOMComment
	 */
	private static function find_preceding_gutenberg_open_comment( DOMNode $p ): ?DOMComment {
		$cur = self::previous_sibling_skipping_whitespace( $p );
		if ( ! $cur instanceof DOMComment ) {
			return null;
		}
		// Tolere les espaces autour ; la signature Gutenberg est
		// `wp:paragraph` eventuellement suivi d'un blanc (puis JSON d'attrs).
		if ( 1 === preg_match( '/^\s*wp:paragraph(\s|$)/', $cur->data ) ) {
			return $cur;
		}
		return null;
	}

	/**
	 * Pendant du helper precedent pour le commentaire fermant
	 * `<!-- /wp:paragraph -->`. Pas d'attrs possibles sur un fermant Gutenberg.
	 *
	 * @param DOMNode $p Paragraphe vide candidat.
	 * @return ?DOMComment
	 */
	private static function find_following_gutenberg_close_comment( DOMNode $p ): ?DOMComment {
		$cur = self::next_sibling_skipping_whitespace( $p );
		if ( ! $cur instanceof DOMComment ) {
			return null;
		}
		if ( 1 === preg_match( '#^\s*/wp:paragraph\s*$#', $cur->data ) ) {
			return $cur;
		}
		return null;
	}

	/**
	 * Sibling precedent en sautant les `DOMText` de whitespace pur (retour-
	 * ligne / espace introduit par la lecture HTML formatee). Ne saute PAS
	 * les autres types de noeuds — un commentaire intermediaire, par
	 * exemple, casse la chaine et fait echouer la detection (intentionnel :
	 * un bloc `wp:paragraph` est forcement adjacent a son `<p>`).
	 *
	 * @param DOMNode $node Noeud de depart.
	 * @return ?DOMNode
	 */
	private static function previous_sibling_skipping_whitespace( DOMNode $node ): ?DOMNode {
		$cur = $node->previousSibling;
		while ( $cur instanceof DOMText && '' === trim( $cur->wholeText ) ) {
			$cur = $cur->previousSibling;
		}
		return $cur;
	}

	/**
	 * Pendant `next` du helper precedent.
	 *
	 * @param DOMNode $node Noeud de depart.
	 * @return ?DOMNode
	 */
	private static function next_sibling_skipping_whitespace( DOMNode $node ): ?DOMNode {
		$cur = $node->nextSibling;
		while ( $cur instanceof DOMText && '' === trim( $cur->wholeText ) ) {
			$cur = $cur->nextSibling;
		}
		return $cur;
	}

	/**
	 * Supprime tous les noeuds de `$start` a `$end` inclus (en parcourant
	 * `nextSibling`). On suppose que `$start` et `$end` ont le meme parent
	 * (verifie par les helpers ci-dessus qui ne quittent pas la fratrie).
	 *
	 * @param DOMNode $start Premier noeud a supprimer.
	 * @param DOMNode $end   Dernier noeud a supprimer.
	 * @return void
	 */
	private static function remove_range_inclusive( DOMNode $start, DOMNode $end ): void {
		$parent = $start->parentNode;
		if ( null === $parent ) {
			return;
		}
		$cur = $start;
		while ( null !== $cur ) {
			$next = $cur->nextSibling;
			$parent->removeChild( $cur );
			if ( $cur === $end ) {
				return;
			}
			$cur = $next;
		}
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
		static $structural = array(
			'img',
			'hr',
			'br',
			'iframe',
			'video',
			'audio',
			'embed',
			'object',
			'picture',
			'source',
		);
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
