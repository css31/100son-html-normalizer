<?php
/**
 * R6 — RemoveInlineStylesRule.
 *
 * Supprime les attributs `style="..."` du HTML.
 *
 * Option `keep_text_align` (booleen, defaut true) :
 *  - true : preserve la declaration `text-align: <valeur>` et drop tout le reste ;
 *           si seul `text-align` reste, l'attribut style est conserve avec uniquement cette declaration.
 *           Si rien ne reste, l'attribut est entierement supprime.
 *  - false : strip integralement l'attribut style sans exception.
 *
 * **Cleanup post-strip des `<span>` orphelins** : un `<span style="...">`
 * dont le `style` est l'unique attribut devient `<span>` apres le strip —
 * un container semantique-neutre qui ne fait plus rien (la balise `<span>`
 * en HTML n'a aucune semantique en soi, elle ne sert qu'a porter des
 * attributs `style`/`class`/`id`). On l'unwrap (le retire en preservant
 * son contenu). Limite ciblee au seul `<span>` — `<div>`, `<font>`,
 * `<strong>`, `<em>`, etc. portent une semantique ou un layout qu'on
 * conserve quel que soit l'etat des attributs.
 *
 * **Exception `core/image` Gutenberg** : les `<img>` enfants directs d'un
 * `<figure>` portant la classe `wp-block-image` sont **ignores** par la regle.
 * Leur attribut `style="aspect-ratio:...;width:...;height:...;object-fit:..."`
 * est synchronise avec le JSON `<!-- wp:image { width, height, aspectRatio,
 * scale } -->` en amont du bloc et la classe `is-resized` sur le `<figure>`
 * parent. Retirer le `style` seul desynchroniserait ces trois fois ce qui
 * declenche un "contenu invalide" Gutenberg a la prochaine ouverture du
 * bloc dans l'editeur. Cf. CLAUDE.md §6 (pieges) — invariant a respecter.
 *
 * Cf. cahier section 3.1 F2.R6 et section 8 F2.R6.
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
 * Preset R6 : suppression des styles inline.
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
		return 'R6';
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
			if ( self::is_img_in_wp_block_image( $el ) ) {
				continue;
			}
			if ( ! $this->keep_text_align ) {
				$el->removeAttribute( 'style' );
			} else {
				$filtered = self::keep_only_text_align( (string) $el->getAttribute( 'style' ) );
				if ( '' === $filtered ) {
					$el->removeAttribute( 'style' );
				} else {
					$el->setAttribute( 'style', $filtered );
				}
			}

			// Post-strip cleanup : `<span>` qui n'a plus aucun attribut →
			// container semantique-neutre, on le retire en preservant son
			// contenu. Limite stricte aux `<span>` — voir docblock.
			if (
				'span' === strtolower( $el->tagName )
				&& 0 === $el->attributes->length
			) {
				self::unwrap_element( $el );
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
	 *
	 * Les `<img>` enfants d'un `<figure class="wp-block-image …">` sont exclus
	 * du comptage, parite avec l'exception appliquee dans `apply()`.
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
		$count = 0;
		foreach ( $styled as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			if ( self::is_img_in_wp_block_image( $node ) ) {
				continue;
			}
			if ( ! $this->keep_text_align ) {
				++$count;
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
	 * Retire un element du DOM en preservant son contenu — l'element est
	 * remplace par la sequence de ses enfants (dans le meme ordre, au meme
	 * endroit). Cas d'usage : un `<span>` sans aucun attribut, qu'on souhaite
	 * effacer comme container vide tout en gardant son texte et ses enfants
	 * lies a la position originale.
	 *
	 * Tolerant aux elements sans parent (no-op silencieux) — defensif pour
	 * eviter une fatal sur un edge case de parsing.
	 *
	 * @param DOMElement $el Element a unwrap.
	 * @return void
	 */
	private static function unwrap_element( DOMElement $el ): void {
		$parent = $el->parentNode;
		if ( null === $parent ) {
			return;
		}
		while ( null !== $el->firstChild ) {
			$parent->insertBefore( $el->firstChild, $el );
		}
		$parent->removeChild( $el );
	}

	/**
	 * Indique si un element est un `<img>` enfant direct d'un `<figure>`
	 * portant la classe CSS `wp-block-image` — autrement dit le `<img>` d'un
	 * bloc Gutenberg `core/image` natif.
	 *
	 * Pourquoi : l'attribut `style="aspect-ratio:...;width:...;height:..."`
	 * de ces `<img>` est synchronise avec le JSON `<!-- wp:image {...} -->`
	 * en amont et la classe `is-resized` du `<figure>` parent. Le retirer
	 * isolement casse l'invariant Gutenberg → bloc affiche en "contenu
	 * invalide" a la reouverture dans l'editeur. Cf. CLAUDE.md §6.
	 *
	 * Le match de classe se fait par regex avec frontiere de mot (espace
	 * ou debut/fin) pour eviter les faux positifs sur d'eventuelles classes
	 * comme `wp-block-image-foo`.
	 *
	 * @param DOMElement $el Element a tester.
	 * @return bool
	 */
	private static function is_img_in_wp_block_image( DOMElement $el ): bool {
		if ( 'img' !== strtolower( $el->tagName ) ) {
			return false;
		}
		$parent = $el->parentNode;
		if ( ! $parent instanceof DOMElement ) {
			return false;
		}
		if ( 'figure' !== strtolower( $parent->tagName ) ) {
			return false;
		}
		$classes = (string) $parent->getAttribute( 'class' );
		return 1 === preg_match( '/(?:^|\s)wp-block-image(?:\s|$)/', $classes );
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
