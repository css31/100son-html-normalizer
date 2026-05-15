<?php
/**
 * R14 — FirstParagraphChapoRule.
 *
 * Ajoute la classe CSS `chapo` au **premier paragraphe significatif**
 * du fragment lorsqu'il porte une (ou plusieurs) phrase(s) — c'est
 * la variante R13 appliquée aux chapôs déjà rédigés en `<p>` (et non
 * détournés en `<h2>` comme R13 corrige).
 *
 * Pattern ciblé (corpus MMM-2, 423 captures sur 758 articles
 * SiteOrigin) :
 *
 *   <p>Basée sur la région toulousaine, Laetitia Moreau de l'Atelier
 *   In Vitro décline le verre en aménagement intérieur…</p>
 *
 *   devient :
 *
 *   <p class="chapo">Basée sur la région toulousaine, Laetitia Moreau
 *   de l'Atelier In Vitro décline le verre en aménagement intérieur…</p>
 *
 * Complément de R13 (h2-chapô → p.chapo) : ensemble, R13 et R14
 * homogénéisent le marquage `class="chapo"` sur **toute** la
 * population de chapôs du corpus, qu'ils soient initialement en h2
 * ou en p.
 *
 * Critères de match (cumulatifs) :
 *  - le **premier élément significatif** du fragment est un `<p>`
 *    (les paragraphes vides — whitespace/NBSP only — et les nœuds
 *    blancs/commentaires en tête sont sautés) ;
 *  - ce `<p>` n'a pas déjà la classe `chapo` (idempotence) ;
 *  - son `textContent` (NBSP normalisé) compte ≥ 5 mots ;
 *  - contient au moins une ponctuation `.` / `!` / `?` (signature
 *    d'une phrase entière).
 *
 * Préservation des attributs : la classe `chapo` est **ajoutée**
 * à l'attribut `class` existant (séparée par un espace), pas
 * substituée. Les autres attributs (`style`, `id`…) restent
 * inchangés.
 *
 * Choix conservateur : si le premier élément significatif n'est pas
 * un `<p>` (par exemple un `<h2>` qui n'a pas passé le critère
 * chapô de R13, une image, une liste…), R14 abandonne. On évite
 * ainsi de marquer comme chapô un paragraphe corps qui suivrait
 * une section explicite — la `<p>` ainsi sautée a clairement
 * une fonction de corps, pas de standfirst.
 *
 * Position pipeline (cf. PresetRegistry::PRESETS) : juste après R13.
 *  - **R13 avant R14** : le chapô-h2 est démoté en `p.chapo` d'abord.
 *    Si R13 a fait son job, le premier `<p>` est désormais la
 *    démotion R13 (déjà `class="chapo"`) → R14 idempotent skip.
 *    Sinon (article SO avec chapô-p directement, 423 cas), R14
 *    marque ce p.
 *  - **R14 avant R6** : R6 ne strippe que les attributs `style`, il
 *    ne touche pas à `class`, donc l'ordre R14 ↔ R6 est neutre. On
 *    place R14 immédiatement après R13 pour la lisibilité.
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
 * Règle R14 : marquage `class="chapo"` sur le premier paragraphe
 * phrase du fragment.
 */
final class FirstParagraphChapoRule implements RuleInterface {

	/**
	 * Seuil de mots minimum pour qualifier un `<p>` de chapô-phrase.
	 * Aligné sur R13 (critère identique : chapô = phrase).
	 */
	private const MIN_WORD_COUNT = 5;

	/**
	 * Classe CSS ajoutée au `<p>`. Aligné sur R13 et la convention
	 * française pour un chapô / lead.
	 */
	private const CHAPO_CLASS = 'chapo';

	/**
	 * Nombre maximum de `<p>` crédit consécutifs marqués comme chapô
	 * après le chapô-lead. Garde-fou anti-emballement : un article qui
	 * débuterait par 10 courts paragraphes ne doit pas être marqué
	 * intégralement comme chapô.
	 */
	private const MAX_CREDIT_PARAGRAPHS = 3;

	/**
	 * Seuil de mots pour qu'un `<p>` court SANS ponctuation finale
	 * soit considéré comme crédit / signature (typique : « LA RÉDACTION »,
	 * « PHOTOS Cyrille Martin », « Marie Dupont »).
	 */
	private const SHORT_CREDIT_MAX_WORDS = 6;

	/**
	 * Patterns explicites de crédits MMM (case insensitive, unicode).
	 * Capture « LA RÉDACTION », « LE RÉDACTEUR », « LA RÉDACTRICE »,
	 * « PHOTOS », « PHOTO », « PHOTOGRAPHE(S) », « PHOTOGRAPHIE(S) »,
	 * « TEXTE : », « TEXTE ET ». La détection se fait sur n'importe
	 * quelle position du textContent (par ex. « Belle plante. PHOTOS
	 * Untel » match aussi, ce qui est OK car ça reste un signe fort de
	 * crédit).
	 */
	private const CREDIT_PATTERN = '/\b(?:LA\s+R[ÉE]DACTION|LE\s+R[ÉE]DACTEUR|LA\s+R[ÉE]DACTRICE|PHOTOS?\b|PHOTOGRAPHES?\b|PHOTOGRAPHIES?\b|TEXTE\s*:|TEXTE\s+ET)/iu';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R14';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Marquage chapô (1er p)', '100son-html-normalizer' );
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

		$result = self::walk_for_first_chapo_paragraph( $wrapper );
		if ( 'found' === $result[0] && $result[1] instanceof DOMElement ) {
			self::add_chapo_class( $result[1] );
			ChapoFormatter::clean( $result[1] );
			self::extend_chapo_to_credit_paragraphs( $result[1] );
		} elseif ( 'already_marked' === $result[0] && $result[1] instanceof DOMElement ) {
			// R13 a déjà marqué le chapô-lead (ou R14 a déjà tourné).
			// On clean (idempotent) puis étend aux crédits — c'est
			// leur première chance d'être marqués si R13 vient juste
			// de démoter un h2 en p.chapo.
			ChapoFormatter::clean( $result[1] );
			self::extend_chapo_to_credit_paragraphs( $result[1] );
		}

		return DomHtml::serialize_fragment( $doc );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Compte le nombre de `<p>` que `apply()` marquerait OU pourrait
	 * ajouter à la classe (= chapô-lead non marqué + crédits suivants
	 * non marqués). 0 si le chapô-lead n'est pas trouvé ou est déjà
	 * marqué sans crédits supplémentaires à étendre.
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

		$result = self::walk_for_first_chapo_paragraph( $wrapper );
		if ( ! $result[1] instanceof DOMElement ) {
			return 0;
		}

		$count = 'found' === $result[0] ? 1 : 0;
		$count += self::count_extendable_credit_paragraphs( $result[1] );
		return $count;
	}

	/**
	 * Wrappers HTML structurels considérés comme **transparents** :
	 * on descend dedans sans qu'ils comptent comme « premier contenu
	 * significatif ». Cas typique : la cascade `<div class="panel-layout">`
	 * → `<div class="panel-grid">` → `<div class="cell">` → `<div class="widget">`
	 * → `<div class="textwidget">` qui enrobe le contenu réel des
	 * articles SiteOrigin. `<aside>`, `<nav>`, `<footer>` sont
	 * volontairement EXCLUS : ce sont des contenus secondaires, un
	 * `<p>` à l'intérieur n'est pas le chapô de l'article.
	 *
	 * @var list<string>
	 */
	private const TRANSPARENT_WRAPPERS = array( 'div', 'section', 'article', 'main', 'header' );

	/**
	 * Cherche le premier `<p>` significatif du fragment et vérifie
	 * qu'il qualifie comme chapô.
	 *
	 * Logique en DFS pré-ordre :
	 *  - text node blanc / commentaire / `<p>` vide → sauté ;
	 *  - text node non-blanc → abandon (texte hors `<p>` = pas un
	 *    contexte chapô propre) ;
	 *  - `<div>`/`<section>`/etc. → descendre dedans (wrapper
	 *    transparent), propager le résultat de la descente ;
	 *  - `<p>` non vide → c'est le premier contenu significatif :
	 *    soit on le marque (chapô-phrase), soit on abandonne ;
	 *  - `<hN>` non vide → abandon (une section précède = pas de
	 *    chapô possible) ;
	 *  - autre élément bloc (ul, ol, figure, blockquote, table, img…)
	 *    → abandon.
	 *
	 * Cette logique gère correctement les contenus SiteOrigin où le
	 * `<p>` chapô est imbriqué dans une cascade de `<div>` panels.
	 *
	 * @param DOMNode $node Nœud racine ou sous-arbre courant.
	 * @return array{0:string,1:?DOMElement} Tuple `[$status, $node]`
	 *                                       avec $status ∈ {'found','abandon','empty'}.
	 */
	private static function walk_for_first_chapo_paragraph( DOMNode $node ): array {
		$child = $node->firstChild;
		while ( null !== $child ) {
			$next   = $child->nextSibling;
			$result = self::classify_node( $child );
			if ( 'empty' !== $result[0] ) {
				return $result;
			}
			$child = $next;
		}
		return array( 'empty', null );
	}

	/**
	 * Classifie un nœud unique selon la grille décrite dans
	 * `walk_for_first_chapo_paragraph`.
	 *
	 * @param DOMNode $node Nœud à classer.
	 * @return array{0:string,1:?DOMElement}
	 */
	private static function classify_node( DOMNode $node ): array {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$txt = str_replace( "\xc2\xa0", ' ', (string) $node->nodeValue );
			return '' === trim( $txt ) ? array( 'empty', null ) : array( 'abandon', null );
		}
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			return array( 'empty', null );
		}
		if ( ! $node instanceof DOMElement ) {
			return array( 'empty', null );
		}

		$tag = strtolower( $node->nodeName );

		if ( in_array( $tag, self::TRANSPARENT_WRAPPERS, true ) ) {
			// Descendre dans le wrapper et propager le résultat.
			return self::walk_for_first_chapo_paragraph( $node );
		}

		if ( 'p' === $tag ) {
			if ( '' === self::normalized_text_content( $node ) ) {
				return array( 'empty', null );
			}
			if ( self::already_has_chapo_class( $node ) ) {
				// Le `<p>` est déjà marqué (typiquement par R13 qui
				// vient de démoter un h2 chapô). On retourne ce nœud
				// pour permettre à `apply()` d'étendre aux crédits.
				return array( 'already_marked', $node );
			}
			return self::is_chapo_phrase( $node ) ? array( 'found', $node ) : array( 'abandon', null );
		}

		if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			return '' === self::normalized_text_content( $node )
				? array( 'empty', null )
				: array( 'abandon', null );
		}

		// Autre élément bloc (ul, ol, figure, blockquote, table, img…)
		// → on considère que le contenu chapô-utile a déjà commencé,
		// abandon.
		return array( 'abandon', null );
	}

	/**
	 * Compte les `<p>` crédit non encore marqués qui suivraient un
	 * chapô-lead donné — pour `countMatches`. Réplique la logique de
	 * `extend_chapo_to_credit_paragraphs` sans effet de bord.
	 *
	 * @param DOMElement $chapo_p Chapô-lead.
	 * @return int Nombre de crédits qui SERAIENT marqués par apply().
	 */
	private static function count_extendable_credit_paragraphs( DOMElement $chapo_p ): int {
		$count   = 0;
		$marked  = 0;
		$sibling = $chapo_p->nextSibling;
		while ( null !== $sibling && $marked < self::MAX_CREDIT_PARAGRAPHS ) {
			if ( XML_TEXT_NODE === $sibling->nodeType ) {
				$txt = str_replace( "\xc2\xa0", ' ', (string) $sibling->nodeValue );
				if ( '' === trim( $txt ) ) {
					$sibling = $sibling->nextSibling;
					continue;
				}
				return $count;
			}
			if ( XML_COMMENT_NODE === $sibling->nodeType ) {
				$sibling = $sibling->nextSibling;
				continue;
			}
			if ( ! $sibling instanceof DOMElement ) {
				$sibling = $sibling->nextSibling;
				continue;
			}
			if ( 'p' !== strtolower( $sibling->nodeName ) ) {
				return $count;
			}
			$text = self::normalized_text_content( $sibling );
			if ( '' === $text ) {
				$sibling = $sibling->nextSibling;
				continue;
			}
			if ( ! self::is_credit_paragraph( $text ) ) {
				return $count;
			}
			if ( ! self::already_has_chapo_class( $sibling ) ) {
				++$count;
			}
			++$marked;
			$sibling = $sibling->nextSibling;
		}
		return $count;
	}

	/**
	 * Détecte si un `<p>` porte une phrase-chapô — mêmes critères
	 * que R13.
	 *
	 * @param DOMElement $p Élément `<p>`.
	 * @return bool
	 */
	private static function is_chapo_phrase( DOMElement $p ): bool {
		$text = self::normalized_text_content( $p );
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
	 * Indique si l'attribut `class` d'un élément contient déjà le
	 * token `chapo` (séparé par des espaces).
	 *
	 * @param DOMElement $element Élément testé.
	 * @return bool
	 */
	private static function already_has_chapo_class( DOMElement $element ): bool {
		$classes = $element->getAttribute( 'class' );
		if ( '' === $classes ) {
			return false;
		}
		$tokens = preg_split( '/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $tokens ) {
			return false;
		}
		return in_array( self::CHAPO_CLASS, $tokens, true );
	}

	/**
	 * Ajoute le token `chapo` à l'attribut `class` du `<p>`. Préserve
	 * les classes existantes — n'écrase rien.
	 *
	 * @param DOMElement $p Paragraphe à marquer.
	 * @return void
	 */
	private static function add_chapo_class( DOMElement $p ): void {
		$existing = $p->getAttribute( 'class' );
		$tokens   = '' === $existing ? array() : preg_split( '/\s+/', $existing, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $tokens ) {
			$tokens = array();
		}
		if ( in_array( self::CHAPO_CLASS, $tokens, true ) ) {
			return;
		}
		$tokens[] = self::CHAPO_CLASS;
		$p->setAttribute( 'class', implode( ' ', $tokens ) );
	}

	/**
	 * Étend le marquage `chapo` aux `<p>` suivants qui sont des
	 * paragraphes de crédits (« LA RÉDACTION », « PHOTOS Cyrille
	 * Martin », noms isolés…). On considère le chapô MMM comme un
	 * **bloc** : phrase-lead + 1 à 3 paragraphes signature/crédit.
	 *
	 * Critères de continuité (OR) pour un `<p>` candidat crédit :
	 *  - matche un pattern explicite (`CREDIT_PATTERN`) ;
	 *  - OU paragraphe court (≤ `SHORT_CREDIT_MAX_WORDS` mots) sans
	 *    ponctuation finale (signature, nom isolé).
	 *
	 * Conditions d'arrêt :
	 *  - frère élément suivant n'est pas un `<p>` (un titre, une
	 *    image, etc. = début du corps) ;
	 *  - frère `<p>` qui n'est ni un crédit explicite ni un court ;
	 *  - `MAX_CREDIT_PARAGRAPHS` atteint.
	 *
	 * Les `<p>` vides et nœuds blancs intercalaires sont sautés sans
	 * compter dans le quota.
	 *
	 * @param DOMElement $chapo_p `<p>` chapô-lead déjà marqué.
	 * @return void
	 */
	private static function extend_chapo_to_credit_paragraphs( DOMElement $chapo_p ): void {
		$marked  = 0;
		$sibling = $chapo_p->nextSibling;
		while ( null !== $sibling && $marked < self::MAX_CREDIT_PARAGRAPHS ) {
			if ( XML_TEXT_NODE === $sibling->nodeType ) {
				$txt = str_replace( "\xc2\xa0", ' ', (string) $sibling->nodeValue );
				if ( '' === trim( $txt ) ) {
					$sibling = $sibling->nextSibling;
					continue;
				}
				return; // Texte non-blanc = fin du bloc chapô.
			}
			if ( XML_COMMENT_NODE === $sibling->nodeType ) {
				$sibling = $sibling->nextSibling;
				continue;
			}
			if ( ! $sibling instanceof DOMElement ) {
				$sibling = $sibling->nextSibling;
				continue;
			}
			if ( 'p' !== strtolower( $sibling->nodeName ) ) {
				return; // Élément non-`<p>` = fin du bloc chapô.
			}

			$text = self::normalized_text_content( $sibling );
			if ( '' === $text ) {
				// `<p>` vide en intercalaire — on saute sans compter
				// dans le quota et sans arrêter.
				$sibling = $sibling->nextSibling;
				continue;
			}

			if ( ! self::is_credit_paragraph( $text ) ) {
				return; // Premier `<p>` non-crédit = corps de l'article.
			}

			if ( ! self::already_has_chapo_class( $sibling ) ) {
				self::add_chapo_class( $sibling );
			}
			// Nettoyage typographique (idempotent si déjà clean).
			ChapoFormatter::clean( $sibling );
			++$marked;
			$sibling = $sibling->nextSibling;
		}
	}

	/**
	 * Détecte si un texte (déjà normalisé NBSP + trim) ressemble à un
	 * paragraphe de crédit MMM.
	 *
	 *  (a) pattern explicite : `LA RÉDACTION`, `PHOTOS`, `PHOTOGRAPHE`,
	 *      `TEXTE :`, etc. ;
	 *  (b) ou paragraphe court (≤ `SHORT_CREDIT_MAX_WORDS` mots) sans
	 *      ponctuation finale `.`/`!`/`?` — signature ou nom isolé.
	 *
	 * @param string $text Texte normalisé du `<p>`.
	 * @return bool
	 */
	private static function is_credit_paragraph( string $text ): bool {
		if ( '' === $text ) {
			return false;
		}
		if ( preg_match( self::CREDIT_PATTERN, $text ) ) {
			return true;
		}
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $words ) {
			return false;
		}
		if ( count( $words ) > self::SHORT_CREDIT_MAX_WORDS ) {
			return false;
		}
		// Court : si pas de ponctuation terminale, on considère crédit.
		return ! (bool) preg_match( '/[.!?]\s*$/u', $text );
	}

	/**
	 * Retourne le `textContent` d'un nœud après normalisation NBSP
	 * et trim. Convention partagée avec R9/R10/R11/R12/R13.
	 *
	 * @param DOMNode $node Nœud.
	 * @return string Texte normalisé.
	 */
	private static function normalized_text_content( DOMNode $node ): string {
		$text = (string) $node->textContent;
		$text = str_replace( "\xc2\xa0", ' ', $text );
		return trim( $text );
	}
}
