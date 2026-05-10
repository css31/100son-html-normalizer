<?php
/**
 * RestServiceProvider — point d'enregistrement des contrôleurs REST.
 *
 * Cf. cahier v2.0 §4.5 (endpoints REST) et §11 (étapes 10/15/19/20 — controllers).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Hook unique `rest_api_init` qui boucle sur les contrôleurs fournis et
 * appelle `register_routes()` sur chacun.
 *
 * Pourquoi un provider plutôt qu'un appel direct depuis chaque contrôleur :
 *  - centralise le moment d'enregistrement (avant `rest_api_init` les routes
 *    ne peuvent pas être déclarées) ;
 *  - autorise l'idempotence du `register()` (Plugin::boot peut être appelé
 *    plus d'une fois en théorie — un `add_action` doublon créerait des
 *    routes dupliquées) ;
 *  - facilite l'extension : ajouter un nouveau contrôleur en Phase 5.2-5.4
 *    se fait en l'injectant dans la liste passée au constructeur côté
 *    Plugin.php, sans toucher au provider.
 *
 * Convention DI : la liste des contrôleurs est figée au constructeur. Le
 * provider ne fait pas d'auto-discovery — Plugin.php (composition root)
 * sait quels contrôleurs sont disponibles à un instant T.
 */
final class RestServiceProvider {

	/**
	 * Indique si `register()` a déjà branché le hook (idempotence).
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * @param list<BaseController> $controllers Contrôleurs à enregistrer.
	 */
	public function __construct(
		private readonly array $controllers,
	) {}

	/**
	 * Branche le hook `rest_api_init`. Appelée une fois par `Plugin::boot()`.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'rest_api_init', array( $this, 'register_all_routes' ) );
	}

	/**
	 * Callback du hook `rest_api_init` — appelle `register_routes()` sur
	 * chaque contrôleur dans l'ordre fourni.
	 *
	 * Public car invoquée par WordPress via `do_action`. Pas usage direct
	 * en tests : préférer instancier le provider et appeler cette méthode
	 * pour déclencher l'enregistrement.
	 *
	 * @return void
	 */
	public function register_all_routes(): void {
		foreach ( $this->controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Liste des contrôleurs (lecture seule). Utile en tests pour assertion.
	 *
	 * @return list<BaseController>
	 */
	public function controllers(): array {
		return $this->controllers;
	}
}
