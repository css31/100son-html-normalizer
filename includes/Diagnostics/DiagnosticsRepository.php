<?php
/**
 * DiagnosticsRepository — accès lecture/écriture à la table custom V1.0.
 *
 * Cf. cahier v2.0 §4.2 (schéma) et §3.1 F12 (DiagnosticEngine).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Repository CRUD sur `{$wpdb->prefix}son100_htmln_diagnostics`.
 *
 * Convention :
 *  - Méthodes lecture renvoient `null` ou un DTO `DiagnosticRecord` ;
 *  - Méthodes écriture renvoient `int` (rows affectées) ou `bool` (succès).
 *  - Tous les accès passent par `wpdb::prepare()` quand des valeurs externes
 *    entrent dans le SQL — pas de concaténation directe.
 *
 * Volontairement non-final pour permettre l'extension/stub en tests
 * d'intégration (DiagnosticInvalidatorTest…). Même convention que
 * `SettingsRepository` et `PresetRegistry`.
 */
class DiagnosticsRepository {

	/**
	 * Adapter `$wpdb`.
	 *
	 * @var \wpdb
	 */
	private object $wpdb;

	/**
	 * Nom complet de la table avec préfixe.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * @param object|null $wpdb Adapter `\wpdb`. Si null, utilise `$GLOBALS['wpdb']`.
	 */
	public function __construct( ?object $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		/** @var \wpdb $wpdb */
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'son100_htmln_diagnostics';
	}

	// =========================================================================
	//  Lecture
	// =========================================================================

	/**
	 * Récupère le diagnostic d'un article (un seul existe par post_id).
	 *
	 * @param int $post_id Identifiant de l'article.
	 * @return DiagnosticRecord|null
	 */
	public function find_by_post_id( int $post_id ): ?DiagnosticRecord {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$this->table}` WHERE post_id = %d LIMIT 1",
			$post_id
		);
		$row = $this->wpdb->get_row( $sql, 'ARRAY_A' );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return DiagnosticRecord::from_db_row( $row );
	}

	/**
	 * Liste paginée des diagnostics filtrés par status.
	 *
	 * @param string $status `normal` ou `to_improve`.
	 * @param int    $limit  Nombre max de résultats.
	 * @param int    $offset Décalage.
	 * @return list<DiagnosticRecord>
	 */
	public function list_by_status( string $status, int $limit = 50, int $offset = 0 ): array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$this->table}` WHERE status = %s ORDER BY diagnosed_at DESC LIMIT %d OFFSET %d",
			$status,
			max( 1, $limit ),
			max( 0, $offset )
		);
		return $this->fetch_records( $sql );
	}

	/**
	 * Liste paginée des diagnostics is_stale = 1 (onglet F13 « Diagnostics
	 * obsolètes »).
	 *
	 * @param int $limit  Nombre max de résultats.
	 * @param int $offset Décalage.
	 * @return list<DiagnosticRecord>
	 */
	public function list_stale( int $limit = 50, int $offset = 0 ): array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$this->table}` WHERE is_stale = 1 ORDER BY diagnosed_at DESC LIMIT %d OFFSET %d",
			max( 1, $limit ),
			max( 0, $offset )
		);
		return $this->fetch_records( $sql );
	}

	/**
	 * Compte des diagnostics par status, utile pour les badges des onglets F13.
	 *
	 * `total` : nombre total de lignes dans la table, peu importe is_stale ou
	 * status. Utile pour `GET /diagnostics/stats` (F13) et la SPA pour calculer
	 * la part de stale dans l'ensemble.
	 *
	 * @return array{normal: int, to_improve: int, stale: int, total: int}
	 */
	public function count_by_status(): array {
		$sql_normal   = "SELECT COUNT(*) FROM `{$this->table}` WHERE status = 'normal' AND is_stale = 0";
		$sql_improve  = "SELECT COUNT(*) FROM `{$this->table}` WHERE status = 'to_improve' AND is_stale = 0";
		$sql_stale    = "SELECT COUNT(*) FROM `{$this->table}` WHERE is_stale = 1";
		$sql_total    = "SELECT COUNT(*) FROM `{$this->table}`";

		return array(
			'normal'     => (int) $this->wpdb->get_var( $sql_normal ),
			'to_improve' => (int) $this->wpdb->get_var( $sql_improve ),
			'stale'      => (int) $this->wpdb->get_var( $sql_stale ),
			'total'      => (int) $this->wpdb->get_var( $sql_total ),
		);
	}

	/**
	 * Liste paginée unifiée pour `GET /diagnostics` (F13). Filtre par status
	 * optionnel : `null` retourne tous les diagnostics.
	 *
	 * Sémantique du filtre `status` :
	 *  - `'normal'`     : `status = 'normal'` ET `is_stale = 0`.
	 *  - `'to_improve'` : `status = 'to_improve'` ET `is_stale = 0`.
	 *  - `'stale'`      : `is_stale = 1` (peu importe `status`).
	 *  - `null`         : pas de filtre, tous les diagnostics.
	 *  - autre valeur   : liste vide (status inconnu — défense en profondeur).
	 *
	 * @param string|null $status Filtre status, ou null pour tous.
	 * @param int         $limit  Nombre max.
	 * @param int         $offset Décalage.
	 * @return list<DiagnosticRecord>
	 */
	public function list_paginated( ?string $status, int $limit = 50, int $offset = 0 ): array {
		$where = $this->build_status_clause( $status );
		if ( null === $where ) {
			return array();
		}
		$sql = "SELECT * FROM `{$this->table}`";
		if ( '' !== $where ) {
			$sql .= ' WHERE ' . $where;
		}
		$sql .= ' ORDER BY diagnosed_at DESC LIMIT %d OFFSET %d';
		return $this->fetch_records(
			$this->wpdb->prepare( $sql, max( 1, $limit ), max( 0, $offset ) )
		);
	}

	/**
	 * Comptage paginé compagnon de `list_paginated()` — sert au calcul de
	 * `total_pages` côté contrôleur REST.
	 *
	 * @param string|null $status Filtre status, ou null pour tous.
	 * @return int
	 */
	public function count_paginated( ?string $status ): int {
		$where = $this->build_status_clause( $status );
		if ( null === $where ) {
			return 0;
		}
		$sql = "SELECT COUNT(*) FROM `{$this->table}`";
		if ( '' !== $where ) {
			$sql .= ' WHERE ' . $where;
		}
		return (int) $this->wpdb->get_var( $sql );
	}

	// =========================================================================
	//  Écriture
	// =========================================================================

	/**
	 * Insère ou met à jour le diagnostic d'un article (un seul par post_id).
	 *
	 * Stratégie : on regarde si une ligne existe ; UPDATE si oui, INSERT sinon.
	 * Pas de `INSERT … ON DUPLICATE KEY` car portabilité douteuse selon les
	 * adapters $wpdb stubbés.
	 *
	 * @param DiagnosticRecord $record DTO à persister (id ignoré pour upsert).
	 * @return bool Vrai si l'opération a effectué une écriture.
	 */
	public function upsert( DiagnosticRecord $record ): bool {
		$existing = $this->find_by_post_id( $record->post_id );
		$row      = $record->to_db_row();
		// Pour l'upsert, on retire l'id du payload (la colonne reste pilotée par AUTO_INCREMENT).
		unset( $row['id'] );

		if ( null === $existing ) {
			$result = $this->wpdb->insert( $this->table, $row );
			return false !== $result && 0 !== $result;
		}

		$result = $this->wpdb->update(
			$this->table,
			$row,
			array( 'post_id' => $record->post_id )
		);
		return false !== $result;
	}

	/**
	 * Marque is_stale = 1 sur le diagnostic d'un article. No-op si aucun
	 * diagnostic n'existe pour ce post_id.
	 *
	 * @param int $post_id Identifiant de l'article.
	 * @return bool Vrai si une ligne a été modifiée.
	 */
	public function mark_stale_for_post( int $post_id ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'is_stale' => 1 ),
			array( 'post_id' => $post_id )
		);
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Supprime le diagnostic d'un article (utile lors d'un trash/delete WP).
	 *
	 * @param int $post_id Identifiant de l'article.
	 * @return bool Vrai si une ligne a été supprimée.
	 */
	public function delete_for_post( int $post_id ): bool {
		$sql = $this->wpdb->prepare(
			"DELETE FROM `{$this->table}` WHERE post_id = %d",
			$post_id
		);
		return $this->wpdb->query( $sql ) > 0;
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Construit la clause WHERE pour le filtrage par status. Hardcodé car
	 * limité à 4 valeurs whitelistées — pas de prepare() nécessaire.
	 *
	 * @param string|null $status Statut ou null.
	 * @return string|null Clause WHERE (ou chaîne vide pour pas de filtre),
	 *                     ou `null` pour signaler un status inconnu (le
	 *                     caller retourne array vide / 0 sans frapper la BDD).
	 */
	private function build_status_clause( ?string $status ): ?string {
		return match ( $status ) {
			null         => '',
			'normal'     => "status = 'normal' AND is_stale = 0",
			'to_improve' => "status = 'to_improve' AND is_stale = 0",
			'stale'      => 'is_stale = 1',
			default      => null,
		};
	}

	/**
	 * @param string $sql SQL préparé.
	 * @return list<DiagnosticRecord>
	 */
	private function fetch_records( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$result = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$result[] = DiagnosticRecord::from_db_row( $row );
			}
		}
		return $result;
	}
}
