<?php
/**
 * R12 — HeadingMixedToFigureRule.
 *
 * Convertit un `<h4>` qui mélange image(s) et texte (un détournement
 * courant dans le corpus migré SiteOrigin où le rédacteur emboîtait
 * directement l'image et sa légende dans le même titre) en une
 * `<figure>` regroupant les images suivies d'un `<figcaption>` unique
 * contenant le texte.
 *
 * Pattern ciblé :
 *
 *   <h4>
 *     <a href="big1.jpg"><img src="thumb1.jpg" alt="..."></a>
 *     <a href="big2.jpg"><img src="thumb2.jpg" alt="..."></a>
 *     Texte de légende qui décrit l'ensemble.
 *   </h4>
 *
 *   devient :
 *
 *   <figure>
 *     <a href="big1.jpg"><img src="thumb1.jpg" alt="..."></a>
 *     <a href="big2.jpg"><img src="thumb2.jpg" alt="..."></a>
 *     <figcaption>Texte de légende qui décrit l'ensemble.</figcaption>
 *   </figure>
 *
 * Mode **tolérant multi-images** (≥ 1 `<img>` matche) : la spec HTML5
 * permet plusieurs `<img>` dans un même `<figure>` partageant une
 * `<figcaption>` unique (cas typique « avant/après ») — voir l'exemple
 * normatif de la spec sur la figure. SO to Blocks pourra ensuite
 * convertir en bloc `core/gallery` ou `core/image` selon le compte.
 *
 * Critères de match (cumulatifs) :
 *  - élément `<h4>` (et seulement h4 — convention corpus MMM-2,
 *    cohérente avec R11) ;
 *  - contient ≥ 1 descendant `<img>` ;
 *  - `textContent` (NBSP normalisé, trim) non vide après retrait des
 *    contenus image — il faut une légende réelle à mettre dans le
 *    `<figcaption>`.
 *
 * Préservés dans la `<figcaption>` : tous les inlines du `<h4>` autres
 * que les wrappers d'image (`<em>`, `<strong>`, `<a>` purement textuel,
 * etc.). Les `<br>` et nœuds blancs en bordure de caption sont nettoyés
 * (séparateurs visuels parasites entre images et texte).
 *
 * Position pipeline (cf. PresetRegistry::PRESETS) : entre R9 et R11.
 * Invariants assurés :
 *  - R9 avant R12 → les `<h4>` image-seule sont déjà désencapsulés ;
 *    R12 n'agit que sur les h4 mixtes restants.
 *  - R12 avant R11 → R11 cherche le pattern *adjacent*
 *    (`<p><img></p><h4>texte</h4>`), R12 le pattern *inline* (image
 *    et texte dans le même h4). Pas de chevauchement, mais l'ordre
 *    garantit que les `<figure>` produites ici ne perturbent pas la
 *    détection d'adjacence de R11 en aval.
 *
 * Niveau HTML uniquement : aucune classe Gutenberg n'est injectée —
 * SO to Blocks produira ensuite `core/gallery` (si ≥ 2 imgs) ou
 * `core/image` (si 1 img) à partir de la `<figure>` propre.
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
 * Règle R12 : conversion h4 mixte (image+texte) → figure+figcaption.
 */
final class HeadingMixedToFigureRule implements RuleInterface {

	/**
	 * Niveau de titre ciblé. Convention héritée du corpus MMM-2 où
	 * `<h4>` est exclusivement détourné en légende (h2/h3 = vrais
	 * sous-titres). Aligné sur R11.
	 */
	private const HEADING_TAG = 'h4';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R12';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Titres mixtes image + légende', '100son-html-normalizer' );
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

		// Collecte avant mutation — modifier la live NodeList pendant
		// itération casse l'index (cf. R9/R10/R11 pour le même pattern).
		$headings = array();
		foreach ( $doc->getElementsByTagName( self::HEADING_TAG ) as $heading ) {
			$headings[] = $heading;
		}

		foreach ( $headings as $heading ) {
			if ( ! $heading instanceof DOMElement ) {
				continue;
			}
			if ( ! self::is_mixed_heading( $heading ) ) {
				continue;
			}
			self::split_into_figure( $doc, $heading );
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
			if ( $heading instanceof DOMElement && self::is_mixed_heading( $heading ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Indique si un `<h4>` est éligible (image + texte mixés à
	 * l'intérieur). Critères cumulatifs :
	 *  1. au moins un descendant `<img>` ;
	 *  2. après retrait virtuel des wrappers d'image, il reste du
	 *     texte non-blanc (NBSP-normalisé).
	 *
	 * @param DOMElement $heading Heading candidat.
	 * @return bool
	 */
	private static function is_mixed_heading( DOMElement $heading ): bool {
		$has_img = false;
		foreach ( $heading->getElementsByTagName( 'img' ) as $unused_img ) {
			$has_img = true;
			break;
		}
		unset( $unused_img );
		if ( ! $has_img ) {
			return false;
		}

		// `textContent` agrège TOUT le texte descendant (incl. alt n'est
		// pas dedans — c'est un attribut, pas un text node). On compte
		// donc directement le texte du h4 ; s'il n'est pas vide après
		// trim, c'est qu'il y a de la légende mixée avec l'image.
		return '' !== self::normalized_text_content( $heading );
	}

	/**
	 * Effectue la transformation : reconstruit un `<figure>` avec les
	 * enfants image-wrapper du `<h4>` suivis d'un `<figcaption>` avec
	 * le reste, puis remplace le `<h4>` dans le DOM.
	 *
	 * @param DOMDocument $doc     Document hôte.
	 * @param DOMElement  $heading `<h4>` à transformer (validé en amont).
	 * @return void
	 */
	private static function split_into_figure( DOMDocument $doc, DOMElement $heading ): void {
		$parent = $heading->parentNode;
		if ( null === $parent ) {
			return;
		}

		// Partition des enfants en 2 buckets (image-wrappers vs reste).
		// L'ordre d'apparition initial est préservé dans chaque bucket.
		$image_nodes   = array();
		$caption_nodes = array();
		$child         = $heading->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			if ( self::is_image_wrapper( $child ) ) {
				$image_nodes[] = $child;
			} else {
				$caption_nodes[] = $child;
			}
			$child = $next;
		}

		// Nettoyage bordures de la caption : on retire les `<br>`,
		// les commentaires et les text nodes blancs en début/fin
		// (séparateurs visuels parasites entre image et texte).
		$caption_nodes = self::trim_caption_boundaries( $caption_nodes );

		// Si après nettoyage la caption est vide, on est en fait sur
		// un cas R9 (image seule, juste des séparateurs autour). Ne
		// rien faire ici — R9 a déjà traité, ou traitera (selon ordre).
		if ( array() === $caption_nodes ) {
			return;
		}

		$figure     = $doc->createElement( 'figure' );
		$figcaption = $doc->createElement( 'figcaption' );

		// Déplacement effectif (appendChild détache du h4 et rattache
		// au figure / figcaption).
		foreach ( $image_nodes as $node ) {
			$figure->appendChild( $node );
		}
		foreach ( $caption_nodes as $node ) {
			$figcaption->appendChild( $node );
		}

		// Trim de l'éventuel whitespace en tête du premier text node
		// de la caption (« ␣Texte… » → « Texte… »).
		self::trim_first_text_node( $figcaption );

		$figure->appendChild( $figcaption );
		$parent->replaceChild( $figure, $heading );
	}

	/**
	 * Indique si un nœud est un « wrapper d'image » qu'on déplace dans
	 * la `<figure>` plutôt que dans la `<figcaption>`.
	 *
	 *  - `<img>` direct ;
	 *  - `<a>` qui contient une image descendante ;
	 *  - `<picture>` qui contient une image descendante ;
	 *  - `<figure>` qui contient une image descendante (cas exotique,
	 *    figure imbriquée à plat).
	 *
	 * Les autres éléments (`<em>`, `<strong>`, `<span>`, text nodes,
	 * commentaires…) tombent dans la caption.
	 *
	 * @param DOMNode $node Nœud testé.
	 * @return bool
	 */
	private static function is_image_wrapper( DOMNode $node ): bool {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}
		$tag = strtolower( $node->nodeName );
		if ( 'img' === $tag ) {
			return true;
		}
		if ( in_array( $tag, array( 'a', 'picture', 'figure' ), true ) ) {
			foreach ( $node->getElementsByTagName( 'img' ) as $unused_img ) {
				unset( $unused_img );
				return true;
			}
		}
		return false;
	}

	/**
	 * Retire des extrémités d'un tableau de nœuds les séparateurs
	 * visuels parasites : `<br>`, commentaires et text nodes blancs
	 * (NBSP-normalisés). S'arrête au premier nœud porteur de contenu
	 * réel à chaque extrémité.
	 *
	 * @param list<DOMNode> $nodes Tableau ordonné de nœuds candidats à figcaption.
	 * @return list<DOMNode> Tableau nettoyé (potentiellement vide).
	 */
	private static function trim_caption_boundaries( array $nodes ): array {
		while ( array() !== $nodes && self::is_caption_boundary_noise( reset( $nodes ) ) ) {
			array_shift( $nodes );
		}
		while ( array() !== $nodes && self::is_caption_boundary_noise( end( $nodes ) ) ) {
			array_pop( $nodes );
		}
		return $nodes;
	}

	/**
	 * Détecte les nœuds inutiles en bordure de caption.
	 *
	 * @param DOMNode $node Nœud testé.
	 * @return bool
	 */
	private static function is_caption_boundary_noise( DOMNode $node ): bool {
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			return true;
		}
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$txt = str_replace( "\xc2\xa0", ' ', (string) $node->nodeValue );
			return '' === trim( $txt );
		}
		if ( $node instanceof DOMElement && 'br' === strtolower( $node->nodeName ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Trime le whitespace de tête du premier text node descendant d'un
	 * `<figcaption>` fraîchement construit, pour éviter qu'une caption
	 * débute par « ␣ texte » alors que le séparateur visuel d'origine
	 * (le whitespace text node initialement entre image et texte) a
	 * déjà été retiré par `trim_caption_boundaries`.
	 *
	 * @param DOMElement $figcaption `<figcaption>` cible.
	 * @return void
	 */
	private static function trim_first_text_node( DOMElement $figcaption ): void {
		$node = $figcaption->firstChild;
		while ( null !== $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$txt = str_replace( "\xc2\xa0", ' ', (string) $node->nodeValue );
				$node->nodeValue = ltrim( $txt );
				return;
			}
			if ( $node instanceof DOMElement && $node->firstChild instanceof DOMNode ) {
				// Descend dans l'élément (ex. `<a>texte`) pour trimer
				// son premier text node interne.
				$node = $node->firstChild;
				continue;
			}
			return;
		}
	}

	/**
	 * Retourne le `textContent` d'un nœud après normalisation NBSP et
	 * trim. Convention partagée avec R9/R10/R11.
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
