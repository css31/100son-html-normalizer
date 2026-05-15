<?php
/**
 * R11 — HeadingCaptionToFigcaptionRule.
 *
 * Convertit les `<h4>` utilisés comme légende d'une image qui les
 * précède en `<figcaption>` attachée à un `<figure>` englobant
 * l'image. Pattern ciblé (cas typique du corpus migré depuis
 * SiteOrigin/Classic Editor où le rédacteur a détourné `<h4>`
 * en légende faute de `<figcaption>`) :
 *
 *   <p><a href="big.jpg"><img src="thumb.jpg" alt="…"></a></p>
 *   <h4>Texte de légende</h4>
 *
 *   devient :
 *
 *   <figure>
 *     <a href="big.jpg"><img src="thumb.jpg" alt="…"></a>
 *     <figcaption>Texte de légende</figcaption>
 *   </figure>
 *
 * Critères de match (cumulatifs) :
 *  - élément `<h4>` (et seulement h4 — h2/h3/h5/h6 = vrais sous-titres,
 *    pas touchés) ;
 *  - le `<h4>` contient du texte (sinon R2/R9 s'en chargent) ;
 *  - son frère élément précédent immédiat est un `<p>` (les nœuds texte
 *    whitespace entre les deux sont tolérés) ;
 *  - ce `<p>` contient exactement une `<img>` descendante (multi-image
 *    intentionnellement non traité — 2 cas corpus MMM-2, mapping
 *    légende→image non trivial) ;
 *  - le `textContent` du `<p>` (NBSP normalisé, trim) est vide :
 *    pas de texte autour de l'image.
 *
 * Préserve dans la `<figcaption>` tous les inlines du `<h4>` d'origine
 * (`<a>`, `<em>`, `<strong>`, `<br>`, etc.) et préserve dans la
 * `<figure>` tous les enfants du `<p>` (typiquement `<a>` wrapper de
 * lightbox, mais aussi `<picture>`, `<figure>` interne, etc.).
 *
 * Position pipeline (cf. PresetRegistry::PRESETS) : entre R9 et R10.
 * Invariants assurés :
 *  - R9 avant R11 → tout `<h4>` restant a forcément du texte (les
 *    h4-image-seule sont déjà désencapsulés).
 *  - R11 avant R10 → le `<p>` autour de l'image est encore présent
 *    quand R11 cherche l'adjacence ; après R10 le signal serait perdu.
 *
 * Niveau HTML uniquement : aucune classe Gutenberg (`wp-block-image`,
 * `wp-element-caption`, …) n'est injectée — c'est le rôle de SO to
 * Blocks de produire la block grammar à partir du `<figure>` propre.
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

/**
 * Règle R11 : conversion h4-légende → figcaption.
 */
final class HeadingCaptionToFigcaptionRule implements RuleInterface {

	/**
	 * Niveau de titre ciblé. Convention héritée du corpus MMM-2 où
	 * `<h4>` est exclusivement détourné en légende (h2/h3 = vrais
	 * sous-titres). Si un autre corpus utilise un autre niveau, ce
	 * choix sera à reconsidérer (option de la règle, v1.1+).
	 */
	private const HEADING_TAG = 'h4';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R11';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Titres légendes d\'images', '100son-html-normalizer' );
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

		// Collecte avant mutation : modifier la live NodeList pendant
		// itération est risqué (cf. R9/R10 pour le même pattern).
		$headings = array();
		foreach ( $doc->getElementsByTagName( self::HEADING_TAG ) as $heading ) {
			$headings[] = $heading;
		}

		foreach ( $headings as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}
			// L'élément a pu être retiré (replaceChild) par une
			// transformation précédente sur un voisin.
			if ( null === $heading->parentNode ) {
				continue;
			}
			$paragraph = self::find_caption_paragraph( $heading );
			if ( null !== $paragraph ) {
				self::collapse_into_figure( $doc, $paragraph, $heading );
				continue;
			}
			// h4 orphelin (sans image-p précédente) : démotion
			// contextuelle en chapô-crédit ou en `<p><strong>`.
			self::handle_orphan_heading( $doc, $heading );
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
		foreach ( $doc->getElementsByTagName( self::HEADING_TAG ) as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}
			if ( null !== self::find_caption_paragraph( $heading ) ) {
				++$count;
				continue;
			}
			// h4 orphelin avec contenu texte non-vide et sans image
			// interne sera transformé par `handle_orphan_heading`.
			if ( '' === self::normalized_text_content( $heading ) ) {
				continue;
			}
			$has_img = false;
			foreach ( $heading->getElementsByTagName( 'img' ) as $unused_img ) {
				$has_img = true;
				break;
			}
			unset( $unused_img );
			if ( ! $has_img ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Retourne le `<p>` éligible précédant immédiatement le `<h4>`
	 * fourni, ou null si les critères de match ne sont pas remplis.
	 *
	 * Tolère un (ou plusieurs) nœud texte whitespace entre le `<p>`
	 * et le `<h4>` (sortie typique de l'éditeur classique WP). Tout
	 * autre nœud intercalaire (élément, commentaire, texte non-blanc)
	 * abandonne le match.
	 *
	 * @param DOMElement $heading `<h4>` candidat.
	 * @return DOMElement|null
	 */
	private static function find_caption_paragraph( DOMElement $heading ): ?DOMElement {
		// (1) h4 doit avoir du texte (sinon R2/R9 le traitent ailleurs).
		if ( '' === self::normalized_text_content( $heading ) ) {
			return null;
		}

		// (2) Remonter via previousSibling en tolérant les text nodes blancs.
		$sibling = $heading->previousSibling;
		while ( null !== $sibling ) {
			if ( $sibling instanceof DOMElement ) {
				break;
			}
			if ( XML_TEXT_NODE === $sibling->nodeType ) {
				$txt = str_replace( "\xc2\xa0", ' ', (string) $sibling->nodeValue );
				if ( '' !== trim( $txt ) ) {
					return null;
				}
				$sibling = $sibling->previousSibling;
				continue;
			}
			// Commentaire ou autre type de nœud → bloque l'adjacence.
			return null;
		}

		if ( ! $sibling instanceof DOMElement || 'p' !== $sibling->nodeName ) {
			return null;
		}

		// (3) `<p>` doit contenir exactement une `<img>` descendante.
		$img_count = 0;
		foreach ( $sibling->getElementsByTagName( 'img' ) as $unused_img ) {
			++$img_count;
			if ( $img_count > 1 ) {
				return null;
			}
		}
		unset( $unused_img );
		if ( 1 !== $img_count ) {
			return null;
		}

		// (4) Pas de texte autour de l'image (NBSP toléré).
		if ( '' !== self::normalized_text_content( $sibling ) ) {
			return null;
		}

		return $sibling;
	}

	/**
	 * Effectue la transformation : remplace `<p>` par un `<figure>`
	 * recevant les enfants du `<p>` puis une `<figcaption>` recevant
	 * les enfants du `<h4>`. Supprime le `<h4>` du parent.
	 *
	 * @param DOMDocument $doc       Document hôte (pour `createElement`).
	 * @param DOMElement  $paragraph `<p>` à transformer (validé en amont).
	 * @param DOMElement  $heading   `<h4>` à consommer (validé en amont).
	 * @return void
	 */
	private static function collapse_into_figure( DOMDocument $doc, DOMElement $paragraph, DOMElement $heading ): void {
		$parent = $paragraph->parentNode;
		if ( null === $parent ) {
			return;
		}

		$figure     = $doc->createElement( 'figure' );
		$figcaption = $doc->createElement( 'figcaption' );

		// Déplace les enfants du `<p>` dans `<figure>` (préserve l'ordre
		// et l'éventuel wrapper `<a>`/`<figure>` autour de l'image).
		/** @var DOMNode|null $child */
		$child = $paragraph->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$figure->appendChild( $child );
			$child = $next;
		}

		// Déplace les enfants du `<h4>` dans `<figcaption>` (préserve
		// les inlines : `<a>`, `<em>`, `<strong>`, `<br>`, etc.).
		$child = $heading->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$figcaption->appendChild( $child );
			$child = $next;
		}

		$figure->appendChild( $figcaption );

		// Substitution dans l'arbre + suppression du `<h4>` consommé.
		$parent->replaceChild( $figure, $paragraph );
		$heading_parent = $heading->parentNode;
		if ( null !== $heading_parent ) {
			$heading_parent->removeChild( $heading );
		}
	}

	/**
	 * Traite un `<h4>` qui n'a PAS d'image-paragraphe précédent (cas
	 * non couvert par la transformation principale R11 / figure-
	 * caption). Convention éditoriale MMM : un `<h4>` n'est jamais un
	 * vrai sous-titre de section — c'est un détournement typographique
	 * (un « paragraphe gras » du rédacteur). On le démote en fonction
	 * du contexte :
	 *
	 *  - **Si le `<h4>` suit immédiatement un chapô-lead** (`<p class=
	 *    "chapo">` sans autre `<p class="chapo">` avant lui dans le
	 *    parent) : promotion en chapô-crédit (`<p class="chapo">texte</p>`).
	 *    `ChapoFormatter::clean()` strippe le gras éventuel — c'est le
	 *    pattern « signature de rédaction » MMM.
	 *
	 *  - **Sinon** : démotion en paragraphe gras
	 *    (`<p><strong>texte</strong></p>`). Le h4 servait visuellement
	 *    de texte fort, on rend la sémantique explicite.
	 *
	 * Cas écartés (renvoient sans rien faire) :
	 *  - h4 vide (R2 s'en charge) ;
	 *  - h4 contenant une `<img>` (R9 ou R12 territoire).
	 *
	 * @param DOMDocument $doc Document hôte.
	 * @param DOMElement  $h4  `<h4>` orphelin.
	 * @return void
	 */
	private static function handle_orphan_heading( DOMDocument $doc, DOMElement $h4 ): void {
		if ( '' === self::normalized_text_content( $h4 ) ) {
			return;
		}
		foreach ( $h4->getElementsByTagName( 'img' ) as $unused_img ) {
			unset( $unused_img );
			return;
		}

		if ( self::is_first_credit_position_after_chapo( $h4 ) ) {
			self::demote_to_chapo_credit( $doc, $h4 );
			return;
		}
		self::demote_to_bold_paragraph( $doc, $h4 );
	}

	/**
	 * Indique si le `<h4>` est dans la position « premier crédit après
	 * un chapô qui n'a qu'un p » :
	 *  - frère élément significatif précédent = `<p class="chapo">` ;
	 *  - frère élément significatif AVANT ce chapô-p n'est PAS lui-
	 *    même `<p class="chapo">` (sinon le chapô a déjà ≥ 2 p,
	 *    typiquement lead + crédit déjà étendu par R14).
	 *
	 * @param DOMElement $h4 Heading orphelin.
	 * @return bool
	 */
	private static function is_first_credit_position_after_chapo( DOMElement $h4 ): bool {
		$prev = self::previous_significant_element_sibling( $h4 );
		if ( null === $prev || ! self::is_chapo_paragraph( $prev ) ) {
			return false;
		}
		$prev_prev = self::previous_significant_element_sibling( $prev );
		if ( null === $prev_prev ) {
			return true;
		}
		return ! self::is_chapo_paragraph( $prev_prev );
	}

	/**
	 * Indique si un élément est un `<p>` portant la classe `chapo`.
	 *
	 * @param DOMElement $element Élément testé.
	 * @return bool
	 */
	private static function is_chapo_paragraph( DOMElement $element ): bool {
		if ( 'p' !== strtolower( $element->nodeName ) ) {
			return false;
		}
		$classes = $element->getAttribute( 'class' );
		if ( '' === $classes ) {
			return false;
		}
		$tokens = preg_split( '/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $tokens ) {
			return false;
		}
		return in_array( 'chapo', $tokens, true );
	}

	/**
	 * Walk arrière pour trouver le premier frère élément
	 * « significatif » :
	 *  - text nodes blancs sautés ;
	 *  - commentaires sautés ;
	 *  - `<p>` vides (whitespace/NBSP only) sautés ;
	 *  - sinon, retourne le premier élément trouvé (peut être null).
	 *
	 * @param DOMNode $node Point de départ.
	 * @return DOMElement|null
	 */
	private static function previous_significant_element_sibling( DOMNode $node ): ?DOMElement {
		$prev = $node->previousSibling;
		while ( null !== $prev ) {
			if ( XML_TEXT_NODE === $prev->nodeType || XML_COMMENT_NODE === $prev->nodeType ) {
				$prev = $prev->previousSibling;
				continue;
			}
			if ( $prev instanceof DOMElement ) {
				if ( 'p' === strtolower( $prev->nodeName )
					&& '' === self::normalized_text_content( $prev )
				) {
					$prev = $prev->previousSibling;
					continue;
				}
				return $prev;
			}
			$prev = $prev->previousSibling;
		}
		return null;
	}

	/**
	 * Promeut un `<h4>` orphelin en chapô-crédit
	 * (`<p class="chapo">texte</p>`). Le formatage inline est strippé
	 * par `ChapoFormatter::clean()` — notamment d'éventuels `<strong>`
	 * qui contredisent la sémantique chapô (« on retire le gras »).
	 *
	 * @param DOMDocument $doc Document hôte.
	 * @param DOMElement  $h4  Heading à promouvoir.
	 * @return void
	 */
	private static function demote_to_chapo_credit( DOMDocument $doc, DOMElement $h4 ): void {
		$parent = $h4->parentNode;
		if ( null === $parent ) {
			return;
		}
		$paragraph = $doc->createElement( 'p' );
		$paragraph->setAttribute( 'class', 'chapo' );
		/** @var DOMNode|null $child */
		$child = $h4->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$paragraph->appendChild( $child );
			$child = $next;
		}
		$parent->replaceChild( $paragraph, $h4 );
		ChapoFormatter::clean( $paragraph );
	}

	/**
	 * Démote un `<h4>` orphelin en paragraphe gras
	 * (`<p><strong>texte</strong></p>`). Le contenu (text nodes,
	 * inlines comme `<a>`) est conservé tel quel dans le `<strong>`.
	 *
	 * @param DOMDocument $doc Document hôte.
	 * @param DOMElement  $h4  Heading à démoter.
	 * @return void
	 */
	private static function demote_to_bold_paragraph( DOMDocument $doc, DOMElement $h4 ): void {
		$parent = $h4->parentNode;
		if ( null === $parent ) {
			return;
		}
		$paragraph = $doc->createElement( 'p' );
		$strong    = $doc->createElement( 'strong' );
		$paragraph->appendChild( $strong );
		/** @var DOMNode|null $child */
		$child = $h4->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$strong->appendChild( $child );
			$child = $next;
		}
		$parent->replaceChild( $paragraph, $h4 );
	}

	/**
	 * Retourne le `textContent` d'un nœud après normalisation NBSP
	 * et trim. Convention partagée avec R9/R10 pour les checks
	 * « pseudo-vide ».
	 *
	 * @param DOMNode $node Nœud.
	 * @return string Texte normalisé (vide = pas de texte significatif).
	 */
	private static function normalized_text_content( DOMNode $node ): string {
		$text = (string) $node->textContent;
		$text = str_replace( "\xc2\xa0", ' ', $text );
		return trim( $text );
	}
}
