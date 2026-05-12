<?php
/**
 * BuilderClassifier — classifie un article selon le constructeur d'origine.
 *
 * Service stateless de classification des articles en 5 types (cf. §14
 * hyp. 21 + V0.1 PostsPage::classify_builder). Source unique de vérité
 * partagée entre la page V0.1 (Admin\Pages\PostsPage) et la SPA V1.0
 * (Rest\DiagnosticsController, qui persiste le résultat en base pour
 * filtrage SQL rapide).
 *
 * Les 5 types :
 *
 *  - `siteorigin`      : panels_data en post-meta non-vide, OU contenu
 *                        contenant `<!-- wp:siteorigin-panels` (mode bloc
 *                        Gutenberg packagé depuis SiteOrigin 2.10+).
 *  - `siteorigin_flat` : pas de panels_data ni bloc SO, mais le contenu
 *                        porte les classes `panel-layout` ou `so-panel`
 *                        (rendu SO aplati — typique de scripts de
 *                        migration incomplets). Normalisation à risque.
 *  - `gutenberg`       : pas de marqueur SO, présence d'un autre
 *                        `<!-- wp:` (détecté par `has_blocks()`).
 *  - `other`           : aucun marqueur — HTML libre, éditeur classique.
 *  - `out`             : override manuel via post-meta
 *                        `_son100_htmln_builder_override` posé via le
 *                        toggle « → Out » de la colonne Constr. V0.1.
 *                        Priorité absolue sur la détection auto.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Classifie un article selon son constructeur (SiteOrigin, Gutenberg, etc.).
 */
final class BuilderClassifier {

	/**
	 * Constantes des 5 types — strings stables côté SQL (colonne
	 * `builder_type` de `son100_htmln_diagnostics`) et côté API REST.
	 * Ne PAS modifier sans migration.
	 */
	public const TYPE_SITEORIGIN      = 'siteorigin';
	public const TYPE_SITEORIGIN_FLAT = 'siteorigin_flat';
	public const TYPE_GUTENBERG       = 'gutenberg';
	public const TYPE_OTHER           = 'other';
	public const TYPE_OUT             = 'out';

	/**
	 * Liste fixe des types valides. Utile pour la validation de filtre
	 * et la docu auto-générée.
	 *
	 * @var list<string>
	 */
	public const ALL_TYPES = array(
		self::TYPE_SITEORIGIN,
		self::TYPE_SITEORIGIN_FLAT,
		self::TYPE_GUTENBERG,
		self::TYPE_OTHER,
		self::TYPE_OUT,
	);

	/**
	 * Post-meta du tag manuel (override de la classification auto). Posé
	 * via le toggle V0.1, respecté par la SPA V1.0.
	 */
	public const META_OVERRIDE = '_son100_htmln_builder_override';

	/**
	 * Classifie un article en un des 5 types.
	 *
	 * Ordre des tests (rc4 — refonte pour gérer le cas « `panels_data`
	 * fossile ») :
	 *  1. Override manuel `out` → priorité absolue.
	 *  2. Bloc `<!-- wp:siteorigin-panels` dans `post_content` → `siteorigin`
	 *     (SiteOrigin 2.10+, mode packagé Gutenberg).
	 *  3. Classes `panel-layout` ou `so-panel` dans `post_content` :
	 *     - avec `panels_data` non-vide → `siteorigin` (édition SO active) ;
	 *     - sans `panels_data` → `siteorigin_flat` (rendu HTML aplati,
	 *       migration partielle).
	 *  4. `has_blocks( $content )` → `gutenberg`. Cette étape est placée
	 *     **avant** le test sur `panels_data` seul pour respecter le rendu
	 *     effectif : un article migré vers Gutenberg garde souvent son
	 *     ancien `panels_data` en post-meta comme vestige, mais c'est
	 *     `post_content` qui est servi au front — c'est lui qui doit
	 *     piloter la classification.
	 *  5. `panels_data` non-vide sans aucun marqueur dans `post_content` →
	 *     `siteorigin` (cas dégénéré : article SO dont `post_content` est
	 *     vide ou non encore régénéré).
	 *  6. Sinon → `other`.
	 *
	 * @param int $post_id Identifiant.
	 * @return string Une des constantes `TYPE_*`.
	 */
	public function classify( int $post_id ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return self::TYPE_OTHER;
		}

		// 1. Override manuel : priorité absolue.
		$override = (string) get_post_meta( $post_id, self::META_OVERRIDE, true );
		if ( self::TYPE_OUT === $override ) {
			return self::TYPE_OUT;
		}

		$content         = (string) get_post_field( 'post_content', $post_id );
		$panels_data     = get_post_meta( $post_id, 'panels_data', true );
		$has_panels_data = $this->panels_data_is_non_empty( $panels_data );

		// 2. Bloc SO packagé en Gutenberg (SiteOrigin 2.10+).
		if ( str_contains( $content, '<!-- wp:siteorigin-panels' ) ) {
			return self::TYPE_SITEORIGIN;
		}

		// 3. Classes SO dans le contenu rendu.
		$has_so_classes = str_contains( $content, 'panel-layout' )
			|| str_contains( $content, 'so-panel' );
		if ( $has_so_classes ) {
			return $has_panels_data
				? self::TYPE_SITEORIGIN
				: self::TYPE_SITEORIGIN_FLAT;
		}

		// 4. Gutenberg natif — prime sur `panels_data` fossile.
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			return self::TYPE_GUTENBERG;
		}

		// 5. `panels_data` seul sans aucun marqueur dans le contenu —
		// vestige actif rare (article SO dont `post_content` n'a pas été
		// re-rendu après la dernière édition de la mise en page).
		if ( $has_panels_data ) {
			return self::TYPE_SITEORIGIN;
		}

		return self::TYPE_OTHER;
	}

	/**
	 * Indique si une valeur retournée par `get_post_meta(..., 'panels_data')`
	 * est considérée comme « SiteOrigin actif » :
	 *  - tableau non-vide (cas typique post-SO standard) ;
	 *  - string non-vide (rare — meta sérialisée manuellement).
	 *
	 * `null`, `false`, `''` et `array()` sont considérés comme absents.
	 *
	 * @param mixed $panels_data Valeur brute de `get_post_meta`.
	 * @return bool
	 */
	private function panels_data_is_non_empty( mixed $panels_data ): bool {
		if ( null === $panels_data || false === $panels_data || '' === $panels_data ) {
			return false;
		}
		if ( is_array( $panels_data ) ) {
			return ! empty( $panels_data );
		}
		return true;
	}

	/**
	 * Indique si un type donné représente un article hors périmètre
	 * d'action automatique (actuellement uniquement `out`).
	 *
	 * Utile côté SPA pour griser les checkboxes du tableau et empêcher
	 * l'inclusion dans un pas.
	 *
	 * @param string $type Type retourné par `classify()`.
	 * @return bool
	 */
	public function is_out_of_scope( string $type ): bool {
		return self::TYPE_OUT === $type;
	}

}
