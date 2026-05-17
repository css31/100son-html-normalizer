<?php
/**
 * Activation du plugin.
 *
 * Seed des options par défaut + création des tables custom V1.0
 * (cf. cahier v2.0 §4.2 et §11 étape 1).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Hook d'activation.
 */
final class Activator {

	/**
	 * Version courante du schéma BDD (incrémentée à chaque évolution
	 * structurelle des tables custom). Utilisée pour piloter les futures
	 * migrations conditionnelles.
	 *
	 * Historique :
	 *  - 1.0.0 : V0.1 (aucune table custom)
	 *  - 2.0.0 : V1.0 Phase 2.1 — ajout `son100_htmln_diagnostics` et `son100_htmln_steps`
	 *  - 2.1.0 : post-rc3 — ajout colonne `builder_type` (VARCHAR(20) NULL +
	 *            KEY) dans `son100_htmln_diagnostics` pour filtrage SPA.
	 *            dbDelta est idempotent : ajoute la colonne sur instances
	 *            existantes, NULL initial puis re-rempli au prochain scan.
	 *  - 2.2.0 : post-rc4 — ajout colonne `pending_articles` (INT UNSIGNED
	 *            NOT NULL DEFAULT 0) dans `son100_htmln_steps`. Sépare le
	 *            bucket `regression_pending` du `errored_articles` qui
	 *            l'absorbait à tort. Le hook `maybe_run_db_upgrade` du
	 *            Plugin déclenche un re-run de `create_tables` (dbDelta
	 *            idempotent) sur les installs upgradées sans réactivation
	 *            manuelle.
	 */
	public const DB_VERSION = '2.2.0';

	/**
	 * Exécuté à l'activation du plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::seed_settings();
		self::seed_presets();
		self::seed_user_rules();
		self::create_tables();

		update_option( 'son100_htmln_db_version', self::DB_VERSION, false );
	}

	/**
	 * Crée (ou met à niveau) les tables custom V1.0 via `dbDelta()`.
	 *
	 * Idempotent : `dbDelta()` ne touche que les colonnes/clés divergentes.
	 * Les schémas sont la source de vérité du cahier v2.0 §4.2.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset_collate = $wpdb->get_charset_collate();
		$diag_table      = $wpdb->prefix . 'son100_htmln_diagnostics';
		$steps_table     = $wpdb->prefix . 'son100_htmln_steps';

		// F12 — Diagnostic batch (status par article + matching_rules + métriques + is_stale).
		// 2.1.0 — Ajout `builder_type` pour filtrage SPA Normaliser (cf. BuilderClassifier).
		$sql_diag = "CREATE TABLE $diag_table (
			id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL,
			builder_type VARCHAR(20) NULL,
			matching_rules LONGTEXT NULL,
			metrics LONGTEXT NULL,
			is_stale TINYINT(1) NOT NULL DEFAULT 0,
			diagnosed_at DATETIME NOT NULL,
			post_modified_at_diagnosis DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_post_id (post_id),
			KEY idx_status (status),
			KEY idx_stale (is_stale),
			KEY idx_builder (builder_type)
		) $charset_collate;";

		// F14/F16 — Historique des pas (UUID, règles appliquées, articles touchés, résultats par article).
		$sql_steps = "CREATE TABLE $steps_table (
			id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
			step_uuid VARCHAR(36) NOT NULL,
			applied_rules LONGTEXT NOT NULL,
			affected_post_ids LONGTEXT NOT NULL,
			total_articles INT UNSIGNED NOT NULL,
			successful_articles INT UNSIGNED NOT NULL DEFAULT 0,
			refused_articles INT UNSIGNED NOT NULL DEFAULT 0,
			errored_articles INT UNSIGNED NOT NULL DEFAULT 0,
			pending_articles INT UNSIGNED NOT NULL DEFAULT 0,
			per_article_results LONGTEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_step_uuid (step_uuid),
			KEY idx_started_at (started_at)
		) $charset_collate;";

		dbDelta( $sql_diag );
		dbDelta( $sql_steps );
	}

	/**
	 * Initialise les réglages globaux si l'option est absente.
	 *
	 * Cf. §4.2 du cahier (modèle de données).
	 *
	 * @return void
	 */
	private static function seed_settings(): void {
		if ( false !== get_option( 'son100_htmln_settings', false ) ) {
			return;
		}

		$defaults = array(
			'f8_post_types_selection' => array( 'post' ),
		);

		add_option( 'son100_htmln_settings', $defaults, '', 'no' );
	}

	/**
	 * Initialise / complète la configuration des règles.
	 *
	 * Tous les règles sont activés par défaut, avec leurs paramètres au
	 * défaut documenté en §3.1 et §14.
	 *
	 * Comportement de **propagation** (post-rc4) : au lieu d'un seed
	 * strictement initial (early-return si l'option existe), on fait un
	 * **merge** à chaque activation/réactivation — les règles
	 * existants sont préservés à l'identique (clés `enabled` et
	 * paramètres conservés), les nouvelles règles absents sont ajoutés
	 * avec leur config par défaut. Cela permet de propager les nouvelles
	 * règles ajoutées dans une version (R10 post-rc4, futures Pxx) à un
	 * site déjà activé sans écraser les choix de l'utilisateur — il
	 * suffit d'une réactivation du plugin.
	 *
	 * @return void
	 */
	private static function seed_presets(): void {
		$defaults = array(
			'R1' => array( 'enabled' => true ),
			'R2' => array( 'enabled' => true ),
			'R3' => array( 'enabled' => true ),
			'R4' => array( 'enabled' => true ),
			'R5' => array(
				'enabled'   => true,
				'threshold' => 2,
			),
			'R6' => array(
				'enabled'         => true,
				'keep_text_align' => true,
			),
			'R7' => array(
				'enabled'        => true,
				'threshold'      => 2,
				'markers'        => array(
					'dash'    => true,
					'emdash'  => true,
					'asterix' => true,
					'bullet'  => true,
					'numeric' => true,
				),
				'custom_markers' => array(),
			),
			'R8' => array(
				'enabled'  => true,
				'mappings' => array(
					'bold'   => true,
					'italic' => true,
				),
			),
			'R9'  => array( 'enabled' => true ),
			'R10' => array( 'enabled' => true ),
			'R11' => array( 'enabled' => true ),
			'R12' => array( 'enabled' => true ),
			'R13' => array( 'enabled' => true ),
			'R14' => array( 'enabled' => true ),
			'R15' => array( 'enabled' => true ),
			'R16' => array( 'enabled' => true ),
			'R17' => array( 'enabled' => true ),
		);

		$existing = get_option( 'son100_htmln_presets', false );
		if ( false === $existing || ! is_array( $existing ) ) {
			add_option( 'son100_htmln_presets', $defaults, '', 'no' );
			return;
		}

		// Merge : ne touche pas aux clés existantes, n'ajoute que celles
		// absentes. `+` array operator en PHP : préserve les clés du LHS,
		// complète avec les clés du RHS uniquement si absentes du LHS.
		$merged = $existing + $defaults;
		if ( $merged !== $existing ) {
			update_option( 'son100_htmln_presets', $merged, false );
		}
	}

	/**
	 * Initialise une bibliothèque de règles custom vide.
	 *
	 * @return void
	 */
	private static function seed_user_rules(): void {
		if ( false !== get_option( 'son100_htmln_rules_user', false ) ) {
			return;
		}
		add_option( 'son100_htmln_rules_user', array(), '', 'no' );
	}
}
