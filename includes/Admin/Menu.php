<?php
/**
 * Menu admin — enregistre le top-level qui sert la SPA V1.0.
 *
 * Historique :
 *  - V0.1 : UI classique en 4 pages PHP (Règles / Tester / Normaliser /
 *    Journal). Top-level pointait sur Règles.
 *  - V1.0 (rc4) : SPA React ajoutée comme sous-page « Normaliser V1 »,
 *    cohabitant avec les 4 pages V0.1.
 *  - Post-rc4 (2026-05-13) : la SPA devient le point d'entrée unique —
 *    le top-level « HTML Normalizer » bascule sur SpaPage et les
 *    sous-pages V0.1 sortent du menu visible (mais leurs classes restent
 *    instanciées pour rollback / URLs directes).
 *  - V1.0 (2026-05-16) : les 4 classes V0.1 sont supprimées définitivement.
 *    Plus qu'une seule entrée de menu (top-level → SpaPage) + un alias
 *    submenu rétro-compat sur l'ancien slug `…-spa` (pour ne pas casser
 *    les favoris navigateur), retiré du menu visible juste après.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Admin\Pages\SpaPage;

/**
 * Enregistrement du menu admin.
 */
final class Menu {

	public const CAPABILITY     = 'manage_options';
	public const SLUG           = '100son-html-normalizer';

	/**
	 * Slug de la sous-page SPA (V1.0 — Phase 6).
	 *
	 * Référencé aussi par `Admin\Assets` pour calculer le hook_suffix
	 * attendu et restreindre l'enqueue du bundle React à cette page
	 * uniquement (cf. cahier §13). Conservé même après la suppression
	 * V0.1 pour la rétro-compatibilité des favoris utilisateur.
	 */
	public const SPA_PAGE_SLUG = '100son-html-normalizer-spa';

	private SpaPage $spa_page;

	public function __construct( SpaPage $spa_page ) {
		$this->spa_page = $spa_page;
	}

	/**
	 * Branche les hooks WP.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'admin_menu', array( $this, 'on_admin_menu' ) );
	}

	/**
	 * Callback `admin_menu`.
	 *
	 * @return void
	 */
	public function on_admin_menu(): void {
		// Top-level — sert la SPA V1.0. Icône et menu_title conservés à
		// l'identique de V0.1 pour ne pas dérouter l'utilisateur.
		add_menu_page(
			__( 'HTML Normalizer', '100son-html-normalizer' ),
			__( 'HTML Normalizer', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this->spa_page, 'render' ),
			'dashicons-editor-removeformatting',
			80
		);

		// Alias submenu sur l'ancien slug `…-spa` — conservé pour que les
		// favoris/bookmarks utilisateur sur `?page=100son-html-normalizer-spa`
		// continuent de marcher. Retiré du menu visible juste après.
		add_submenu_page(
			self::SLUG,
			__( 'Normaliser', '100son-html-normalizer' ),
			__( 'Normaliser', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SPA_PAGE_SLUG,
			array( $this->spa_page, 'render' )
		);

		// Le sidebar n'affiche plus que l'entrée top-level « HTML Normalizer ».
		// L'alias auto-créé par WP (qui réutilise le slug du top-level) et
		// l'alias rétro-compat SPA sortent du menu visible.
		remove_submenu_page( self::SLUG, self::SLUG );          // alias auto-créé du top-level
		remove_submenu_page( self::SLUG, self::SPA_PAGE_SLUG ); // alias rétro-compat SPA
	}
}
