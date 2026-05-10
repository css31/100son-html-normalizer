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
	 * @param PresetRegistry    $registry Source des règles (filtrage par rule_ids).
	 * @param Pipeline          $pipeline Moteur applySubset.
	 * @param MetricsCalculator $metrics  Snapshot avant/après.
	 */
	public function __construct(
		private readonly PresetRegistry $registry,
		private readonly Pipeline $pipeline,
		private readonly MetricsCalculator $metrics,
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
	 * Réponse 200 : `{ html_before, html_after, metrics_before, metrics_after, warnings }`.
	 * Réponse 400 si `rule_ids` vide.
	 * Réponse 404 si article inconnu.
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

		return $this->respond( array(
			'html_before'    => $html_before,
			'html_after'     => $html_after,
			'metrics_before' => $before->toArray(),
			'metrics_after'  => $after->toArray(),
			'warnings'       => $warnings,
			'unchanged'      => $html_before === $html_after,
		) );
	}
}
