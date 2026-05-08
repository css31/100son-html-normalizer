<?php
/**
 * P7 — AsciiListRule.
 *
 * Detecte des listes ASCII et les convertit en <ul> / <ol>.
 *
 * Marqueurs activables (defaut : tous actives) :
 *   - dash       : `-`        -> <ul>
 *   - emdash     : `–`        -> <ul>
 *   - asterix    : `*`        -> <ul>
 *   - bullet     : `•`        -> <ul>
 *   - numeric    : `N.`       -> <ol>
 * Plus marqueurs custom utilisateur (1 par ligne) -> <ul>.
 *
 * Algorithme en deux passes :
 *  1. Pass intra-<p> : pour chaque <p> contenant des <br>, compter les
 *     fragments separes par <br> qui commencent par un marqueur. Si >= seuil,
 *     split le <p> et convertit les fragments bullets en <li> regroupes
 *     dans des <ul>/<ol>. Les fragments non-bullet restent en <p> separes.
 *  2. Pass document-level : trouve les sequences de <p> consecutifs dont le
 *     contenu entier commence par un marqueur. Si la sequence atteint le
 *     seuil, regroupe en <ul>/<ol>.
 *
 * Detection au niveau du texte effectif : le marqueur peut etre precede
 * de balises inline ouvrantes (span, em, strong, a...). C'est le premier
 * caractere de texte non-blanc qui compte.
 *
 * Preservation inline (β2 affine) : dans le <li> produit, les balises
 * semantiques (strong, em, b, i, a, code, q, cite, mark, sub, sup, abbr, time)
 * sont preservees avec leurs attributs semantiques (href, alt, ...).
 * Les conteneurs de presentation (span, font, div inline) sont desenrobes
 * SI ils n'ont aucun attribut semantique restant (apres nettoyage de
 * style/class/id qui sont supprimes lors de la copie).
 *
 * Cf. cahier section 3.1 F2.P7, section 4.4, section 14 hyp. 12.
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
 * Preset P7 : conversion des listes ASCII.
 */
final class AsciiListRule implements RuleInterface {

	/**
	 * Marqueurs activables avec leur regex et leur type de liste cible.
	 *
	 * Cle : nom du marqueur (cle de configuration utilisateur).
	 * Valeur : ['regex' => pattern PCRE, 'list_tag' => 'ul'|'ol'].
	 */
	private const MARKER_DEFINITIONS = [
		'dash'    => [ 'regex' => '/^-[\s\xc2\xa0]+/', 'list_tag' => 'ul' ],
		'emdash'  => [ 'regex' => '/^\xe2\x80\x93[\s\xc2\xa0]+/', 'list_tag' => 'ul' ], // U+2013
		'asterix' => [ 'regex' => '/^\*[\s\xc2\xa0]+/', 'list_tag' => 'ul' ],
		'bullet'  => [ 'regex' => '/^\xe2\x80\xa2[\s\xc2\xa0]+/', 'list_tag' => 'ul' ], // U+2022
		'numeric' => [ 'regex' => '/^\d+\.[\s\xc2\xa0]+/', 'list_tag' => 'ol' ],
	];

	/**
	 * Balises inline porteuses de sens (preservees).
	 */
	private const SEMANTIC_INLINE_TAGS = [
		'strong', 'em', 'b', 'i', 'a', 'code', 'q', 'cite', 'mark',
		'sub', 'sup', 'abbr', 'time',
	];

	/**
	 * Conteneurs de presentation (desenrobes si vides d'attributs semantiques).
	 */
	private const CONTAINER_TAGS = [ 'span', 'font' ];

	/**
	 * Attributs de presentation a supprimer lors de la copie d'un inline.
	 */
	private const PRESENTATION_ATTRIBUTES = [ 'style', 'class', 'id' ];

	/**
	 * Marqueurs actives par configuration.
	 *
	 * @var array<string, true>
	 */
	private array $enabled_markers;

	/**
	 * Marqueurs custom utilisateur.
	 *
	 * @var list<string>
	 */
	private array $custom_markers;

	/**
	 * Seuil minimum de marqueurs consecutifs declenchant la conversion.
	 *
	 * @var int
	 */
	private int $threshold;

	/**
	 * Constructor.
	 *
	 * @param array<string, bool> $markers        Mappage des marqueurs actives (cles : dash, emdash, asterix, bullet, numeric).
	 * @param int                 $threshold      Seuil >= 2.
	 * @param list<string>        $custom_markers Marqueurs custom utilisateur (1 par ligne).
	 */
	public function __construct(
		array $markers = [
			'dash'    => true,
			'emdash'  => true,
			'asterix' => true,
			'bullet'  => true,
			'numeric' => true,
		],
		int $threshold = 2,
		array $custom_markers = []
	) {
		$this->enabled_markers = [];
		foreach ( $markers as $key => $enabled ) {
			if ( $enabled && isset( self::MARKER_DEFINITIONS[ $key ] ) ) {
				$this->enabled_markers[ $key ] = true;
			}
		}
		$this->threshold      = max( 2, $threshold );
		$this->custom_markers = array_values(
			array_filter(
				array_map( 'strval', $custom_markers ),
				static fn( string $m ): bool => '' !== trim( $m )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'P7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Listes ASCII', '100son-html-normalizer' );
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

		// Pass 1 : intra-<p>.
		$this->process_intra_paragraphs( $doc, $wrapper );

		// Pass 2 : document-level (sequences de <p> consecutifs marker-prefixed).
		$this->process_document_level( $doc, $wrapper );

		return DomHtml::serialize_fragment( $doc );
	}

	/**
	 * Pass 1 : traite chaque <p> contenant des <br /> et possedant assez de
	 * fragments marker-prefixed pour declencher la conversion.
	 *
	 * @param DOMDocument $doc     Document parse.
	 * @param DOMNode     $wrapper Wrapper racine (parent des nodes top-level).
	 * @return void
	 */
	private function process_intra_paragraphs( DOMDocument $doc, DOMNode $wrapper ): void {
		$paragraphs = [];
		foreach ( $doc->getElementsByTagName( 'p' ) as $p ) {
			$paragraphs[] = $p;
		}

		foreach ( $paragraphs as $p ) {
			if ( ! $p instanceof DOMElement ) {
				continue;
			}
			// Optimisation : skip les <p> sans <br>.
			if ( 0 === $p->getElementsByTagName( 'br' )->length ) {
				continue;
			}

			$fragments = self::split_paragraph_on_br( $p );
			$bullet_count = 0;
			foreach ( $fragments as $frag ) {
				if ( null !== $this->detect_marker_in_fragment( $frag ) ) {
					$bullet_count++;
				}
			}

			if ( $bullet_count < $this->threshold ) {
				continue;
			}

			// Construit la sequence de remplacement et remplace le <p>.
			$replacements = $this->build_intra_replacement( $doc, $fragments );
			$parent       = $p->parentNode;
			if ( null === $parent ) {
				continue;
			}
			foreach ( $replacements as $node ) {
				$parent->insertBefore( $node, $p );
			}
			$parent->removeChild( $p );
		}
	}

	/**
	 * Pass 2 : groupe les sequences de <p> consecutifs marker-prefixed.
	 *
	 * @param DOMDocument $doc     Document parse.
	 * @param DOMNode     $wrapper Wrapper racine.
	 * @return void
	 */
	private function process_document_level( DOMDocument $doc, DOMNode $wrapper ): void {
		// Snapshot des enfants directs du wrapper (ils peuvent inclure des <p> et autres).
		$children = [];
		foreach ( $wrapper->childNodes as $child ) {
			$children[] = $child;
		}

		$run         = []; // Liste de <p> en cours de regroupement.
		$run_tag     = null;
		$next_anchor = null;

		$flush = function () use ( &$run, &$run_tag, &$next_anchor, $doc, $wrapper ): void {
			if ( count( $run ) >= $this->threshold && null !== $run_tag ) {
				/** @var list<DOMElement> $run_typed */
				$run_typed = $run;
				$list      = $this->build_list_from_paragraphs( $doc, $run_typed, $run_tag );
				// Insere la liste avant le premier <p> du run.
				$first = $run[0];
				$wrapper->insertBefore( $list, $first );
				// Supprime les <p> du run.
				foreach ( $run as $node ) {
					$wrapper->removeChild( $node );
				}
				unset( $run_typed );
			}
			$run         = [];
			$run_tag     = null;
			$next_anchor = null;
		};

		foreach ( $children as $child ) {
			// Ignorer les noeuds "transparents" entre <p> : commentaires HTML
			// et text-nodes purement whitespace ne cassent pas un run en cours.
			if ( $child instanceof DOMText && '' === trim( str_replace( "\xc2\xa0", ' ', (string) $child->data ) ) ) {
				continue;
			}
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				continue;
			}

			if ( $child instanceof DOMElement && 'p' === strtolower( $child->nodeName ) ) {
				$marker_info = $this->detect_marker_in_node( $child );
				if ( null !== $marker_info ) {
					$tag = $marker_info['list_tag'];
					if ( null === $run_tag ) {
						$run_tag = $tag;
						$run[]   = $child;
						continue;
					}
					if ( $tag === $run_tag ) {
						$run[] = $child;
						continue;
					}
					// Tag different -> flush et commence nouveau run.
					$flush();
					$run_tag = $tag;
					$run[]   = $child;
					continue;
				}
			}
			$flush();
		}
		$flush();
	}

	// ===================================================================
	//  Detection des marqueurs
	// ===================================================================

	/**
	 * Cherche un marqueur en debut du texte effectif d'un noeud (DFS).
	 *
	 * @param DOMNode $node Noeud (paragraphe, fragment, ...).
	 * @return array{marker_key: string, marker_match: string, list_tag: string}|null
	 */
	private function detect_marker_in_node( DOMNode $node ): ?array {
		$first_text = self::find_first_non_blank_text( $node );
		if ( null === $first_text ) {
			return null;
		}
		return $this->detect_marker_in_string( $first_text->data );
	}

	/**
	 * Idem mais sur une liste de noeuds (fragment).
	 *
	 * @param list<DOMNode> $fragment Fragment a analyser.
	 * @return array{marker_key: string, marker_match: string, list_tag: string}|null
	 */
	private function detect_marker_in_fragment( array $fragment ): ?array {
		foreach ( $fragment as $node ) {
			$first_text = self::find_first_non_blank_text( $node );
			if ( null !== $first_text ) {
				return $this->detect_marker_in_string( $first_text->data );
			}
		}
		return null;
	}

	/**
	 * Cherche un marqueur en debut d'une chaine.
	 *
	 * @param string $text Texte candidat.
	 * @return array{marker_key: string, marker_match: string, list_tag: string}|null
	 */
	private function detect_marker_in_string( string $text ): ?array {
		$ltrimmed = ltrim( $text );

		// Marqueurs presets actives.
		foreach ( $this->enabled_markers as $key => $_ ) {
			$def = self::MARKER_DEFINITIONS[ $key ];
			if ( preg_match( $def['regex'], $ltrimmed, $matches ) ) {
				return [
					'marker_key'   => $key,
					'marker_match' => $matches[0],
					'list_tag'     => $def['list_tag'],
				];
			}
		}

		// Marqueurs custom (echappes pour preg_quote).
		foreach ( $this->custom_markers as $custom ) {
			$pattern = '/^' . preg_quote( $custom, '/' ) . '[\s\xc2\xa0]+/';
			if ( preg_match( $pattern, $ltrimmed, $matches ) ) {
				return [
					'marker_key'   => 'custom',
					'marker_match' => $matches[0],
					'list_tag'     => 'ul',
				];
			}
		}

		return null;
	}

	/**
	 * DFS pour trouver le premier DOMText non-blanc sous un noeud.
	 *
	 * @param DOMNode $node Noeud racine.
	 * @return DOMText|null
	 */
	private static function find_first_non_blank_text( DOMNode $node ): ?DOMText {
		if ( $node instanceof DOMText ) {
			$str = str_replace( "\xc2\xa0", ' ', (string) $node->data );
			if ( '' !== trim( $str ) ) {
				return $node;
			}
			return null;
		}
		if ( $node->hasChildNodes() ) {
			foreach ( $node->childNodes as $child ) {
				$found = self::find_first_non_blank_text( $child );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	// ===================================================================
	//  Split <p> sur <br />
	// ===================================================================

	/**
	 * Split les enfants d'un <p> en fragments separes par <br>.
	 *
	 * @param DOMElement $p Paragraphe.
	 * @return list<list<DOMNode>> Liste de fragments (chaque fragment = liste de noeuds).
	 */
	private static function split_paragraph_on_br( DOMElement $p ): array {
		$fragments = [];
		$current   = [];
		foreach ( $p->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'br' === strtolower( $child->nodeName ) ) {
				$fragments[] = $current;
				$current     = [];
				continue;
			}
			$current[] = $child;
		}
		$fragments[] = $current;
		return $fragments;
	}

	// ===================================================================
	//  Construction des remplacements
	// ===================================================================

	/**
	 * Construit la sequence de noeuds de remplacement pour un <p> mixte.
	 *
	 * @param DOMDocument           $doc       Document parent.
	 * @param list<list<DOMNode>>   $fragments Fragments du <p> original.
	 * @return list<DOMNode> Sequence de noeuds (p, ul, ol) a inserer.
	 */
	private function build_intra_replacement( DOMDocument $doc, array $fragments ): array {
		$result   = [];
		$buffer   = []; // Bullets en attente de regroupement.
		$buf_tag  = null;

		$flush_buffer = function () use ( &$result, &$buffer, &$buf_tag, $doc ): void {
			if ( [] !== $buffer && null !== $buf_tag ) {
				$result[] = $this->build_list_from_items( $doc, $buffer, $buf_tag );
			}
			$buffer  = [];
			$buf_tag = null;
		};

		foreach ( $fragments as $frag ) {
			$marker = $this->detect_marker_in_fragment( $frag );
			if ( null === $marker ) {
				$flush_buffer();
				// Fragment non-bullet : enrobe dans un <p>.
				if ( ! self::fragment_is_blank( $frag ) ) {
					$p = $doc->createElement( 'p' );
					foreach ( $frag as $node ) {
						$p->appendChild( $node->cloneNode( true ) );
					}
					$result[] = $p;
				}
				continue;
			}
			// Fragment bullet : ajoute au buffer.
			if ( null !== $buf_tag && $marker['list_tag'] !== $buf_tag ) {
				$flush_buffer();
			}
			$buf_tag  = $marker['list_tag'];
			$buffer[] = [ 'fragment' => $frag, 'marker' => $marker ];
		}
		$flush_buffer();

		return $result;
	}

	/**
	 * Indique si un fragment ne contient que du blanc.
	 *
	 * @param list<DOMNode> $fragment Fragment.
	 * @return bool
	 */
	private static function fragment_is_blank( array $fragment ): bool {
		foreach ( $fragment as $node ) {
			if ( null !== self::find_first_non_blank_text( $node ) ) {
				return false;
			}
			// Element sans texte mais structurel ?
			if ( $node instanceof DOMElement ) {
				$structural = [ 'img', 'iframe', 'video', 'audio', 'embed', 'object', 'picture', 'source', 'br' ];
				if ( in_array( strtolower( $node->nodeName ), $structural, true ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Construit un <ul>/<ol> a partir d'une liste de fragments bullets.
	 *
	 * @param DOMDocument                                                                $doc   Document.
	 * @param list<array{fragment: list<DOMNode>, marker: array{list_tag: string, marker_match: string, marker_key: string}}> $items Items.
	 * @param string                                                                     $tag   'ul' ou 'ol'.
	 * @return DOMElement
	 */
	private function build_list_from_items( DOMDocument $doc, array $items, string $tag ): DOMElement {
		$list = $doc->createElement( $tag );
		foreach ( $items as $item ) {
			$li = $this->build_li_from_fragment( $doc, $item['fragment'], $item['marker']['marker_match'] );
			$list->appendChild( $li );
		}
		return $list;
	}

	/**
	 * Construit un <ul>/<ol> a partir de <p> consecutifs marker-prefixed.
	 *
	 * @param DOMDocument       $doc        Document.
	 * @param list<DOMElement>  $paragraphs <p> du run.
	 * @param string            $tag        'ul' ou 'ol'.
	 * @return DOMElement
	 */
	private function build_list_from_paragraphs( DOMDocument $doc, array $paragraphs, string $tag ): DOMElement {
		$list = $doc->createElement( $tag );
		foreach ( $paragraphs as $p ) {
			$marker = $this->detect_marker_in_node( $p );
			$marker_match = ( null !== $marker ) ? $marker['marker_match'] : '';
			// Convertir tous les enfants du <p> en fragment.
			$fragment = [];
			foreach ( $p->childNodes as $child ) {
				$fragment[] = $child;
			}
			$li = $this->build_li_from_fragment( $doc, $fragment, $marker_match );
			$list->appendChild( $li );
		}
		return $list;
	}

	/**
	 * Construit un <li> a partir d'un fragment de noeuds (clones), avec :
	 *  - Suppression du marqueur du premier text-node non-vide
	 *  - Desenrobage des conteneurs de presentation sans attribut semantique
	 *  - Suppression des attributs de presentation (style/class/id) sur les
	 *    balises clonees (cumul avec le desenrobage si l'inline devient vide).
	 *
	 * @param DOMDocument   $doc          Document.
	 * @param list<DOMNode> $fragment     Fragment source.
	 * @param string        $marker_match Marqueur a stripper.
	 * @return DOMElement
	 */
	private function build_li_from_fragment( DOMDocument $doc, array $fragment, string $marker_match ): DOMElement {
		$li = $doc->createElement( 'li' );

		// Cloner chaque noeud dans le <li> en appliquant les regles inline.
		foreach ( $fragment as $node ) {
			$clone = $this->clone_with_cleanup( $node, $doc );
			if ( null !== $clone ) {
				$li->appendChild( $clone );
			}
		}

		// Strip le marqueur du premier text-node non-vide.
		if ( '' !== $marker_match ) {
			$text_node = self::find_first_non_blank_text( $li );
			if ( null !== $text_node ) {
				$data = $text_node->data;
				$data = ltrim( $data );
				$data = preg_replace( '/^' . preg_quote( $marker_match, '/' ) . '/', '', $data, 1 );
				$text_node->data = ltrim( $data ?? '' );
			}
		}

		return $li;
	}

	/**
	 * Clone un noeud en appliquant les regles inline P7 :
	 *  - Suppression de style/class/id sur tous les elements clones
	 *  - Desenrobage des span/font/div inline sans attribut semantique restant
	 *  - Preservation des balises semantiques avec leurs attributs semantiques
	 *
	 * Pour les conteneurs desenrobes, on retourne potentiellement plusieurs
	 * noeuds (les enfants), mais on ne peut pas retourner une liste — on
	 * insere directement les enfants dans le parent appelant via un
	 * mecanisme alternatif. Ici on contourne en utilisant un DocumentFragment.
	 *
	 * @param DOMNode     $node Noeud source.
	 * @param DOMDocument $doc  Document destination.
	 * @return DOMNode|null Clone (eventuellement un DocumentFragment si desenrobage).
	 */
	private function clone_with_cleanup( DOMNode $node, DOMDocument $doc ): ?DOMNode {
		if ( $node instanceof DOMText ) {
			return $doc->createTextNode( (string) $node->data );
		}
		if ( ! $node instanceof DOMElement ) {
			// Commentaires, etc. : on ignore.
			return null;
		}

		$tag = strtolower( $node->nodeName );

		// Cloner les enfants recursivement.
		$child_clones = [];
		foreach ( $node->childNodes as $child ) {
			$cc = $this->clone_with_cleanup( $child, $doc );
			if ( null !== $cc ) {
				$child_clones[] = $cc;
			}
		}

		// Decision : desenrober ou conserver ?
		if ( in_array( $tag, self::CONTAINER_TAGS, true ) ) {
			// Desenrobage candidat : on regarde si l'element a des attributs SEMANTIQUES restants
			// (apres suppression mentale de style/class/id).
			if ( ! self::has_semantic_attribute( $node ) ) {
				// Desenrober : retourner un DocumentFragment contenant les enfants.
				$frag = $doc->createDocumentFragment();
				foreach ( $child_clones as $cc ) {
					$frag->appendChild( $cc );
				}
				return $frag;
			}
		}

		// Conservation de la balise (semantique ou conteneur avec attribut semantique).
		$clone = $doc->createElement( $tag );
		// Copier les attributs sauf ceux de presentation.
		if ( $node->hasAttributes() ) {
			foreach ( $node->attributes as $attr ) {
				$name = strtolower( (string) $attr->nodeName );
				if ( in_array( $name, self::PRESENTATION_ATTRIBUTES, true ) ) {
					continue;
				}
				$clone->setAttribute( $name, (string) $attr->nodeValue );
			}
		}
		foreach ( $child_clones as $cc ) {
			$clone->appendChild( $cc );
		}
		return $clone;
	}

	/**
	 * Indique si un element a au moins un attribut hors style/class/id.
	 *
	 * @param DOMElement $el Element.
	 * @return bool
	 */
	private static function has_semantic_attribute( DOMElement $el ): bool {
		if ( ! $el->hasAttributes() ) {
			return false;
		}
		foreach ( $el->attributes as $attr ) {
			$name = strtolower( (string) $attr->nodeName );
			if ( ! in_array( $name, self::PRESENTATION_ATTRIBUTES, true ) ) {
				return true;
			}
		}
		return false;
	}
}
