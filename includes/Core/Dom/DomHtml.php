<?php
/**
 * DomHtml — helper de parsing / sérialisation de fragments HTML via DOMDocument.
 *
 * Outillage partagé par les règles DOM-aware (P1, P2, P4, P6, P7, P8) pour :
 *  - parser un fragment sans wrapper `<html><body>` parasite ;
 *  - préserver l'encodage UTF-8 ;
 *  - sérialiser sans réinjecter de DOCTYPE ni de balises racine.
 *
 * Ce helper n'apparaît pas dans l'arborescence §5 du cahier — il est
 * introduit comme dépendance technique transversale aux règles.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Dom;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMNode;

/**
 * Helper statique pour parsing/sérialisation de fragments HTML.
 */
final class DomHtml {

	/**
	 * Conteneur racine utilisé pour wrapper les fragments à parser.
	 */
	private const WRAPPER_TAG = 'htmln-frag';

	/**
	 * Parse un fragment HTML en DOMDocument.
	 *
	 * Stratégie :
	 *  1. Wrapper le fragment dans un tag custom pour avoir un nœud racine
	 *     prédictible et éviter les wrappers `<html><body>` automatiques.
	 *  2. Charger via libxml en silenciant les warnings (HTML5 / entités).
	 *  3. Le caller récupère le wrapper via `get_root_wrapper()` pour itérer.
	 *
	 * @param string $html Fragment HTML.
	 * @return DOMDocument
	 */
	public static function parse_fragment( string $html ): DOMDocument {
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$doc->preserveWhiteSpace = true;
		$doc->formatOutput       = false;

		$wrapped = '<?xml encoding="UTF-8"?><' . self::WRAPPER_TAG . '>' . $html . '</' . self::WRAPPER_TAG . '>';

		$internal = libxml_use_internal_errors( true );
		$doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $internal );

		return $doc;
	}

	/**
	 * Récupère le nœud wrapper créé par `parse_fragment`.
	 *
	 * Tous les nœuds du fragment original sont enfants directs de ce wrapper.
	 *
	 * @param DOMDocument $doc Document parsé.
	 * @return DOMNode|null
	 */
	public static function get_root_wrapper( DOMDocument $doc ): ?DOMNode {
		foreach ( $doc->childNodes as $node ) {
			if ( $node->nodeType === XML_ELEMENT_NODE && $node->nodeName === self::WRAPPER_TAG ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Sérialise le DOM en chaîne HTML, sans le wrapper racine ni le PI XML.
	 *
	 * @param DOMDocument $doc Document à sérialiser.
	 * @return string Fragment HTML.
	 */
	public static function serialize_fragment( DOMDocument $doc ): string {
		$wrapper = self::get_root_wrapper( $doc );
		if ( null === $wrapper ) {
			return '';
		}

		$out = '';
		foreach ( $wrapper->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}

		// `saveHTML` peut réinjecter `&#13;` et autres ; on remet en forme minimal.
		return self::clean_serialized( $out );
	}

	/**
	 * Nettoie la sortie de saveHTML des artefacts connus.
	 *
	 * @param string $html HTML sérialisé.
	 * @return string
	 */
	private static function clean_serialized( string $html ): string {
		// libxml encode '&nbsp;' en '\xc2\xa0' selon les versions.
		$html = str_replace( "\xc2\xa0", '&nbsp;', $html );
		// Supprime les CR ajoutés par saveHTML.
		$html = str_replace( "\r", '', $html );
		return $html;
	}
}
