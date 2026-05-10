<?php
/**
 * StepsController — endpoints REST F14/F16 (application par pas + historique).
 *
 * Cf. cahier v2.0 §4.5.2 (endpoints diagnostic / pas / diff) et §11 étape 19.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Steps\ArticleResult;
use Cent_Son\Html_Normalizer\Steps\StepRecord;
use Cent_Son\Html_Normalizer\Steps\StepRunner;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surface REST de F14 (application par pas) et F16 (historique).
 *
 * Routes (toutes namespace `htmln/v1`) :
 *
 *  - `GET    /steps`                          — historique paginé (F16).
 *  - `GET    /steps/<uuid>`                   — détail + progression (F14).
 *  - `POST   /steps/run`                      — démarre un pas (F14).
 *  - `POST   /steps/<uuid>/process`           — traite un chunk d'articles.
 *  - `POST   /steps/<uuid>/confirm-article`   — décision admin sur régression.
 *  - `POST   /steps/<uuid>/finalize`          — clôt le pas (idempotent).
 *  - `GET    /steps/export`                   — export non paginé (capé 200, F16).
 *
 * Toutes les routes : permission `manage_options` (cf. §14 hyp. 14).
 *
 * Le `StepRunner` est l'orchestrateur métier (Phase 4) — ce contrôleur
 * délègue tout à lui et se contente de traduire les payloads HTTP en
 * appels métier et inversement, en respectant le format d'erreur
 * standardisé de `BaseController::rest_error()`.
 */
final class StepsController extends BaseController {

	/**
	 * Limite maximale de `per_page` côté liste (cf. §4.5.2 — pas de pagination
	 * géante côté SPA en V1.0). Le filtre est défensif.
	 */
	public const MAX_PER_PAGE = 200;

	/**
	 * Page par défaut.
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * Limite max pour l'export non-paginé (V1.0 — capé pour borner le payload).
	 */
	public const EXPORT_MAX = 200;

	/**
	 * @param StepRunner      $runner Orchestrateur Phase 4.
	 * @param StepsRepository $steps  Persistance pas (lecture historique).
	 */
	public function __construct(
		private readonly StepRunner $runner,
		private readonly StepsRepository $steps,
	) {}

	/**
	 * Enregistre les 7 routes au hook `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns       = self::REST_NAMESPACE;
		$can_read = array( $this, 'permission_check_manage_options' );

		register_rest_route( $ns, '/steps', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_steps' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/steps/export', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'export' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/steps/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'run_step' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/steps/(?P<uuid>[a-f0-9-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_step' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/steps/(?P<uuid>[a-f0-9-]+)/process', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'process_chunk' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/steps/(?P<uuid>[a-f0-9-]+)/confirm-article', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'confirm_article_decision' ),
			'permission_callback' => $can_read,
		) );

		register_rest_route( $ns, '/steps/(?P<uuid>[a-f0-9-]+)/finalize', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'finalize' ),
			'permission_callback' => $can_read,
		) );
	}

	// =========================================================================
	//  Handlers
	// =========================================================================

	/**
	 * `GET /steps` — historique paginé, filtre optionnel `from`/`to`.
	 *
	 * Query : `page` (≥1, défaut 1), `per_page` (1..200, défaut 50),
	 * `from` (datetime MySQL inclusif), `to` (datetime MySQL inclusif).
	 *
	 * Réponse 200 : `{ items, total, page, per_page, total_pages }`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function list_steps( WP_REST_Request $request ): WP_REST_Response {
		[ 'page' => $page, 'per_page' => $per_page, 'offset' => $offset ] = $this->parse_pagination( $request );
		[ 'from' => $from, 'to' => $to ]                                  = $this->parse_date_range( $request );

		$total = $this->steps->count_filtered( $from, $to );
		$items = $this->steps->list_filtered( $from, $to, $per_page, $offset );

		return $this->respond( array(
			'items'       => array_map( array( $this, 'step_to_array' ), $items ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => 0 === $per_page ? 0 : (int) ceil( $total / $per_page ),
		) );
	}

	/**
	 * `GET /steps/<uuid>` — détail + progression d'un pas.
	 *
	 * Réponse 200 : `{ step: <StepRecord>, progress: <resume_progress> }`.
	 * Réponse 404 si UUID inconnu.
	 *
	 * @param WP_REST_Request $request Requête (paramètre URL `uuid`).
	 * @return WP_REST_Response
	 */
	public function get_step( WP_REST_Request $request ): WP_REST_Response {
		$uuid     = $this->extract_uuid( $request );
		$progress = $this->runner->resume_progress( $uuid );
		if ( null === $progress ) {
			return $this->step_not_found( $uuid );
		}
		$record = $this->steps->find_by_uuid( $uuid );
		if ( null === $record ) {
			// Devrait être impossible si resume_progress non null, mais filet de sécurité.
			return $this->step_not_found( $uuid );
		}
		return $this->respond( array(
			'step'     => $this->step_to_array( $record ),
			'progress' => $progress,
		) );
	}

	/**
	 * `POST /steps/run` — démarre un nouveau pas. UUID v4 généré côté serveur
	 * (cf. §13 garde-fou — jamais côté client).
	 *
	 * Body : `{ post_ids: list<int>, rule_ids: list<string> }`.
	 * Réponse 201 : `{ uuid, total_articles }`.
	 * Réponse 400 si `post_ids` ou `rule_ids` vide.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function run_step( WP_REST_Request $request ): WP_REST_Response {
		$post_ids = $this->sanitize_int_list( $request->get_param( 'post_ids' ) );
		$rule_ids = $this->sanitize_string_list( $request->get_param( 'rule_ids' ) );

		if ( array() === $post_ids ) {
			return $this->rest_error( 'invalid_post_ids', 'post_ids must be a non-empty list of integers', 400 );
		}
		if ( array() === $rule_ids ) {
			return $this->rest_error( 'invalid_rule_ids', 'rule_ids must be a non-empty list of strings', 400 );
		}

		$user_id = $this->current_user_id();
		try {
			$uuid = $this->runner->start_step( $post_ids, $rule_ids, $user_id );
		} catch ( \RuntimeException $e ) {
			return $this->rest_error( 'start_step_failed', $e->getMessage(), 500 );
		}

		return $this->respond(
			array(
				'uuid'           => $uuid,
				'total_articles' => count( $post_ids ),
			),
			201
		);
	}

	/**
	 * `POST /steps/<uuid>/process` — traite un chunk d'articles du pas.
	 *
	 * Body : `{ chunk_post_ids: list<int>, dry_run?: bool }`.
	 * Réponse 200 : `{ results: array<int, ArticleResult>, processed_count }`.
	 * Réponse 400 si `chunk_post_ids` vide.
	 * Réponse 404 si UUID inconnu (couvert par StepRunner qui retourne error).
	 *
	 * Les régressions et erreurs ne fail-fast pas la requête — chaque article
	 * a son entrée dans `results` avec son status individuel (la SPA doit
	 * scanner pour décider quoi afficher en modale).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function process_chunk( WP_REST_Request $request ): WP_REST_Response {
		$uuid     = $this->extract_uuid( $request );
		$post_ids = $this->sanitize_int_list( $request->get_param( 'chunk_post_ids' ) );
		$dry_run  = (bool) $request->get_param( 'dry_run' );

		if ( array() === $post_ids ) {
			return $this->rest_error(
				'invalid_chunk_post_ids',
				'chunk_post_ids must be a non-empty list of integers',
				400
			);
		}

		$results = array();
		foreach ( $post_ids as $post_id ) {
			$results[ $post_id ] = $this->article_result_to_array(
				$this->runner->process_article( $uuid, $post_id, $dry_run )
			);
		}

		return $this->respond( array(
			'results'         => $results,
			'processed_count' => count( $results ),
		) );
	}

	/**
	 * `POST /steps/<uuid>/confirm-article` — décision admin suite à régression
	 * détectée. Le contrôleur dispatche selon `decision` :
	 *  - `confirm` → `StepRunner::confirm_article` (force l'écriture).
	 *  - `refuse`  → `StepRunner::refuse_article` (post_meta de relance).
	 *
	 * Body : `{ post_id: int, decision: 'confirm'|'refuse' }`.
	 * Réponse 200 : `{ result: ArticleResult }`.
	 * Réponse 400 si `decision` invalide ou `post_id` manquant.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function confirm_article_decision( WP_REST_Request $request ): WP_REST_Response {
		$uuid     = $this->extract_uuid( $request );
		$post_id  = (int) $request->get_param( 'post_id' );
		$decision = sanitize_key( (string) $request->get_param( 'decision' ) );

		if ( 0 === $post_id ) {
			return $this->rest_error( 'invalid_post_id', 'post_id must be a positive integer', 400 );
		}
		if ( ! in_array( $decision, array( 'confirm', 'refuse' ), true ) ) {
			return $this->rest_error(
				'invalid_decision',
				'decision must be either "confirm" or "refuse"',
				400
			);
		}

		$result = 'confirm' === $decision
			? $this->runner->confirm_article( $uuid, $post_id )
			: $this->runner->refuse_article( $uuid, $post_id );

		return $this->respond( array(
			'result' => $this->article_result_to_array( $result ),
		) );
	}

	/**
	 * `POST /steps/<uuid>/finalize` — clôt le pas (idempotent).
	 *
	 * Réponse 200 : `{ step: <StepRecord finalisé> }`.
	 * Réponse 404 si UUID inconnu.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function finalize( WP_REST_Request $request ): WP_REST_Response {
		$uuid     = $this->extract_uuid( $request );
		$finalized = $this->runner->finalize_step( $uuid );
		if ( null === $finalized ) {
			return $this->step_not_found( $uuid );
		}
		return $this->respond( array(
			'step' => $this->step_to_array( $finalized ),
		) );
	}

	/**
	 * `GET /steps/export` — export non paginé, capé à `EXPORT_MAX`. V1.0 = JSON
	 * uniquement (le format CSV demandé au cahier sera fourni en V1.1, non
	 * critique pour la SPA).
	 *
	 * Query : `from`, `to` optionnels (idem `/steps`).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		[ 'from' => $from, 'to' => $to ] = $this->parse_date_range( $request );

		$total = $this->steps->count_filtered( $from, $to );
		$items = $this->steps->list_filtered( $from, $to, self::EXPORT_MAX, 0 );

		return $this->respond( array(
			'items'    => array_map( array( $this, 'step_to_array' ), $items ),
			'total'    => $total,
			'capped'   => $total > self::EXPORT_MAX,
			'capped_at' => self::EXPORT_MAX,
		) );
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Extrait `uuid` du paramètre URL et le sanitize a minima.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return string
	 */
	private function extract_uuid( WP_REST_Request $request ): string {
		// La regex de la route garantit déjà `[a-f0-9-]+` ; on sanitize_text_field
		// pour défense en profondeur.
		return sanitize_text_field( (string) $request->get_param( 'uuid' ) );
	}

	/**
	 * Réponse 404 standardisée pour les UUID inconnus.
	 *
	 * @param string $uuid UUID requêté.
	 * @return WP_REST_Response
	 */
	private function step_not_found( string $uuid ): WP_REST_Response {
		return $this->rest_error(
			'step_not_found',
			'No step found for uuid ' . $uuid,
			404,
			array( 'uuid' => $uuid ),
		);
	}

	/**
	 * Parse les paramètres pagination + retourne page/per_page/offset bornés.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return array{page: int, per_page: int, offset: int}
	 */
	private function parse_pagination( WP_REST_Request $request ): array {
		$page     = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page_raw = absint( $request->get_param( 'per_page' ) ?? self::DEFAULT_PER_PAGE );
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page_raw ) );
		return array(
			'page'     => $page,
			'per_page' => $per_page,
			'offset'   => ( $page - 1 ) * $per_page,
		);
	}

	/**
	 * Parse les bornes temporelles `from`/`to`. Strings vides → null.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return array{from: string|null, to: string|null}
	 */
	private function parse_date_range( WP_REST_Request $request ): array {
		$from = sanitize_text_field( (string) ( $request->get_param( 'from' ) ?? '' ) );
		$to   = sanitize_text_field( (string) ( $request->get_param( 'to' ) ?? '' ) );
		return array(
			'from' => '' === $from ? null : $from,
			'to'   => '' === $to ? null : $to,
		);
	}

	/**
	 * Sérialise un `StepRecord` pour la SPA. Les colonnes JSON (applied_rules,
	 * affected_post_ids, per_article_results) sont exposées telles quelles
	 * (déjà décodées par `StepRecord::from_db_row()`).
	 *
	 * @param StepRecord $record Record.
	 * @return array<string, mixed>
	 */
	private function step_to_array( StepRecord $record ): array {
		return array(
			'id'                  => $record->id,
			'uuid'                => $record->step_uuid,
			'applied_rules'       => $record->applied_rules,
			'affected_post_ids'   => $record->affected_post_ids,
			'total_articles'      => $record->total_articles,
			'successful_articles' => $record->successful_articles,
			'refused_articles'    => $record->refused_articles,
			'errored_articles'    => $record->errored_articles,
			'per_article_results' => $record->per_article_results,
			'user_id'             => $record->user_id,
			'started_at'          => $record->started_at,
			'finished_at'         => $record->finished_at,
			'is_finished'         => $record->is_finished(),
		);
	}

	/**
	 * Sérialise un `ArticleResult` pour la SPA. Le rapport de régression et
	 * l'erreur sont déjà sérialisables par `to_persistence_array()` ; on y
	 * ajoute les snapshots métriques pour permettre le diff côté client.
	 *
	 * @param ArticleResult $result Résultat.
	 * @return array<string, mixed>
	 */
	private function article_result_to_array( ArticleResult $result ): array {
		$out                    = $result->to_persistence_array();
		$out['metrics_before']  = $result->metrics_before->toArray();
		$out['metrics_after']   = $result->metrics_after->toArray();
		return $out;
	}

	/**
	 * ID de l'utilisateur courant ou null en CLI / requête anonyme.
	 *
	 * @return int|null
	 */
	private function current_user_id(): ?int {
		$user = wp_get_current_user();
		// `WP_User::$ID` est typé `int` non-nullable côté WP, donc cast direct.
		// En CLI / requête anonyme, l'ID vaut 0 → on retourne null.
		$id = (int) $user->ID;
		return $id > 0 ? $id : null;
	}
}
