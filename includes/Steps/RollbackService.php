<?php
/**
 * RollbackService — annule un step (entier ou partiel) via les révisions WP.
 *
 * Cf. discussion design 2026-05-16 (F-rollback) et CDC v2.0 (futur §3.1).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Steps;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use WP_Post;

/**
 * Restaure le contenu antérieur d'un article en s'appuyant sur les révisions
 * WP créées juste avant chaque écriture par `StepRunner::write_post_content()`.
 *
 * Le pivot est `per_article_results[post_id].revision_id` (cf.
 * `ArticleResult::to_persistence_array()`) — capturé depuis la rollback
 * feature uniquement. Les steps antérieurs à cette feature n'ont rien à
 * cette clé et sont signalés `skipped: revision_not_captured` (cas explicite,
 * UX = bouton grisé).
 *
 * Contrat de retour :
 *  - `plan` ou `executed` (selon `dry_run`) : liste des actions par article.
 *  - `cascade` : map `post_id => list<step_uuid_postérieur>` — articles
 *    remodifiés par des steps ultérieurs (perte silencieuse si rollback).
 *  - Catégorisation : `rolled_back` (succès), `skipped` (raisons explicites
 *    listées dans `SKIP_REASON_*`), `errors` (échec technique).
 *
 * Pas de transaction multi-articles : si l'article 7 échoue, les articles
 * 1-6 restent rollback-és. C'est aligné avec le comportement actuel des
 * batches (cf. `process_chunk`) — robustesse > atomicité en V1.
 */
class RollbackService {

	public const SKIP_NO_RESULT             = 'no_per_article_result';
	public const SKIP_NOT_SUCCESS           = 'article_not_success';
	public const SKIP_REVISION_NOT_CAPTURED = 'revision_not_captured';
	public const SKIP_REVISION_PURGED       = 'revision_purged';
	public const SKIP_REVISION_MISMATCH     = 'revision_parent_mismatch';

	/**
	 * @param StepsRepository       $steps       Lecture du step à rollback + cascade detection.
	 * @param DiagnosticsRepository $diagnostics Upsert du diagnostic recalculé après restore.
	 * @param DiagnosticEngine      $engine      Recalcul diagnostic post-restore.
	 */
	public function __construct(
		private readonly StepsRepository $steps,
		private readonly DiagnosticsRepository $diagnostics,
		private readonly DiagnosticEngine $engine,
	) {}

	/**
	 * Rollback du step `$uuid`. Par défaut : tous les articles `success` de
	 * `per_article_results` ayant un `revision_id` capturé. Si `$post_ids` est
	 * fourni (non vide), on restreint à ce sous-ensemble (intersection avec
	 * le periimeter du step).
	 *
	 * En `dry_run` : pas de `wp_restore_post_revision`, mais la cascade est
	 * calculée et toutes les vérifications (purge, mismatch) sont faites. La
	 * SPA peut alors présenter le plan + warnings à l'admin avant confirm.
	 *
	 * @param string         $uuid     UUID du step à rollback.
	 * @param list<int>|null $post_ids Sous-ensemble, ou null = tout le step.
	 * @param bool           $dry_run  True = plan seulement, pas de write.
	 * @return array{
	 *   step: array{uuid: string, finished_at: string|null}|null,
	 *   actions: list<array{post_id: int, status: string, reason?: string, revision_id?: int, message?: string}>,
	 *   cascade: array<int, list<string>>,
	 *   summary: array{rolled_back: int, skipped: int, errors: int, dry_run: bool},
	 * }
	 */
	public function rollback_step( string $uuid, ?array $post_ids = null, bool $dry_run = false ): array {
		$step = $this->steps->find_by_uuid( $uuid );
		if ( null === $step ) {
			return array(
				'step'    => null,
				'actions' => array(),
				'cascade' => array(),
				'summary' => array(
					'rolled_back' => 0,
					'skipped'     => 0,
					'errors'      => 0,
					'dry_run'     => $dry_run,
				),
			);
		}

		// Périmètre = intersection( post_ids filtre, affected_post_ids du step ).
		// On itère sur affected_post_ids pour garder l'ordre stable du step.
		$filter_set = null === $post_ids ? null : array_flip( $post_ids );
		$scope      = array();
		foreach ( $step->affected_post_ids as $candidate ) {
			if ( null === $filter_set || isset( $filter_set[ $candidate ] ) ) {
				$scope[] = $candidate;
			}
		}

		$actions     = array();
		$cascade     = array();
		$rolled_back = 0;
		$skipped     = 0;
		$errors      = 0;

		foreach ( $scope as $post_id ) {
			$entry          = $step->per_article_results[ $post_id ] ?? null;
			$action         = $this->rollback_one( $post_id, $entry, $dry_run );
			$actions[]      = $action;
			match ( $action['status'] ) {
				'rolled_back', 'would_rollback' => $rolled_back++,
				'skipped'                       => $skipped++,
				'error'                         => $errors++,
				default                         => null,
			};

			// Cascade : seulement pour les articles qu'on a effectivement
			// rollback-és (ou qu'on rollback-erait en dry_run). Inutile de
			// signaler des cascades sur des articles skip-és.
			if ( 'rolled_back' === $action['status'] || 'would_rollback' === $action['status'] ) {
				$subsequent = $this->detect_cascade_for_post( $post_id, $step );
				if ( array() !== $subsequent ) {
					$cascade[ $post_id ] = $subsequent;
				}
			}
		}

		return array(
			'step'    => array(
				'uuid'        => $step->step_uuid,
				'finished_at' => $step->finished_at,
			),
			'actions' => $actions,
			'cascade' => $cascade,
			'summary' => array(
				'rolled_back' => $rolled_back,
				'skipped'     => $skipped,
				'errors'      => $errors,
				'dry_run'     => $dry_run,
			),
		);
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Rollback d'un article. Validation → action → recalcul diagnostic.
	 *
	 * @param int                       $post_id Article.
	 * @param array<string, mixed>|null $entry   Entrée per_article_results, ou null.
	 * @param bool                      $dry_run Mode plan.
	 * @return array{post_id: int, status: string, reason?: string, revision_id?: int, message?: string}
	 */
	private function rollback_one( int $post_id, ?array $entry, bool $dry_run ): array {
		if ( null === $entry ) {
			return array(
				'post_id' => $post_id,
				'status'  => 'skipped',
				'reason'  => self::SKIP_NO_RESULT,
			);
		}
		$status = (string) ( $entry['status'] ?? '' );
		if ( ArticleResult::STATUS_SUCCESS !== $status ) {
			return array(
				'post_id' => $post_id,
				'status'  => 'skipped',
				'reason'  => self::SKIP_NOT_SUCCESS,
			);
		}
		$revision_id = isset( $entry['revision_id'] ) ? (int) $entry['revision_id'] : 0;
		if ( $revision_id <= 0 ) {
			return array(
				'post_id' => $post_id,
				'status'  => 'skipped',
				'reason'  => self::SKIP_REVISION_NOT_CAPTURED,
			);
		}

		$revision = get_post( $revision_id );
		if ( ! $revision instanceof WP_Post || 'revision' !== $revision->post_type ) {
			return array(
				'post_id'     => $post_id,
				'status'      => 'skipped',
				'reason'      => self::SKIP_REVISION_PURGED,
				'revision_id' => $revision_id,
			);
		}
		if ( (int) $revision->post_parent !== $post_id ) {
			return array(
				'post_id'     => $post_id,
				'status'      => 'skipped',
				'reason'      => self::SKIP_REVISION_MISMATCH,
				'revision_id' => $revision_id,
			);
		}

		if ( $dry_run ) {
			return array(
				'post_id'     => $post_id,
				'status'      => 'would_rollback',
				'revision_id' => $revision_id,
			);
		}

		$restored = wp_restore_post_revision( $revision_id );
		if ( null === $restored || false === $restored ) {
			return array(
				'post_id'     => $post_id,
				'status'      => 'error',
				'revision_id' => $revision_id,
				'message'     => 'wp_restore_post_revision returned null/false',
			);
		}

		$this->refresh_diagnostic_for( $post_id );

		return array(
			'post_id'     => $post_id,
			'status'      => 'rolled_back',
			'revision_id' => $revision_id,
		);
	}

	/**
	 * Steps postérieurs ayant **effectivement** écrit (status `success`) sur
	 * `$post_id`. Filtre les faux positifs : `affected_post_ids` inclut les
	 * articles ciblés au démarrage, mais un step peut avoir terminé en error
	 * ou regression_pending sans toucher au post_content — donc pas de
	 * cascade réelle dans ce cas.
	 *
	 * @param int        $post_id Article.
	 * @param StepRecord $step    Step source (utilisé pour l'exclusion et la borne temporelle).
	 * @return list<string> UUIDs des steps postérieurs ayant écrit.
	 */
	private function detect_cascade_for_post( int $post_id, StepRecord $step ): array {
		$after = $step->finished_at ?? $step->started_at;
		if ( '' === $after ) {
			return array();
		}
		$subsequent = $this->steps->find_subsequent_steps_for_post(
			$post_id,
			$after,
			$step->step_uuid
		);
		$uuids = array();
		foreach ( $subsequent as $candidate ) {
			$entry = $candidate->per_article_results[ $post_id ] ?? null;
			if ( null === $entry ) {
				continue;
			}
			if ( ArticleResult::STATUS_SUCCESS !== (string) ( $entry['status'] ?? '' ) ) {
				continue;
			}
			$uuids[] = $candidate->step_uuid;
		}
		return $uuids;
	}

	/**
	 * Recalcule et upsert le diagnostic d'un article post-restore.
	 * Symétrique de `StepRunner::refresh_diagnostic_for()`.
	 *
	 * @param int $post_id Article.
	 */
	private function refresh_diagnostic_for( int $post_id ): void {
		$fresh = get_post( $post_id );
		if ( $fresh instanceof WP_Post ) {
			$diagnostic = $this->engine->diagnose( $fresh );
			$this->diagnostics->upsert( $diagnostic );
		}
	}
}
