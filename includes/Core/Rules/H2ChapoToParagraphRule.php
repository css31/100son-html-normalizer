<?php
/**
 * R13 — H2ChapoToParagraphRule.
 *
 * Convertit le **premier** `<h2>` du fragment en `<p class="chapo">`
 * lorsqu'il porte une (ou plusieurs) phrase(s) — c'est-à-dire un
 * « chapô » d'article au sens journalistique, et non un sous-titre
 * de section.
 *
 * Pattern ciblé (corpus MMM-2, 148 captures sur 758 articles
 * SiteOrigin) :
 *
 *   <h2>Il est rare de rénover sa maison en une unique session de
 *   travaux. La plupart du temps, ils s'échelonnent par tranches
 *   sur plusieurs années…</h2>
 *
 *   devient :
 *
 *   <p class="chapo">Il est rare de rénover sa maison en une unique
 *   session de travaux. La plupart du temps, ils s'échelonnent par
 *   tranches sur plusieurs années…</p>
 *
 * **Pourquoi** : sémantiquement, un `<h2>` est une tête de section
 * (en complément d'un `<h1>` titre d'article). L'usage du `<h2>` pour
 * un chapô était une commodité typographique de l'éditeur SiteOrigin
 * (Helvetica large) sans intention de hiérarchie de section. Le bon
 * code HTML5 pour un chapô est soit `<hgroup><h1>…</h1><p>…</p></hgroup>`
 * (titre + standfirst, restauré dans HTML Living Standard 2022), soit
 * un `<p class="chapo">` standalone — le second étant compatible avec
 * Gutenberg `core/paragraph` (className) et avec le style CSS du
 * thème.
 *
 * Critères de match (cumulatifs) — heuristique « phrase » :
 *  - élément `<h2>` ;
 *  - **uniquement le premier** h2 du fragment dans l'ordre du document
 *    (les h2 ultérieurs sont de vrais sous-titres de section et restent
 *    intacts) ;
 *  - `textContent` (NBSP normalisé) compte ≥ 5 mots ;
 *  - le `textContent` contient au moins une ponctuation finale
 *    `.` / `!` / `?` (signature d'une phrase entière).
 *
 * Sont préservés dans le `<p class="chapo">` : tous les enfants
 * inline du `<h2>` (`<a>`, `<em>`, `<strong>`, `<br>`, `<span>`…) à
 * l'identique. Les attributs du `<h2>` (style, id, class, etc.) sont
 * **abandonnés** — le `<p>` produit n'a que `class="chapo"`. La perte
 * d'éventuels styles inline n'est pas un problème : (1) R8 a déjà
 * converti les déclarations sémantiques en `<strong>`/`<em>`, et
 * (2) R6 stripperait ces styles de toute façon en aval.
 *
 * Position pipeline (cf. PresetRegistry::PRESETS) : entre R8 et R6.
 *  - **R8 avant R13** : récupération sémantique des styles dans les
 *    inlines du chapô (bold/italic) faite avant qu'on perde les
 *    attributs du `<h2>`.
 *  - **R13 avant R6** : la transformation produit un nouveau `<p>`
 *    sans `style`, R6 trouve juste à dépouiller les éventuels inlines
 *    descendants restants — ordre cohérent avec le reste du pipeline.
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
 * Règle R13 : promotion h2-chapô → p class="chapo".
 */
final class H2ChapoToParagraphRule implements RuleInterface {

	/**
	 * Seuil de mots minimum pour considérer un h2 comme phrase-chapô.
	 * Choisi à 5 d'après l'audit corpus MMM-2 : les vrais sous-titres
	 * de section font typiquement 1-4 mots, les chapôs 5+ mots avec
	 * ponctuation.
	 */
	private const MIN_WORD_COUNT = 5;

	/**
	 * Classe CSS ajoutée au `<p>` produit. Convention française pour
	 * un chapô / lead paragraphe. Compatible Gutenberg `core/paragraph`
	 * via l'attribut `className`.
	 */
	private const CHAPO_CLASS = 'chapo';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R13';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Promotion h2-chapô', '100son-html-normalizer' );
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

		$first_h2 = self::find_first_chapo_h2( $doc );
		if ( null !== $first_h2 ) {
			self::demote_to_paragraph( $doc, $first_h2 );
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
		return null === self::find_first_chapo_h2( $doc ) ? 0 : 1;
	}

	/**
	 * Retourne le premier `<h2>` du document qui satisfait les
	 * critères chapô, ou null si aucun.
	 *
	 * `getElementsByTagName` renvoie les `<h2>` dans l'ordre du
	 * document. On vérifie l'éligibilité du tout premier — si non
	 * éligible, on renonce (pas de recherche plus loin), car le
	 * pattern « h2 chapô » occupe par définition la tête du contenu.
	 *
	 * @param DOMDocument $doc Document parsé.
	 * @return DOMElement|null
	 */
	private static function find_first_chapo_h2( DOMDocument $doc ): ?DOMElement {
		foreach ( $doc->getElementsByTagName( 'h2' ) as $h2 ) {
			if ( ! $h2 instanceof DOMElement ) {
				return null;
			}
			return self::is_chapo_phrase( $h2 ) ? $h2 : null;
		}
		return null;
	}

	/**
	 * Détecte si un `<h2>` porte une phrase-chapô.
	 *
	 *  - texte non vide après normalisation NBSP + trim ;
	 *  - au moins MIN_WORD_COUNT mots ;
	 *  - contient au moins une ponctuation `.` / `!` / `?`.
	 *
	 * @param DOMElement $h2 Élément `<h2>`.
	 * @return bool
	 */
	private static function is_chapo_phrase( DOMElement $h2 ): bool {
		$text = (string) $h2->textContent;
		$text = str_replace( "\xc2\xa0", ' ', $text );
		$text = trim( $text );
		if ( '' === $text ) {
			return false;
		}
		if ( ! preg_match( '/[.!?]/u', $text ) ) {
			return false;
		}
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words ) {
			return false;
		}
		return count( $words ) >= self::MIN_WORD_COUNT;
	}

	/**
	 * Effectue la transformation : crée un `<p class="chapo">`,
	 * déplace tous les enfants du `<h2>` dedans, et remplace le
	 * `<h2>` dans son parent.
	 *
	 * @param DOMDocument $doc Document hôte.
	 * @param DOMElement  $h2  `<h2>` cible (validé en amont).
	 * @return void
	 */
	private static function demote_to_paragraph( DOMDocument $doc, DOMElement $h2 ): void {
		$parent = $h2->parentNode;
		if ( null === $parent ) {
			return;
		}

		$paragraph = $doc->createElement( 'p' );
		$paragraph->setAttribute( 'class', self::CHAPO_CLASS );

		// Nettoyage typographique : tout inline interne (sauf <a>) est
		// unwrappé après le déplacement (cf. ChapoFormatter en fin de
		// méthode). On déplace d'abord les enfants tels quels.

		// Déplace tous les enfants du `<h2>` (text nodes + inlines).
		/** @var DOMNode|null $child */
		$child = $h2->firstChild;
		while ( null !== $child ) {
			$next = $child->nextSibling;
			$paragraph->appendChild( $child );
			$child = $next;
		}

		$parent->replaceChild( $paragraph, $h2 );

		// Nettoyage final : tout inline interne sauf <a> est unwrappé.
		ChapoFormatter::clean( $paragraph );
	}
}
