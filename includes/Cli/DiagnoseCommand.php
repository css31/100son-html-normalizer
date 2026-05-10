<?php
/**
 * DiagnoseCommand — commande WP-CLI `wp htmln scan` (F12).
 *
 * Cf. cahier v2.0 §4.6 (commandes WP-CLI) et §11 étape 21.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Cli;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticBatchRunner;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use WP_CLI;
use WP_Post;

/**
 * Trois modes invocables sur `__invoke()` selon les arguments :
 *
 *  1. `wp htmln scan` ou `wp htmln scan --all [--post-type=post,page]`
 *     — scan complet via DiagnosticBatchRunner (boucle séquentielle des
 *     chunks côté CLI, contrairement à la SPA qui les pousse depuis le
 *     navigateur).
 *
 *  2. `wp htmln scan <id>`
 *     — diagnostique un unique article via DiagnosticEngine + upsert.
 *
 *  3. `wp htmln scan --status=stale [--rebuild]`
 *     — liste les articles is_stale=1. `--rebuild` re-diagnostique
 *     chacun (équivalent du flow F12 ciblé).
 *
 * Sorties : log structuré JSON pour les listings, success() pour les
 * confirmations terminales, error() (= exit non-zero) pour les erreurs.
 */
class DiagnoseCommand {

	/**
	 * @param DiagnosticBatchRunner $runner Orchestrateur scan complet (Phase 3.3).
	 * @param DiagnosticEngine      $engine Calcul diagnostic d'un article (Phase 3.2).
	 * @param DiagnosticsRepository $repo   Persistance diagnostics (Phase 2.2).
	 */
	public function __construct(
		private readonly DiagnosticBatchRunner $runner,
		private readonly DiagnosticEngine $engine,
		private readonly DiagnosticsRepository $repo,
	) {}

	/**
	 * Dispatch principal de la commande `wp htmln scan`.
	 *
	 * @param list<string>          $args       Arguments positionnels (post_id éventuel).
	 * @param array<string, string|bool> $assoc_args Flags `--all`, `--post-type`, `--status`, `--rebuild`.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Mode 3 : --status=stale (priorité haute — flag explicite).
		if ( isset( $assoc_args['status'] ) && 'stale' === (string) $assoc_args['status'] ) {
			$this->scan_stale( ! empty( $assoc_args['rebuild'] ) );
			return;
		}

		// Mode 2 : scan <id> (un argument positionnel numérique).
		if ( isset( $args[0] ) && is_numeric( $args[0] ) ) {
			$this->scan_single( (int) $args[0] );
			return;
		}

		// Mode 1 : scan --all (ou rien — par défaut on scanne tout).
		$post_types = $this->parse_post_types( $assoc_args['post-type'] ?? null );
		$this->scan_all( $post_types );
	}

	// =========================================================================
	//  Modes
	// =========================================================================

	/**
	 * Scan complet : boucle sur les chunks issus de start_batch.
	 *
	 * @param list<string>|null $post_types_override Override post_types ou null.
	 * @return void
	 */
	private function scan_all( ?array $post_types_override ): void {
		$batch = $this->runner->start_batch( null, $post_types_override );
		$total = (int) $batch['total_articles'];

		WP_CLI::log( sprintf(
			'Starting scan: %d article(s) to diagnose (chunk_size=%d).',
			$total,
			$batch['chunk_size']
		) );

		if ( 0 === $total ) {
			WP_CLI::success( 'No article to diagnose.' );
			return;
		}

		$processed = 0;
		$chunks    = array_chunk( $batch['post_ids'], (int) $batch['chunk_size'] );
		foreach ( $chunks as $chunk ) {
			$results    = $this->runner->process_chunk( $chunk );
			$processed += count( $results );
			WP_CLI::log( sprintf( '  …processed %d / %d', $processed, $total ) );
		}

		WP_CLI::success( sprintf( 'Scan complete. %d / %d article(s) diagnosed.', $processed, $total ) );
	}

	/**
	 * Diagnostic d'un article unique.
	 *
	 * @param int $post_id Identifiant.
	 * @return void
	 */
	private function scan_single( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
			return;
		}

		$record = $this->engine->diagnose( $post );
		$this->repo->upsert( $record );

		WP_CLI::log( $this->encode_json( array(
			'post_id'        => $record->post_id,
			'status'         => $record->status,
			'matching_rules' => $record->matching_rules,
		) ) );
		WP_CLI::success( sprintf( 'Diagnosed post %d as "%s".', $post_id, $record->status ) );
	}

	/**
	 * Liste / re-diagnostique les articles `is_stale = 1`.
	 *
	 * @param bool $rebuild Si true, re-diagnostique chaque stale.
	 * @return void
	 */
	private function scan_stale( bool $rebuild ): void {
		$stale = $this->repo->list_stale( 1000, 0 );

		if ( array() === $stale ) {
			WP_CLI::success( 'No stale diagnostics.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d stale diagnostic(s).', count( $stale ) ) );

		if ( ! $rebuild ) {
			$ids = array_map( static fn( $r ) => $r->post_id, $stale );
			WP_CLI::log( $this->encode_json( array( 'stale_post_ids' => $ids ) ) );
			WP_CLI::log( 'Pass --rebuild to re-diagnose them.' );
			return;
		}

		$rebuilt = 0;
		foreach ( $stale as $record ) {
			$post = get_post( $record->post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$fresh = $this->engine->diagnose( $post );
			$this->repo->upsert( $fresh );
			++$rebuilt;
		}

		WP_CLI::success( sprintf( 'Re-diagnosed %d / %d stale article(s).', $rebuilt, count( $stale ) ) );
	}

	// =========================================================================
	//  Helpers
	// =========================================================================

	/**
	 * Parse `--post-type=post,page` en `list<string>` ou null.
	 *
	 * @param mixed $raw Valeur brute du flag.
	 * @return list<string>|null
	 */
	private function parse_post_types( mixed $raw ): ?array {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		if ( is_array( $raw ) ) {
			return array_values( array_filter(
				array_map( static fn( $v ) => sanitize_text_field( (string) $v ), $raw ),
				static fn( string $s ): bool => '' !== $s
			) );
		}
		$parts = array_map( 'trim', explode( ',', (string) $raw ) );
		return array_values( array_filter( $parts, static fn( string $s ): bool => '' !== $s ) );
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
