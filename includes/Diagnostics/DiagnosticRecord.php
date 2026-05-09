<?php
/**
 * DiagnosticRecord — DTO d'une ligne de `son100_htmln_diagnostics`.
 *
 * Cf. cahier v2.0 §4.2 (schéma) et §3.1 F12 (sémantique).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Représentation immuable d'un diagnostic article.
 *
 * Statuts possibles :
 *  - `normal`     : aucune règle ne matcherait + métriques cohérentes
 *  - `to_improve` : au moins une règle matcherait, ou métrique anormale
 *
 * `is_stale` est mis à 1 par le hook `save_post` (cf. §4.3) : le diagnostic
 * existe mais est désynchronisé par rapport au contenu actuel.
 */
final class DiagnosticRecord {

	public const STATUS_NORMAL     = 'normal';
	public const STATUS_TO_IMPROVE = 'to_improve';

	/**
	 * @param int|null                                      $id                          Identifiant en base (null tant que non persisté).
	 * @param int                                           $post_id                     ID de l'article diagnostiqué.
	 * @param string                                        $status                      `normal` ou `to_improve`.
	 * @param list<array{rule_id: string, occurrences: int}> $matching_rules              Règles qui matcheraient + occurrences (peut être vide pour `normal`).
	 * @param array<string, mixed>                          $metrics                     Snapshot 7 métriques γ ; structure cf. MetricsSnapshot.
	 * @param bool                                          $is_stale                    Vrai si invalide depuis le dernier `save_post`.
	 * @param string                                        $diagnosed_at                Datetime MySQL (`Y-m-d H:i:s`) au moment du diagnostic.
	 * @param string|null                                   $post_modified_at_diagnosis  `post_modified` snapshot au diagnostic, pour détection stale fine.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly int $post_id,
		public readonly string $status,
		public readonly array $matching_rules,
		public readonly array $metrics,
		public readonly bool $is_stale,
		public readonly string $diagnosed_at,
		public readonly ?string $post_modified_at_diagnosis,
	) {}

	/**
	 * Reconstruit un DiagnosticRecord depuis une ligne brute renvoyée par
	 * `wpdb` (associative). Décode les colonnes JSON.
	 *
	 * @param array<string, mixed> $row Ligne `wpdb` (associative).
	 * @return self
	 */
	public static function from_db_row( array $row ): self {
		$matching = self::decode_json_list( (string) ( $row['matching_rules'] ?? '' ) );
		$metrics  = self::decode_json_assoc( (string) ( $row['metrics'] ?? '' ) );

		return new self(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			post_id: (int) ( $row['post_id'] ?? 0 ),
			status: (string) ( $row['status'] ?? self::STATUS_NORMAL ),
			matching_rules: $matching,
			metrics: $metrics,
			is_stale: (bool) ( $row['is_stale'] ?? false ),
			diagnosed_at: (string) ( $row['diagnosed_at'] ?? '' ),
			post_modified_at_diagnosis: isset( $row['post_modified_at_diagnosis'] ) && '' !== $row['post_modified_at_diagnosis']
				? (string) $row['post_modified_at_diagnosis']
				: null,
		);
	}

	/**
	 * Représentation BDD prête à passer à `$wpdb->insert()` / `$wpdb->update()`.
	 *
	 * Encode les colonnes JSON et coerce les types.
	 *
	 * @return array<string, mixed>
	 */
	public function to_db_row(): array {
		$row = array(
			'post_id'                    => $this->post_id,
			'status'                     => $this->status,
			'matching_rules'             => self::encode_json( $this->matching_rules ),
			'metrics'                    => self::encode_json( $this->metrics ),
			'is_stale'                   => $this->is_stale ? 1 : 0,
			'diagnosed_at'               => $this->diagnosed_at,
			'post_modified_at_diagnosis' => $this->post_modified_at_diagnosis,
		);
		if ( null !== $this->id ) {
			$row['id'] = $this->id;
		}
		return $row;
	}

	/**
	 * @param array<string, mixed> $changes Champs à modifier (clés = propriétés).
	 * @return self
	 */
	public function with( array $changes ): self {
		return new self(
			id: array_key_exists( 'id', $changes ) ? $changes['id'] : $this->id,
			post_id: array_key_exists( 'post_id', $changes ) ? (int) $changes['post_id'] : $this->post_id,
			status: array_key_exists( 'status', $changes ) ? (string) $changes['status'] : $this->status,
			matching_rules: array_key_exists( 'matching_rules', $changes ) ? $changes['matching_rules'] : $this->matching_rules,
			metrics: array_key_exists( 'metrics', $changes ) ? $changes['metrics'] : $this->metrics,
			is_stale: array_key_exists( 'is_stale', $changes ) ? (bool) $changes['is_stale'] : $this->is_stale,
			diagnosed_at: array_key_exists( 'diagnosed_at', $changes ) ? (string) $changes['diagnosed_at'] : $this->diagnosed_at,
			post_modified_at_diagnosis: array_key_exists( 'post_modified_at_diagnosis', $changes )
				? $changes['post_modified_at_diagnosis']
				: $this->post_modified_at_diagnosis,
		);
	}

	/**
	 * Décode une chaîne JSON en list<array>. Retourne tableau vide si invalide.
	 *
	 * @param string $json JSON encodé.
	 * @return list<array{rule_id: string, occurrences: int}>
	 */
	private static function decode_json_list( string $json ): array {
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$result = array();
		foreach ( $decoded as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$rule_id     = isset( $entry['rule_id'] ) ? (string) $entry['rule_id'] : '';
			$occurrences = isset( $entry['occurrences'] ) ? (int) $entry['occurrences'] : 0;
			if ( '' === $rule_id ) {
				continue;
			}
			$result[] = array( 'rule_id' => $rule_id, 'occurrences' => $occurrences );
		}
		return $result;
	}

	/**
	 * Décode une chaîne JSON en array assoc. Retourne tableau vide si invalide.
	 *
	 * @param string $json JSON encodé.
	 * @return array<string, mixed>
	 */
	private static function decode_json_assoc( string $json ): array {
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Encode un array en JSON, retourne chaine vide si echec.
	 *
	 * @param array<mixed> $value Valeur a encoder.
	 * @return string
	 */
	private static function encode_json( array $value ): string {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}
}
