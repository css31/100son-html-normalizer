<?php
/**
 * MetricsCalculator — calcule un MetricsSnapshot à partir d'un fragment HTML.
 *
 * Cf. cahier v2.0 §3.1 F15 (sémantique des 7 métriques) et §14 hyp. 23.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Metrics;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Dom\DomHtml;

/**
 * Service stateless de calcul des 7 métriques γ.
 *
 * Stratégie : un seul parse DOM par appel, comptages en parcourant le wrapper.
 * Pour `chars` et `words`, utilisation de `textContent` (decode déjà les
 * entités) puis normalisation des NBSP et coupage unicode-aware.
 *
 * Contrat :
 *  - jamais throw — en cas de parse error, retourne un snapshot zéro ;
 *  - idempotent : `compute($html) === compute($html)` ;
 *  - O(n) sur la taille du HTML (un seul parse + un seul parcours).
 */
final class MetricsCalculator {

	/**
	 * Calcule un MetricsSnapshot pour un fragment HTML.
	 *
	 * @param string $html Fragment HTML.
	 * @return MetricsSnapshot
	 */
	public function compute( string $html ): MetricsSnapshot {
		if ( '' === trim( $html ) ) {
			return MetricsSnapshot::zero();
		}

		$doc     = DomHtml::parse_fragment( $html );
		$wrapper = DomHtml::get_root_wrapper( $doc );
		if ( null === $wrapper ) {
			return MetricsSnapshot::zero();
		}

		// Texte effectif (entités déjà décodées par textContent ; on normalise les NBSP).
		$text       = (string) $wrapper->textContent;
		$text_clean = trim( str_replace( "\xc2\xa0", ' ', $text ) );

		$paragraphs = $doc->getElementsByTagName( 'p' )->length;
		$images     = $doc->getElementsByTagName( 'img' )->length;
		$links      = self::count_links( $doc );
		$lists      = $doc->getElementsByTagName( 'ul' )->length
			+ $doc->getElementsByTagName( 'ol' )->length
			+ $doc->getElementsByTagName( 'li' )->length;

		$headings = array(
			'h1' => $doc->getElementsByTagName( 'h1' )->length,
			'h2' => $doc->getElementsByTagName( 'h2' )->length,
			'h3' => $doc->getElementsByTagName( 'h3' )->length,
			'h4' => $doc->getElementsByTagName( 'h4' )->length,
			'h5' => $doc->getElementsByTagName( 'h5' )->length,
			'h6' => $doc->getElementsByTagName( 'h6' )->length,
		);

		return new MetricsSnapshot(
			chars: self::strlen_utf8( $text_clean ),
			words: '' === $text_clean ? 0 : self::count_words_utf8( $text_clean ),
			paragraphs: $paragraphs,
			headings: $headings,
			images: $images,
			links: $links,
			lists: $lists,
		);
	}

	/**
	 * Compte les `<a href="...">` (hors ancres pures sans href).
	 *
	 * @param \DOMDocument $doc Document DOM parsé.
	 * @return int
	 */
	private static function count_links( \DOMDocument $doc ): int {
		$count = 0;
		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
			if ( $a instanceof \DOMElement && $a->hasAttribute( 'href' ) && '' !== trim( (string) $a->getAttribute( 'href' ) ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Compte les mots en respectant les caractères Unicode (str_word_count
	 * est limité à ASCII).
	 *
	 * @param string $text Texte normalisé.
	 * @return int
	 */
	private static function count_words_utf8( string $text ): int {
		$count = preg_match_all( '/[\p{L}\p{N}]+/u', $text );
		return false === $count ? 0 : (int) $count;
	}

	/**
	 * Longueur multibyte du texte (caractères, pas octets).
	 *
	 * @param string $text Texte.
	 * @return int
	 */
	private static function strlen_utf8( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}
