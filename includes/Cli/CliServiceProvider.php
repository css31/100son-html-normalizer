<?php
/**
 * CliServiceProvider — point d'enregistrement des commandes WP-CLI.
 *
 * Cf. cahier v2.0 §4.6 (commandes WP-CLI) et §11 étape 21.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Branche les commandes WP-CLI auprès de la façade `WP_CLI`.
 *
 * Pourquoi un provider plutôt qu'un appel direct dans chaque commande :
 *  - centralise le « if defined('WP_CLI') » en un seul endroit
 *    (Plugin::boot peut appeler register() sans avoir à tester WP_CLI) ;
 *  - autorise l'idempotence sur register() (Plugin::boot peut être appelé
 *    plus d'une fois en théorie) ;
 *  - facilite l'extension Phase 5.5+ : ajouter une commande = injecter
 *    une nouvelle entrée dans le tableau côté Plugin.php.
 *
 * Format des entrées : tuple `{name: string, callable: array|string}`. Le
 * `callable` est typiquement `[$instance, 'method_name']` pour pointer
 * sur une méthode précise (utile quand `list` est réservé en PHP et que
 * la méthode s'appelle `list_steps`).
 */
final class CliServiceProvider {

	/**
	 * Indique si `register()` a déjà branché les commandes (idempotence).
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * @param list<array{name: string, callable: array|string|callable}> $commands
	 *   Liste des commandes à enregistrer. Chaque entrée : nom WP-CLI complet
	 *   (`htmln steps list`, `htmln scan`, …) + callable cible.
	 */
	public function __construct(
		private readonly array $commands,
	) {}

	/**
	 * Enregistre toutes les commandes auprès de WP_CLI. No-op si la classe
	 * `WP_CLI` n'est pas disponible (contexte non-CLI).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		$this->registered = true;

		foreach ( $this->commands as $entry ) {
			\WP_CLI::add_command( $entry['name'], $entry['callable'] );
		}
	}

	/**
	 * Lecture seule — utile en tests pour assertion.
	 *
	 * @return list<array{name: string, callable: array|string|callable}>
	 */
	public function commands(): array {
		return $this->commands;
	}
}
