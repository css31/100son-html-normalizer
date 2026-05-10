<?php
/**
 * StepsCommand — commande WP-CLI `wp htmln steps {list, show, export}` (F16).
 *
 * Cf. cahier v2.0 §4.6 (commandes WP-CLI) et §11 étape 21.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Cli;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Steps\StepRecord;
use Cent_Son\Html_Normalizer\Steps\StepRunner;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use WP_CLI;

/**
 * Sous-commandes :
 *
 *  - `wp htmln steps list   [--from=<date>] [--to=<date>] [--limit=<n>]`
 *  - `wp htmln steps show   <uuid>`
 *  - `wp htmln steps export [--file=<path>] [--from=<date>] [--to=<date>]`
 *
 * Format de sortie : JSON pour les structures (parsable par scripts),
 * texte court pour les confirmations / erreurs. Pas de format `table`
 * en V1.0 (les pas ont des champs imbriqués comme `per_article_results`
 * difficiles à tableiser proprement).
 *
 * `list` est un mot réservé en PHP : la méthode publique s'appelle
 * `list_steps()` et est enregistrée comme commande `'htmln steps list'`
 * via `CliServiceProvider`.
 *
 * Conserve la même limite max que la couche REST (cap export 200) pour
 * cohérence des contrats sur le corpus V1.0.
 */
class StepsCommand {

	/**
	 * Cap export V1.0 (cohérent avec StepsController::EXPORT_MAX).
	 */
	public const EXPORT_MAX = 200;

	/**
	 * Limite par défaut de la sous-commande `list`.
	 */
	public const DEFAULT_LIST_LIMIT = 50;

	/**
	 * @param StepRunner      $runner Orchestrateur Phase 4 (pour resume_progress).
	 * @param StepsRepository $repo   Persistance pas (lecture historique).
	 */
	public function __construct(
		private readonly StepRunner $runner,
		private readonly StepsRepository $repo,
	) {}

	/**
	 * `wp htmln steps list [--from=<date>] [--to=<date>] [--limit=<n>]`.
	 *
	 * @param list<string>          $args      Arguments positionnels (vides).
	 * @param array<string, string> $assoc_args Flags `--from`, `--to`, `--limit`.
	 * @return void
	 */
	public function list_steps( array $args, array $assoc_args ): void {
		unset( $args );
		$from  = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : null;
		$to    = isset( $assoc_args['to'] )   ? (string) $assoc_args['to']   : null;
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : self::DEFAULT_LIST_LIMIT;

		$total = $this->repo->count_filtered( $from, $to );
		$items = $this->repo->list_filtered( $from, $to, $limit, 0 );

		WP_CLI::log( $this->encode_json( array(
			'total'    => $total,
			'returned' => count( $items ),
			'items'    => array_map( array( $this, 'step_to_array' ), $items ),
		) ) );
	}

	/**
	 * `wp htmln steps show <uuid>`.
	 *
	 * @param list<string>          $args       `[<uuid>]`.
	 * @param array<string, string> $assoc_args Inutilisé.
	 * @return void
	 */
	public function show( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$uuid = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $uuid ) {
			WP_CLI::error( 'Usage: wp htmln steps show <uuid>' );
			return;
		}

		$record = $this->repo->find_by_uuid( $uuid );
		if ( null === $record ) {
			WP_CLI::error( sprintf( 'No step found for uuid %s', $uuid ) );
			return;
		}

		$progress = $this->runner->resume_progress( $uuid );

		WP_CLI::log( $this->encode_json( array(
			'step'     => $this->step_to_array( $record ),
			'progress' => $progress,
		) ) );
	}

	/**
	 * `wp htmln steps export [--file=<path>] [--from=<date>] [--to=<date>]`.
	 *
	 * V1.0 : JSON uniquement (format CSV différé V1.1, cohérent avec
	 * StepsController::export()).
	 *
	 * @param list<string>          $args       Inutilisé.
	 * @param array<string, string> $assoc_args Flags `--file`, `--from`, `--to`.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );
		$from = isset( $assoc_args['from'] ) ? (string) $assoc_args['from'] : null;
		$to   = isset( $assoc_args['to'] )   ? (string) $assoc_args['to']   : null;
		$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';

		$total = $this->repo->count_filtered( $from, $to );
		$items = $this->repo->list_filtered( $from, $to, self::EXPORT_MAX, 0 );

		$payload = $this->encode_json( array(
			'total'     => $total,
			'capped'    => $total > self::EXPORT_MAX,
			'capped_at' => self::EXPORT_MAX,
			'items'     => array_map( array( $this, 'step_to_array' ), $items ),
		) );

		if ( '' === $file ) {
			WP_CLI::log( $payload );
			return;
		}

		$bytes = file_put_contents( $file, $payload );
		if ( false === $bytes ) {
			WP_CLI::error( sprintf( 'Failed to write export to %s', $file ) );
			return;
		}
		WP_CLI::success( sprintf( 'Exported %d steps to %s (%d bytes).', count( $items ), $file, $bytes ) );
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Sérialisation StepRecord pour sortie JSON (alignée sur
	 * `StepsController::step_to_array()` afin que la SPA et la CLI
	 * partagent le même contrat).
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
	 * Encode JSON pretty-printé pour lisibilité humaine en CLI.
	 *
	 * @param mixed $data Données.
	 * @return string
	 */
	private function encode_json( mixed $data ): string {
		$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? $encoded : '{}';
	}
}
