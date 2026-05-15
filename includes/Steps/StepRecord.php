<?php
/**
 * StepRecord — DTO d'une ligne de `son100_htmln_steps`.
 *
 * Cf. cahier v2.0 §4.2 (schéma) et §3.1 F14/F16 (sémantique).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * Représentation immuable d'un pas applicatif (F14) ou d'une entrée
 * d'historique (F16).
 *
 * Cycle de vie typique :
 *  1. Création initiale `running` : `step_uuid`, `applied_rules`,
 *     `affected_post_ids`, `total_articles`, `started_at` ; `finished_at` null.
 *  2. Au fil de l'exécution : `per_article_results` accumule les résultats.
 *  3. À la finalisation : totaux remplis, `finished_at` posée.
 *
 * Un pas avec `finished_at IS NULL` est considéré « inachevé » et apparaît
 * dans le bandeau de reprise (cf. §3.1 F14 et hyp. 31 du cahier).
 */
final class StepRecord {

	/**
	 * @param int|null                                                                                       $id                  Identifiant en base (null avant insertion).
	 * @param string                                                                                         $step_uuid           UUID v4 du pas.
	 * @param list<string>                                                                                   $applied_rules       IDs des règles appliquées (snapshot au lancement).
	 * @param list<int>                                                                                      $affected_post_ids   IDs des articles ciblés.
	 * @param int                                                                                            $total_articles      Nombre total d'articles dans le pas.
	 * @param int                                                                                            $successful_articles Articles validés.
	 * @param int                                                                                            $refused_articles    Articles refusés (régression rejetée par admin).
	 * @param int                                                                                            $errored_articles    Articles en erreur technique.
	 * @param int                                                                                            $pending_articles    Articles en `regression_pending` à la finalisation (admin n'a pas arbitré).
	 * @param array<int, array{status: string, regression?: array<string, mixed>, error?: string}>           $per_article_results Détail par article.
	 * @param int|null                                                                                       $user_id             Auteur du pas (null en CLI).
	 * @param string                                                                                         $started_at          Datetime MySQL au lancement.
	 * @param string|null                                                                                    $finished_at         Datetime MySQL à la finalisation, ou null si inachevé.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $step_uuid,
		public readonly array $applied_rules,
		public readonly array $affected_post_ids,
		public readonly int $total_articles,
		public readonly int $successful_articles,
		public readonly int $refused_articles,
		public readonly int $errored_articles,
		public readonly int $pending_articles,
		public readonly array $per_article_results,
		public readonly ?int $user_id,
		public readonly string $started_at,
		public readonly ?string $finished_at,
	) {}

	/**
	 * Indique si le pas est terminé (finished_at posée).
	 */
	public function is_finished(): bool {
		return null !== $this->finished_at;
	}

	/**
	 * Reconstruit un StepRecord depuis une ligne brute de `wpdb`.
	 *
	 * @param array<string, mixed> $row Ligne associative.
	 * @return self
	 */
	public static function from_db_row( array $row ): self {
		return new self(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			step_uuid: (string) ( $row['step_uuid'] ?? '' ),
			applied_rules: self::decode_string_list( (string) ( $row['applied_rules'] ?? '' ) ),
			affected_post_ids: self::decode_int_list( (string) ( $row['affected_post_ids'] ?? '' ) ),
			total_articles: (int) ( $row['total_articles'] ?? 0 ),
			successful_articles: (int) ( $row['successful_articles'] ?? 0 ),
			refused_articles: (int) ( $row['refused_articles'] ?? 0 ),
			errored_articles: (int) ( $row['errored_articles'] ?? 0 ),
			pending_articles: (int) ( $row['pending_articles'] ?? 0 ),
			per_article_results: self::decode_per_article_results( (string) ( $row['per_article_results'] ?? '' ) ),
			user_id: isset( $row['user_id'] ) && '' !== $row['user_id'] && null !== $row['user_id']
				? (int) $row['user_id']
				: null,
			started_at: (string) ( $row['started_at'] ?? '' ),
			finished_at: isset( $row['finished_at'] ) && '' !== $row['finished_at'] && null !== $row['finished_at']
				? (string) $row['finished_at']
				: null,
		);
	}

	/**
	 * Sérialisation BDD (encodage des colonnes JSON).
	 *
	 * @return array<string, mixed>
	 */
	public function to_db_row(): array {
		$row = array(
			'step_uuid'           => $this->step_uuid,
			'applied_rules'       => self::encode_json( $this->applied_rules ),
			'affected_post_ids'   => self::encode_json( $this->affected_post_ids ),
			'total_articles'      => $this->total_articles,
			'successful_articles' => $this->successful_articles,
			'refused_articles'    => $this->refused_articles,
			'errored_articles'    => $this->errored_articles,
			'pending_articles'    => $this->pending_articles,
			'per_article_results' => self::encode_json( $this->per_article_results ),
			'user_id'             => $this->user_id,
			'started_at'          => $this->started_at,
			'finished_at'         => $this->finished_at,
		);
		if ( null !== $this->id ) {
			$row['id'] = $this->id;
		}
		return $row;
	}

	/**
	 * Décode une liste JSON de strings.
	 *
	 * @param string $json Chaine JSON.
	 * @return list<string>
	 */
	private static function decode_string_list( string $json ): array {
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values(
			array_map(
				static fn( mixed $v ): string => (string) $v,
				array_filter( $decoded, static fn( mixed $v ): bool => is_scalar( $v ) )
			)
		);
	}

	/**
	 * Décode une liste JSON d'entiers.
	 *
	 * @param string $json Chaine JSON.
	 * @return list<int>
	 */
	private static function decode_int_list( string $json ): array {
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values(
			array_map(
				static fn( mixed $v ): int => (int) $v,
				array_filter( $decoded, static fn( mixed $v ): bool => is_numeric( $v ) )
			)
		);
	}

	/**
	 * Décode `per_article_results` : map post_id => result.
	 *
	 * @param string $json Chaine JSON.
	 * @return array<int, array{status: string, regression?: array<string, mixed>, error?: string}>
	 */
	private static function decode_per_article_results( string $json ): array {
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$result = array();
		foreach ( $decoded as $post_id => $entry ) {
			if ( ! is_numeric( $post_id ) || ! is_array( $entry ) ) {
				continue;
			}
			$status = isset( $entry['status'] ) ? (string) $entry['status'] : '';
			if ( '' === $status ) {
				continue;
			}
			$record = array( 'status' => $status );
			if ( isset( $entry['regression'] ) && is_array( $entry['regression'] ) ) {
				$record['regression'] = $entry['regression'];
			}
			if ( isset( $entry['error'] ) ) {
				$record['error'] = (string) $entry['error'];
			}
			$result[ (int) $post_id ] = $record;
		}
		return $result;
	}

	/**
	 * @param array<mixed> $value Valeur a encoder.
	 * @return string
	 */
	private static function encode_json( array $value ): string {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}
}
