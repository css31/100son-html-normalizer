<?php
/**
 * DiffController — endpoint REST F14.3 (calcul de diff à la volée sans écriture).
 *
 * Cf. cahier v2.0 §4.5.2 (endpoints diagnostic / pas / diff) et §11 étape 20.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surface REST de F14.3 — preview du diff (HTML + métriques) qu'aurait
 * produit l'application d'un sous-ensemble de règles sur un article, **sans
 * appliquer ni écrire**. Sert à la modale « Voir le diff » de F14 (avant
 * de cliquer « Appliquer ce pas »).
 *
 * Route :
 *  - `POST /posts/<id>/diff` — body `{ rule_ids: list<string> }`.
 *
 * Permission `manage_options`. Aucune écriture, aucune révision créée
 * (la révision n'est posée qu'en cas d'écriture effective via StepRunner
 * / PostNormalizer — cf. §13).
 *
 * Sépare `DiffController` de `PostsController` pour respecter le découpage
 * du cahier (§5 arborescence cible) — chaque contrôleur incarne un cas
 * d'usage métier distinct, ce qui simplifie la lecture des routes côté SPA.
 */
final class DiffController extends BaseController {

	/**
	 * @param PresetRegistry    $registry   Source des règles (filtrage par rule_ids).
	 * @param Pipeline          $pipeline   Moteur applySubset.
	 * @param MetricsCalculator $metrics    Snapshot avant/après.
	 * @param BuilderClassifier $classifier Typage du constructeur d'origine pour le header de la modale.
	 */
	public function __construct(
		private readonly PresetRegistry $registry,
		private readonly Pipeline $pipeline,
		private readonly MetricsCalculator $metrics,
		private readonly BuilderClassifier $classifier,
	) {}

	/**
	 * Enregistre la route `POST /posts/<id>/diff`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/diff',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'compute_diff' ),
				'permission_callback' => array( $this, 'permission_check_manage_options' ),
			)
		);
	}

	/**
	 * `POST /posts/<id>/diff` — calcule à la volée le diff que produirait
	 * l'application des `rule_ids` sur cet article, sans écrire.
	 *
	 * Body : `{ rule_ids: list<string> }`.
	 * Réponse 200 : `{ html_before, html_after, metrics_before, metrics_after, warnings, unchanged, post_date, categories, builder_type, has_fossil_panels_data }`.
	 * Réponse 400 si `rule_ids` vide.
	 * Réponse 404 si article inconnu.
	 *
	 * Les 4 clés `post_date` / `categories` / `builder_type` / `has_fossil_panels_data`
	 * (post-rc4) sont fournies pour alimenter le header de la modale Diff
	 * côté SPA — éviter un round-trip REST supplémentaire et garder la modale
	 * autonome quand elle est ouverte depuis `RegressionModal` (qui ne dispose
	 * pas du record de diagnostic correspondant). `has_fossil_panels_data`
	 * permet de retomber exactement sur le badge orange « Gut + fossile » du
	 * tableau Normaliser (rc4) sans passer par un endpoint séparé.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function compute_diff( WP_REST_Request $request ): WP_REST_Response {
		$post_id  = (int) $request->get_param( 'id' );
		$rule_ids = $this->sanitize_string_list( $request->get_param( 'rule_ids' ) );

		if ( array() === $rule_ids ) {
			return $this->rest_error(
				'invalid_rule_ids',
				'rule_ids must be a non-empty list of strings',
				400
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->rest_error(
				'post_not_found',
				'No post found for id ' . $post_id,
				404,
				array( 'post_id' => $post_id ),
			);
		}

		$html_before = (string) $post->post_content;
		$warnings    = array();
		$html_after  = $this->pipeline->applySubset(
			$this->registry->get_enabled_rules(),
			$rule_ids,
			$html_before,
			array(
				'post_id' => $post_id,
				'source'  => 'diff',
			),
			$warnings
		);

		$before = $this->metrics->compute( $html_before );
		$after  = $this->metrics->compute( $html_after );

		$builder_type = $this->classifier->classify( $post_id );

		return $this->respond( array(
			'html_before'             => $html_before,
			'html_after'              => $html_after,
			'metrics_before'          => $before->toArray(),
			'metrics_after'           => $after->toArray(),
			'warnings'                => $warnings,
			'unchanged'               => $html_before === $html_after,
			'post_date'               => (string) $post->post_date,
			'categories'              => $this->get_post_category_names( $post_id ),
			'builder_type'            => $builder_type,
			'has_fossil_panels_data'  => $this->has_fossil_panels_data( $post_id, $builder_type ),
		) );
	}

	/**
	 * Indique si l'article est classé Gutenberg mais conserve un vestige
	 * `panels_data` en post-meta (badge orange « Gut + fossile » rc4).
	 *
	 * Logique alignée sur `DiagnosticsController::diagnostic_to_array()` :
	 * un seul lookup `get_post_meta`, scope restreint au type Gutenberg
	 * (pour un SO la présence de `panels_data` est attendue, pas fossile).
	 *
	 * @param int    $post_id      Article.
	 * @param string $builder_type Type retourné par BuilderClassifier.
	 * @return bool
	 */
	private function has_fossil_panels_data( int $post_id, string $builder_type ): bool {
		if ( BuilderClassifier::TYPE_GUTENBERG !== $builder_type ) {
			return false;
		}
		if ( ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		$panels = get_post_meta( $post_id, 'panels_data', true );
		if ( null === $panels || false === $panels || '' === $panels ) {
			return false;
		}
		if ( is_array( $panels ) ) {
			return ! empty( $panels );
		}
		return true;
	}

	/**
	 * Récupère la liste des noms de catégories de l'article, dans l'ordre
	 * fourni par `wp_get_post_categories` (= id ASC par défaut côté WP).
	 *
	 * Retourne un tableau vide si l'article n'a aucune catégorie, si le post
	 * type ne supporte pas la taxonomie `category` (`wp_get_post_categories`
	 * retourne alors `WP_Error` ou `false`), ou si le hook réseau a renvoyé
	 * autre chose qu'un tableau — on reste défensif pour ne jamais casser
	 * la modale Diff sur un edge case.
	 *
	 * @param int $post_id Identifiant de l'article.
	 * @return list<string> Noms des catégories.
	 */
	private function get_post_category_names( int $post_id ): array {
		if ( ! function_exists( 'wp_get_post_categories' ) ) {
			return array();
		}
		$names = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
		if ( ! is_array( $names ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map( 'strval', $names ),
				static fn( string $name ): bool => '' !== $name
			)
		);
	}
}
