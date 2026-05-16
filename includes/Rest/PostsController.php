<?php
/**
 * PostsController — endpoints REST F8 (listing + normalisation par article).
 *
 * Cf. cahier v2.0 §4.5.1 (endpoints de base) et §11 étape 12.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Posts\PostNormalizer;
use Cent_Son\Html_Normalizer\Core\Posts\SiteOriginDetector;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surface REST de F8 (listing articles éligibles + normalisation par article).
 *
 * Routes (toutes namespace `htmln/v1`) :
 *
 *  - `GET    /posts/post-types`              — post_type publics + default_checked F8.
 *  - `GET    /posts/scan`                    — listing paginé filtrable par post_type.
 *  - `GET    /posts/<id>/preview`            — preview avant/après sans écriture.
 *  - `POST   /posts/<id>/normalize`          — applique + révision avant écriture.
 *  - `POST   /posts/batch-normalize`         — lot ; SO ignorés silencieusement sans force.
 *
 * Toutes : permission `manage_options`.
 *
 * Mapping statut PostNormalizer → code HTTP :
 *  - `modified`            : 200
 *  - `unchanged`           : 200
 *  - `skipped_siteorigin`  : 409 (cf. §4.5.1 — code `siteorigin_detected`)
 *  - `error_not_found`     : 404
 *  - `error_permission`    : 403
 *  - `error_write`         : 500
 *
 * V1.0 : `search` accepté mais ignoré (différé V1.1). Pagination en
 * mémoire (V1.0 corpus quelques centaines d'articles, suffisant) — V1.1
 * passera à `WP_Query::found_posts` natif pour les gros corpus.
 */
final class PostsController extends BaseController {

	/**
	 * Cap max `per_page`.
	 */
	public const MAX_PER_PAGE = 200;

	/**
	 * Page par défaut.
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * @param SettingsRepository $settings    Source des post_types F8 par défaut.
	 * @param PostNormalizer     $normalizer  Service normalize_post (utilisé par batch_normalize).
	 * @param SiteOriginDetector $so_detector Détection panels_data côté listing.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly PostNormalizer $normalizer,
		private readonly SiteOriginDetector $so_detector,
	) {}

	/**
	 * Enregistre les 3 routes au hook `rest_api_init`.
	 *
	 * Post-V0.1 (2026-05-16) : les routes `GET /posts/<id>/preview` et
	 * `POST /posts/<id>/normalize` ont été retirées avec leurs handlers —
	 * elles n'étaient consommées que par les pages V0.1 (PostsPage) déjà
	 * supprimées. La normalisation unitaire passe désormais exclusivement
	 * par le flow F14 (`POST /steps/run` + `process` + `confirm-article`),
	 * et le diff par `POST /posts/<id>/diff` (DiffController).
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns        = self::REST_NAMESPACE;
		$can_read  = array( $this, 'permission_check_manage_options' );
		$can_write = array( $this, 'permission_check_locked' );

		register_rest_route( $ns, '/posts/post-types', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_post_types_list' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/posts/scan', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'scan' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/posts/batch-normalize', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'batch_normalize' ),
			'permission_callback' => $can_write,
		) );
	}

	// =========================================================================
	//  Handlers
	// =========================================================================

	/**
	 * `GET /posts/post-types` — liste des post_type publics éligibles avec
	 * leur état `default_checked` (issu de Settings F8).
	 *
	 * Réponse 200 : `[ {slug, label, default_checked}, ... ]`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_post_types_list( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$defaults = $this->settings->get_f8_post_types_selection();
		$objects  = get_post_types( array( 'public' => true ), 'objects' );

		$out = array();
		foreach ( $objects as $slug => $object ) {
			$slug    = (string) $slug;
			$label   = isset( $object->labels->singular_name )
				? (string) $object->labels->singular_name
				: ucfirst( $slug );
			$out[]   = array(
				'slug'            => $slug,
				'label'           => $label,
				'default_checked' => in_array( $slug, $defaults, true ),
			);
		}

		return $this->respond( $out );
	}

	/**
	 * `GET /posts/scan` — listing paginé filtrable par `post_type[]`. Chaque
	 * entrée porte un flag `has_panels_data` calculé via SiteOriginDetector.
	 *
	 * Query :
	 *  - `post_type[]` : valide contre la liste des post_types publics.
	 *                    Vide / absent → utilise les défauts F8 (Settings).
	 *  - `page`, `per_page` : pagination (cap MAX_PER_PAGE).
	 *  - `search` : V1.1 — accepté mais ignoré en V1.0.
	 *
	 * Réponse 200 : `{ items, total, page, per_page, total_pages }`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function scan( WP_REST_Request $request ): WP_REST_Response {
		$post_types = $this->resolve_post_types( $request->get_param( 'post_type' ) );

		[ 'page' => $page, 'per_page' => $per_page, 'offset' => $offset ] = $this->parse_pagination( $request );

		// V1.0 : énumération complète puis slice mémoire. À optimiser V1.1
		// avec WP_Query::found_posts pour les gros corpus.
		$all_ids = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		) );
		$all_ids = array_values( array_filter(
			(array) $all_ids,
			static fn( $v ): bool => is_numeric( $v )
		) );

		$total = count( $all_ids );
		$slice = array_slice( $all_ids, $offset, $per_page );

		$items = array();
		foreach ( $slice as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$items[] = array(
				'id'              => $post->ID,
				'title'           => (string) $post->post_title,
				'post_type'       => (string) $post->post_type,
				'post_status'     => (string) $post->post_status,
				'post_modified'   => (string) $post->post_modified,
				'has_panels_data' => $this->so_detector->has_panels_data( $post->ID ),
			);
		}

		return $this->respond( array(
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => 0 === $per_page ? 0 : (int) ceil( $total / $per_page ),
		) );
	}

	/**
	 * `POST /posts/batch-normalize` — lot. Articles SO ignorés silencieusement
	 * sans `force_siteorigin` (rapport final indique combien).
	 *
	 * Body : `{ ids: list<int>, force_siteorigin?: bool, chunk_size?: int }`.
	 *  - `chunk_size` : la SPA peut spécifier mais V1.0 traite tout en
	 *                   un seul appel ; V1.1 implémentera le chunking REST.
	 *
	 * Réponse 200 :
	 * `{ results: array<int, postnorm_result>, summary: {modified, unchanged, skipped_siteorigin, errors} }`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function batch_normalize( WP_REST_Request $request ): WP_REST_Response {
		$post_ids = $this->sanitize_int_list( $request->get_param( 'ids' ) );
		$force    = (bool) $request->get_param( 'force_siteorigin' );

		if ( array() === $post_ids ) {
			return $this->rest_error(
				'invalid_ids',
				'ids must be a non-empty list of integers',
				400
			);
		}

		$results = array();
		$summary = array(
			'modified'           => 0,
			'unchanged'          => 0,
			'skipped_siteorigin' => 0,
			'errors'             => 0,
		);

		foreach ( $post_ids as $post_id ) {
			$result               = $this->normalizer->normalize_post( $post_id, $force );
			$results[ $post_id ]  = $result;
			$status               = (string) $result['status'];
			match ( $status ) {
				PostNormalizer::STATUS_MODIFIED   => $summary['modified']++,
				PostNormalizer::STATUS_UNCHANGED  => $summary['unchanged']++,
				PostNormalizer::STATUS_SKIPPED_SO => $summary['skipped_siteorigin']++,
				default                            => $summary['errors']++,
			};
		}

		return $this->respond( array(
			'results' => $results,
			'summary' => $summary,
		) );
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Résout les post_types à scanner : si fourni dans la requête, valide
	 * contre la liste des post_types publics ; sinon retombe sur les défauts F8.
	 *
	 * @param mixed $raw Valeur brute de `$request->get_param('post_type')`.
	 * @return list<string>
	 */
	private function resolve_post_types( mixed $raw ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return $this->settings->get_f8_post_types_selection();
		}
		$requested = $this->sanitize_string_list( $raw );
		$public    = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$valid     = array_values( array_intersect( $requested, $public ) );
		return array() === $valid
			? $this->settings->get_f8_post_types_selection()
			: $valid;
	}

	/**
	 * Pagination — extrait page/per_page/offset bornés.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return array{page: int, per_page: int, offset: int}
	 */
	private function parse_pagination( WP_REST_Request $request ): array {
		$page         = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page_raw = absint( $request->get_param( 'per_page' ) ?? self::DEFAULT_PER_PAGE );
		$per_page     = max( 1, min( self::MAX_PER_PAGE, $per_page_raw ) );
		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
		);
	}

}
