<?php
/**
 * DiagnosticsController — endpoints REST F12/F13 (diagnostic engine + tableau de bord).
 *
 * Cf. cahier v2.0 §4.5.2 (endpoints diagnostic / pas / diff) et §11 étape 15.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticBatchRunner;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surface REST de F12 (scan diagnostic) et F13 (tableau de bord — onglets
 * to_improve / normal / stale + badges).
 *
 * Routes (toutes namespace `htmln/v1`) :
 *
 *  - `POST   /diagnostics/run`              — démarre un scan batch (F12).
 *  - `POST   /diagnostics/run/chunk`        — traite un chunk d'articles.
 *  - `GET    /diagnostics`                  — liste paginée (F13).
 *  - `GET    /diagnostics/<post_id>`        — détail d'un diagnostic.
 *  - `DELETE /diagnostics/<post_id>`        — force re-scan au prochain run.
 *  - `GET    /diagnostics/stats`            — compteurs onglets F13.
 *
 * Toutes : permission `manage_options` (cf. §14 hyp. 14).
 *
 * Le `job_id` retourné par `/diagnostics/run` est un identifiant **client**
 * (non persisté en BDD — cf. §3.1 F12 hyp. 20). La SPA s'en sert pour
 * multiplexer ses chunks ; le contrôleur l'echo dans la réponse de
 * `/run/chunk` mais ne le valide pas côté serveur.
 *
 * V1.0 :
 *  - Filtres `post_type[]` et `search` sur `/diagnostics` acceptés mais
 *    différés V1.1 (filtrage côté SPA en attendant). Le filtre `status`
 *    est, lui, implémenté.
 *  - Override `post_types[]` sur `/diagnostics/run` implémenté (utile
 *    pour scan ad-hoc d'un sous-ensemble distinct du défaut F8).
 */
final class DiagnosticsController extends BaseController {

	/**
	 * Limite max `per_page` (cf. §4.5.2 — pas de pagination géante côté SPA).
	 */
	public const MAX_PER_PAGE = 200;

	/**
	 * Page par défaut.
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * Statuts whitelistés pour `GET /diagnostics?status=…`.
	 */
	private const ALLOWED_STATUSES = array( 'normal', 'to_improve', 'stale' );

	/**
	 * @param DiagnosticBatchRunner $runner     Orchestrateur scan batch (F12 — Phase 3.3).
	 * @param DiagnosticsRepository $repo       Persistance diagnostics (F12 — Phase 2.2).
	 * @param ?BuilderClassifier    $classifier Fallback classification au render
	 *                                          pour les rows pré-2.1.0 sans
	 *                                          `builder_type` persisté. Optionnel
	 *                                          (rétro-compat) — si null, les
	 *                                          builder_type null restent null
	 *                                          (badge `—` côté SPA).
	 */
	public function __construct(
		private readonly DiagnosticBatchRunner $runner,
		private readonly DiagnosticsRepository $repo,
		private readonly ?BuilderClassifier $classifier = null,
	) {}

	/**
	 * Enregistre les 6 routes au hook `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns       = self::REST_NAMESPACE;
		$can_read = array( $this, 'permission_check_manage_options' );

		register_rest_route( $ns, '/diagnostics', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_diagnostics' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/diagnostics/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_batch' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/diagnostics/run/chunk', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_chunk' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/diagnostics/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'stats' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/diagnostics/facets', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_facets' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/diagnostics/(?P<post_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_diagnostic' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/diagnostics/(?P<post_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_diagnostic' ),
			'permission_callback' => $can_read,
		) );
	}

	// =========================================================================
	//  Handlers
	// =========================================================================

	/**
	 * `GET /diagnostics` — liste paginée filtrable.
	 *
	 * Query :
	 *  - `status` : `normal` | `to_improve` | `stale` | absent (tous).
	 *  - `page`, `per_page` : pagination (cap MAX_PER_PAGE).
	 *  - `search` (string)  : titre ou ID exact si numérique.
	 *  - `cat` (int > 0)    : ID de catégorie WP (taxonomie `category`).
	 *  - `year` (int > 0)   : année (sur `wp_posts.post_date`).
	 *  - `month` (int 1-12) : mois (combiné avec year).
	 *  - `builder` (string) : `siteorigin` (couvre flat) | `gutenberg` | `other` | `out`.
	 *
	 * Réponse 200 : `{ items, total, page, per_page, total_pages }`.
	 * Réponse 400 si `status` est fourni mais hors whitelist.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function list_diagnostics( WP_REST_Request $request ): WP_REST_Response {
		$status_raw = $request->get_param( 'status' );
		$status     = $this->parse_status( $status_raw );
		if ( null === $status && null !== $status_raw && '' !== $status_raw ) {
			return $this->rest_error(
				'invalid_status',
				'status must be one of: normal, to_improve, stale',
				400,
				array( 'allowed' => self::ALLOWED_STATUSES )
			);
		}

		[ 'page' => $page, 'per_page' => $per_page, 'offset' => $offset ] = $this->parse_pagination( $request );
		$filters = $this->parse_filters( $request );

		$total = $this->repo->count_paginated( $status, $filters );
		$items = $this->repo->list_paginated( $status, $per_page, $offset, $filters );

		// Pré-charge le cache d'objets WP pour les post_ids retournés,
		// évite N+1 queries sur `get_post()` dans `diagnostic_to_array`.
		if ( function_exists( '_prime_post_caches' ) && array() !== $items ) {
			$post_ids = array_map( static fn( DiagnosticRecord $r ): int => $r->post_id, $items );
			_prime_post_caches( $post_ids, false, false );
		}

		return $this->respond( array(
			'items'       => array_map( array( $this, 'diagnostic_to_array' ), $items ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => 0 === $per_page ? 0 : (int) ceil( $total / $per_page ),
		) );
	}

	/**
	 * `GET /diagnostics/facets` — données pour les dropdowns de filtres SPA.
	 *
	 * Réponse 200 :
	 *  - `years` : list<int>, années disponibles (DESC).
	 *  - `categories` : list<{id, name, count}>, catégories ayant ≥ 1 article
	 *    diagnostiqué. Pas de filtrage hide_empty — on respecte le contenu
	 *    réel du diagnostic.
	 *  - `builders` : map<string, int>, count par type (siteorigin / gutenberg
	 *    / other / out / unknown).
	 *
	 * Pas paginé — les volumes sont faibles (catégories : <100 typique,
	 * années : <30, builders : 5). Cache HTTP côté WP-REST suffit.
	 *
	 * @param WP_REST_Request $request Requête (inutilisée).
	 * @return WP_REST_Response
	 */
	public function get_facets( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$years     = $this->repo->list_distinct_years();
		$builders  = $this->repo->count_by_builder();
		$categories = $this->fetch_categories_with_counts();

		return $this->respond( array(
			'years'      => $years,
			'categories' => $categories,
			'builders'   => $builders,
		) );
	}

	/**
	 * `GET /diagnostics/<post_id>` — détail.
	 *
	 * Réponse 200 : `{ diagnostic }`.
	 * Réponse 404 si aucun diagnostic n'existe pour `post_id`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function get_diagnostic( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$record  = $this->repo->find_by_post_id( $post_id );
		if ( null === $record ) {
			return $this->diagnostic_not_found( $post_id );
		}
		return $this->respond( array(
			'diagnostic' => $this->diagnostic_to_array( $record ),
		) );
	}

	/**
	 * `DELETE /diagnostics/<post_id>` — supprime le diagnostic. Le prochain
	 * `/diagnostics/run` re-diagnostiquera l'article. Idempotent : si rien
	 * n'existe, retourne `{deleted: false}` 200 (pas d'erreur).
	 *
	 * Réponse 200 : `{ deleted: bool, post_id }`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function delete_diagnostic( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$deleted = $this->repo->delete_for_post( $post_id );
		return $this->respond( array(
			'deleted' => $deleted,
			'post_id' => $post_id,
		) );
	}

	/**
	 * `GET /diagnostics/stats` — compteurs onglets F13 + total. Sert les
	 * badges de la SPA.
	 *
	 * Réponse 200 : `{ to_improve, normal, stale, total }`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function stats( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->respond( $this->repo->count_by_status() );
	}

	/**
	 * `POST /diagnostics/run` — démarre un scan batch (F12).
	 *
	 * Body :
	 *  - `chunk_size?`         : taille de chunk (≥ 1, défaut DEFAULT_CHUNK_SIZE).
	 *  - `post_types?`         : override post_types (sinon défauts Settings F8).
	 *
	 * Réponse 200 : `{ job_id, total_articles, post_ids, chunk_size }`.
	 * `job_id` = UUID v4 généré par `DiagnosticBatchRunner::start_batch` —
	 * identifiant **client** (non persisté).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function run_batch( WP_REST_Request $request ): WP_REST_Response {
		$chunk_size = $request->get_param( 'chunk_size' );
		$chunk_size = null === $chunk_size ? null : max( 1, absint( $chunk_size ) );

		$post_types_raw = $request->get_param( 'post_types' );
		$post_types     = null === $post_types_raw
			? null
			: $this->sanitize_string_list( $post_types_raw );

		$batch = $this->runner->start_batch( $chunk_size, $post_types );

		return $this->respond( array(
			'job_id'         => $batch['batch_id'],
			'total_articles' => $batch['total_articles'],
			'post_ids'       => $batch['post_ids'],
			'chunk_size'     => $batch['chunk_size'],
		) );
	}

	/**
	 * `POST /diagnostics/run/chunk` — traite un chunk d'articles du scan.
	 *
	 * Body : `{ job_id?: string, chunk_post_ids: list<int> }`.
	 * Réponse 200 : `{ job_id, processed, total }`.
	 *  - `processed` = nombre d'articles diagnostiqués dans ce chunk.
	 *  - `total`     = idem (V1.0 — pas de cumul côté serveur).
	 *
	 * `job_id` est echo si fourni — sert uniquement à la SPA pour multiplexer
	 * ses chunks. Le serveur ne valide pas (cf. §3.1 F12 hyp. 20 : non persisté).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function run_chunk( WP_REST_Request $request ): WP_REST_Response {
		$post_ids = $this->sanitize_int_list( $request->get_param( 'chunk_post_ids' ) );
		if ( array() === $post_ids ) {
			return $this->rest_error(
				'invalid_chunk_post_ids',
				'chunk_post_ids must be a non-empty list of integers',
				400
			);
		}

		$results = $this->runner->process_chunk( $post_ids );

		return $this->respond( array(
			'job_id'    => sanitize_text_field( (string) ( $request->get_param( 'job_id' ) ?? '' ) ),
			'processed' => count( $results ),
			'total'     => count( $results ),
		) );
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Validation du paramètre `status`. Whitelist stricte, retourne `null`
	 * pour valeur absente OU vide OU inconnue (le caller distingue les cas).
	 *
	 * @param mixed $value Valeur brute.
	 * @return string|null
	 */
	private function parse_status( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$clean = sanitize_key( (string) $value );
		return in_array( $clean, self::ALLOWED_STATUSES, true ) ? $clean : null;
	}

	/**
	 * Réponse 404 standardisée pour post_id sans diagnostic.
	 *
	 * @param int $post_id Article requêté.
	 * @return WP_REST_Response
	 */
	private function diagnostic_not_found( int $post_id ): WP_REST_Response {
		return $this->rest_error(
			'diagnostic_not_found',
			'No diagnostic found for post_id ' . $post_id,
			404,
			array( 'post_id' => $post_id ),
		);
	}

	/**
	 * Parse les 5 filtres optionnels de `GET /diagnostics` (post-rc3).
	 *
	 * Sanitization stricte par clé :
	 *  - `search`  : trim + sanitize_text_field ; refuse les chaînes vides.
	 *  - `cat`     : absint ; ignoré si ≤ 0.
	 *  - `year`    : absint ; ignoré si ≤ 0 (et si > 2200 — sécurité bidon).
	 *  - `month`   : absint ; ignoré si hors [1,12].
	 *  - `builder` : sanitize_key ; whitelist 4 valeurs (post-arbitrage UX).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return array{search?: string, cat_id?: int, year?: int, month?: int, builder?: string}
	 */
	private function parse_filters( WP_REST_Request $request ): array {
		$filters = array();

		$search_raw = $request->get_param( 'search' );
		if ( is_scalar( $search_raw ) ) {
			$search = trim( sanitize_text_field( (string) $search_raw ) );
			if ( '' !== $search ) {
				$filters['search'] = $search;
			}
		}

		$cat_raw = $request->get_param( 'cat' );
		if ( null !== $cat_raw ) {
			$cat = absint( $cat_raw );
			if ( $cat > 0 ) {
				$filters['cat_id'] = $cat;
			}
		}

		$year_raw = $request->get_param( 'year' );
		if ( null !== $year_raw ) {
			$year = absint( $year_raw );
			if ( $year > 0 && $year < 2200 ) {
				$filters['year'] = $year;
			}
		}

		$month_raw = $request->get_param( 'month' );
		if ( null !== $month_raw ) {
			$month = absint( $month_raw );
			if ( $month >= 1 && $month <= 12 ) {
				$filters['month'] = $month;
			}
		}

		$builder_raw = $request->get_param( 'builder' );
		if ( is_scalar( $builder_raw ) ) {
			$builder = sanitize_key( (string) $builder_raw );
			if ( in_array( $builder, array( 'siteorigin', 'gutenberg', 'other', 'out' ), true ) ) {
				$filters['builder'] = $builder;
			}
		}

		return $filters;
	}

	/**
	 * Récupère les catégories ayant au moins un article diagnostiqué, avec
	 * leur compte. Utilise `get_terms()` standard, restreint à la taxonomy
	 * `category` (alignement V0.1). `count` ici = nombre total d'articles
	 * de la catégorie (pas filtré par état diagnostic), suffit au filtre.
	 *
	 * @return list<array{id: int, name: string, count: int}>
	 */
	private function fetch_categories_with_counts(): array {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$terms = get_terms( array(
			'taxonomy'   => 'category',
			'hide_empty' => true,
			'orderby'    => 'name',
		) );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! property_exists( $term, 'term_id' ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $term->term_id,
				'name'  => property_exists( $term, 'name' ) ? (string) $term->name : '',
				'count' => property_exists( $term, 'count' ) ? (int) $term->count : 0,
			);
		}
		return $out;
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

	/**
	 * Sérialise un `DiagnosticRecord` pour la SPA. Toutes les colonnes
	 * JSON (matching_rules, metrics) sont déjà décodées par
	 * `DiagnosticRecord::from_db_row()`.
	 *
	 * @param DiagnosticRecord $record Record.
	 * @return array<string, mixed>
	 */
	private function diagnostic_to_array( DiagnosticRecord $record ): array {
		$post_title = '';
		$post_date  = '';
		$permalink  = '';
		$edit_url   = '';
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $record->post_id );
			if ( null !== $post ) {
				$post_title = (string) $post->post_title;
				$post_date  = (string) $post->post_date;
			}
		}
		// Lien public (frontend reading). `get_permalink` retourne false sur
		// un post inexistant ou un type sans URL publique — on garde alors
		// chaîne vide et la SPA n'affiche pas le lien.
		if ( function_exists( 'get_permalink' ) ) {
			$pl        = get_permalink( $record->post_id );
			$permalink = is_string( $pl ) ? $pl : '';
		}
		// URL d'édition admin. `'raw'` pour récupérer l'URL non-html-escapée
		// — la SPA fait son propre escape en setant la prop `href` JSX
		// (React escape automatiquement les attributs).
		if ( function_exists( 'get_edit_post_link' ) ) {
			$eu       = get_edit_post_link( $record->post_id, 'raw' );
			$edit_url = is_string( $eu ) ? $eu : '';
		}

		// Fallback classification au render pour les rows pré-2.1.0 (où la
		// colonne `builder_type` n'avait pas encore été migrée et reste NULL
		// jusqu'au prochain scan). On classifie à la volée pour que la SPA
		// affiche tout de suite la bonne pastille, sans forcer un rescan
		// préalable. Au prochain scan complet, `DiagnosticEngine::diagnose`
		// persiste la valeur et ce fallback ne se déclenche plus.
		$builder_type = $record->builder_type;
		if ( null === $builder_type && null !== $this->classifier ) {
			$builder_type = $this->classifier->classify( $record->post_id );
		}

		return array(
			'id'                          => $record->id,
			'post_id'                     => $record->post_id,
			'post_title'                  => $post_title,
			'post_date'                   => $post_date,
			'permalink'                   => $permalink,
			'edit_url'                    => $edit_url,
			'status'                      => $record->status,
			'builder_type'                => $builder_type,
			'matching_rules'              => $record->matching_rules,
			'metrics'                     => $record->metrics,
			'is_stale'                    => $record->is_stale,
			'diagnosed_at'                => $record->diagnosed_at,
			'post_modified_at_diagnosis'  => $record->post_modified_at_diagnosis,
		);
	}
}
