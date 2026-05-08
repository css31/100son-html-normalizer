<?php
/**
 * Trait d'assertions HTML partagé par les tests de règles.
 *
 * `assertHtmlEquals` normalise la mise en forme (commentaires, blancs entre
 * blocs, tabulations) avant comparaison stricte. Les fixtures peuvent ainsi
 * rester lisibles côté humain sans piéger la comparaison.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit;

trait HtmlAssertions {

	/**
	 * Compare deux fragments HTML en ignorant la mise en forme inter-blocs.
	 *
	 * Normalisations appliquées sur les deux côtés :
	 *  - suppression des commentaires HTML `<!-- ... -->`
	 *  - suppression des espaces consécutifs hors texte (entre balises)
	 *  - trim global
	 *  - normalisation des fins de ligne en `\n`
	 *
	 * @param string $expected Fragment attendu.
	 * @param string $actual   Fragment produit.
	 * @param string $message  Message d'échec optionnel.
	 * @return void
	 */
	protected function assertHtmlEquals( string $expected, string $actual, string $message = '' ): void {
		$this->assertSame(
			self::normalize_html( $expected ),
			self::normalize_html( $actual ),
			$message
		);
	}

	/**
	 * Normalise un fragment HTML pour comparaison textuelle.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private static function normalize_html( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = str_replace( [ "\r\n", "\r" ], "\n", $html );
		// Réduit les enchaînements de blanc inter-balises (`>...<`) à un seul espace OU rien.
		$html = (string) preg_replace( '/>\s+</', '><', $html );
		// Normalise les éléments void (XHTML `<img/>` <-> HTML5 `<img>`) — on garde la forme HTML5 canonique.
		$html = (string) preg_replace(
			'#<(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)([^>]*?)\s*/>#i',
			'<$1$2>',
			$html
		);
		// Et tasse les espaces résiduels avant `>` sur ces mêmes balises (`<br >` -> `<br>`).
		$html = (string) preg_replace(
			'#<(area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)\s+>#i',
			'<$1>',
			$html
		);
		return trim( $html );
	}
}
