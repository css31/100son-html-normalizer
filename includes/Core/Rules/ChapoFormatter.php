<?php
/**
 * ChapoFormatter — utilitaire partagé par R13 et R14 pour le
 * nettoyage typographique d'un paragraphe chapô.
 *
 * Règle éditoriale MMM : un chapô est un texte épuré, sans aucune
 * mise en forme inline résiduelle issue de l'éditeur SiteOrigin
 * (font-size, couleurs, centrages, gras/italique « visuels »). Seuls
 * les liens (`<a>`) doivent survivre — ils portent une information
 * sémantique (référence vers une source ou page), pas une décoration.
 *
 * Le helper effectue, sur un `<p>` cible :
 *  1. **Réduction de la classe** à la seule `chapo` (drop des autres
 *     tokens éventuellement présents) ;
 *  2. **Suppression de tous les autres attributs** (`style`, `id`,
 *     `data-*`, etc.) ;
 *  3. **Unwrap récursif** de tous les éléments inline descendants
 *     SAUF `<a>` : `<span>`, `<em>`, `<strong>`, `<i>`, `<b>`, `<u>`,
 *     `<s>`, `<sup>`, `<sub>`, `<small>`, `<mark>`, `<font>`, etc.
 *     Le contenu textuel et les descendants de ces éléments sont
 *     remontés à la position de l'élément retiré ;
 *  4. **Suppression complète** des éléments inline void/structurels
 *     : `<br>`, `<img>`, `<wbr>`, `<hr>`.
 *
 * `<a>` est préservé intact (attributs `href`, `target`, `rel`,
 * `title` inclus). Son contenu interne est lui-même nettoyé en
 * profondeur via le même algorithme (un `<a><strong>texte</strong></a>`
 * devient `<a>texte</a>`).
 *
 * Cas idempotent : appelé deux fois sur le même `<p>`, le résultat
 * est identique au premier appel.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

use DOMElement;
use DOMNode;

/**
 * Helper statique de nettoyage typographique pour les chapôs.
 */
final class ChapoFormatter {

	/**
	 * Classe CSS canonique pour un chapô (alignée avec R13/R14).
	 */
	private const CHAPO_CLASS = 'chapo';

	/**
	 * Éléments **void / structurels** retirés intégralement (pas de
	 * contenu textuel à préserver).
	 *
	 * @var list<string>
	 */
	private const VOID_INLINE = array( 'br', 'img', 'wbr', 'hr' );

	/**
	 * Élément **préservé** intact (ses descendants sont néanmoins
	 * nettoyés en profondeur).
	 */
	private const PRESERVED_INLINE = 'a';

	/**
	 * Nettoie un `<p>` chapô selon les règles éditoriales MMM.
	 *
	 * @param DOMElement $p Paragraphe chapô (déjà marqué `class="chapo"`
	 *                     ou candidat à l'être).
	 * @return void
	 */
	public static function clean( DOMElement $p ): void {
		self::reset_attributes( $p );
		self::clean_inline_descendants( $p );
	}

	/**
	 * Réduit les attributs du `<p>` à un seul `class="chapo"`.
	 *
	 * @param DOMElement $p Cible.
	 * @return void
	 */
	private static function reset_attributes( DOMElement $p ): void {
		// Collecte les noms d'attributs à supprimer (modifier la
		// NamedNodeMap pendant itération est risqué).
		$names_to_remove = array();
		foreach ( $p->attributes as $attr ) {
			$names_to_remove[] = $attr->nodeName;
		}
		foreach ( $names_to_remove as $name ) {
			$p->removeAttribute( $name );
		}
		$p->setAttribute( 'class', self::CHAPO_CLASS );
	}

	/**
	 * Walk récursif : unwrap les éléments inline (hors `<a>`), retire
	 * les éléments void. Conserve les text nodes et commentaires.
	 *
	 * @param DOMElement $parent Élément dont les enfants seront nettoyés.
	 * @return void
	 */
	private static function clean_inline_descendants( DOMElement $parent ): void {
		// Snapshot des enfants avant mutation (sinon NodeList live
		// est imprévisible quand on retire/replace).
		$children = array();
		foreach ( $parent->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( ! $child instanceof DOMElement ) {
				// Text node ou commentaire : intouché.
				continue;
			}

			$tag = strtolower( $child->nodeName );

			if ( in_array( $tag, self::VOID_INLINE, true ) ) {
				// Retire entièrement (<br>, <img>, <wbr>, <hr>).
				$parent->removeChild( $child );
				continue;
			}

			if ( self::PRESERVED_INLINE === $tag ) {
				// <a> : préservé tel quel, mais ses descendants
				// sont nettoyés en profondeur.
				self::clean_inline_descendants( $child );
				continue;
			}

			// Autre élément inline (span, em, strong, font, etc.) :
			// nettoie d'abord ses descendants, puis l'unwrap.
			self::clean_inline_descendants( $child );
			self::unwrap_element( $child );
		}
	}

	/**
	 * Retire un élément en déplaçant ses enfants à sa position dans
	 * son parent (« unwrap »).
	 *
	 * @param DOMElement $element Élément à dissoudre.
	 * @return void
	 */
	private static function unwrap_element( DOMElement $element ): void {
		$parent = $element->parentNode;
		if ( null === $parent ) {
			return;
		}
		/** @var DOMNode|null $child */
		$child = $element->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$parent->insertBefore( $child, $element );
			$child = $next;
		}
		$parent->removeChild( $element );
	}
}
