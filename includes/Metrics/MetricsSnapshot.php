<?php
/**
 * MetricsSnapshot — DTO immuable des 7 métriques γ structurelles.
 *
 * Cf. cahier v2.0 §3.1 F15 (sémantique) et §14 hyp. 23 (figées V1).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Metrics;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot d'un état HTML mesuré sur 7 dimensions structurelles, comparé
 * avant/après normalisation par F15 (RegressionDetector) pour détecter une
 * régression dépassant les seuils γ (`getRegressionThresholds()`).
 *
 * Les 7 dimensions :
 *  - `chars`      : longueur du texte brut (textContent normalisé).
 *  - `words`      : nombre de mots (split sur whitespace après normalisation).
 *  - `paragraphs` : nombre de `<p>`.
 *  - `headings`   : objet `{h1: int, h2: int, ..., h6: int}` (seuil par niveau).
 *  - `images`     : nombre d'`<img>`.
 *  - `links`      : nombre d'`<a href="...">`.
 *  - `lists`      : nombre de `<ul>` + `<ol>` + `<li>` (somme).
 */
final class MetricsSnapshot {

	/**
	 * @param int                                                                         $chars      Longueur du texte brut.
	 * @param int                                                                         $words      Nombre de mots.
	 * @param int                                                                         $paragraphs Nombre de `<p>`.
	 * @param array{h1: int, h2: int, h3: int, h4: int, h5: int, h6: int}                 $headings   Comptes par niveau.
	 * @param int                                                                         $images     Nombre d'`<img>`.
	 * @param int                                                                         $links      Nombre d'`<a href>`.
	 * @param int                                                                         $lists      Somme `<ul>` + `<ol>` + `<li>`.
	 */
	public function __construct(
		public readonly int $chars,
		public readonly int $words,
		public readonly int $paragraphs,
		public readonly array $headings,
		public readonly int $images,
		public readonly int $links,
		public readonly int $lists,
	) {}

	/**
	 * Représentation tableau prête pour persistance JSON ou sérialisation API.
	 *
	 * @return array{chars: int, words: int, paragraphs: int, headings: array{h1:int,h2:int,h3:int,h4:int,h5:int,h6:int}, images: int, links: int, lists: int}
	 */
	public function toArray(): array {
		return array(
			'chars'      => $this->chars,
			'words'      => $this->words,
			'paragraphs' => $this->paragraphs,
			'headings'   => $this->headings,
			'images'     => $this->images,
			'links'      => $this->links,
			'lists'      => $this->lists,
		);
	}

	/**
	 * Reconstruit un snapshot depuis un tableau (typiquement issu de
	 * `json_decode` du champ `metrics` en BDD).
	 *
	 * Tolérant : valeurs manquantes ou typées flous → 0 ou structure défaut.
	 *
	 * @param array<string, mixed> $data Données brutes.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		$headings_raw = is_array( $data['headings'] ?? null ) ? $data['headings'] : array();
		$headings     = array(
			'h1' => isset( $headings_raw['h1'] ) ? (int) $headings_raw['h1'] : 0,
			'h2' => isset( $headings_raw['h2'] ) ? (int) $headings_raw['h2'] : 0,
			'h3' => isset( $headings_raw['h3'] ) ? (int) $headings_raw['h3'] : 0,
			'h4' => isset( $headings_raw['h4'] ) ? (int) $headings_raw['h4'] : 0,
			'h5' => isset( $headings_raw['h5'] ) ? (int) $headings_raw['h5'] : 0,
			'h6' => isset( $headings_raw['h6'] ) ? (int) $headings_raw['h6'] : 0,
		);

		return new self(
			chars: isset( $data['chars'] ) ? (int) $data['chars'] : 0,
			words: isset( $data['words'] ) ? (int) $data['words'] : 0,
			paragraphs: isset( $data['paragraphs'] ) ? (int) $data['paragraphs'] : 0,
			headings: $headings,
			images: isset( $data['images'] ) ? (int) $data['images'] : 0,
			links: isset( $data['links'] ) ? (int) $data['links'] : 0,
			lists: isset( $data['lists'] ) ? (int) $data['lists'] : 0,
		);
	}

	/**
	 * Snapshot vide (toutes les métriques à 0). Utile pour comparaison
	 * sur HTML vide ou comme valeur par défaut.
	 *
	 * @return self
	 */
	public static function zero(): self {
		return new self(
			chars: 0,
			words: 0,
			paragraphs: 0,
			headings: array( 'h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0 ),
			images: 0,
			links: 0,
			lists: 0,
		);
	}

	/**
	 * Total agrégé des headings tous niveaux confondus. Pratique pour les
	 * comparaisons rapides sans descendre par niveau.
	 *
	 * @return int
	 */
	public function totalHeadings(): int {
		return $this->headings['h1']
			+ $this->headings['h2']
			+ $this->headings['h3']
			+ $this->headings['h4']
			+ $this->headings['h5']
			+ $this->headings['h6'];
	}
}
