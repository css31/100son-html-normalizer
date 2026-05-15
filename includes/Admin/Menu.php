<?php
/**
 * Menu admin — enregistre le top-level et les sous-pages.
 *
 * Historique :
 *  - V0.1 : UI classique en pages PHP (Règles / Tester / Normaliser /
 *    Journal). Top-level pointait sur Règles.
 *  - V1.0 (rc4) : SPA React ajoutée comme sous-page « Normaliser V1 »,
 *    cohabitant avec les 4 pages V0.1.
 *  - Post-rc4 : la SPA devient le **point d'entrée unique** — le top-level
 *    « HTML Normalizer » pointe désormais sur SpaPage et toutes les
 *    sous-pages V0.1 sont retirées du menu via `remove_submenu_page()`.
 *    Les classes V0.1 restent instanciées et leurs URLs `?page=…-<sub>`
 *    restent accessibles directement (pour non-régression / rollback),
 *    mais aucune entrée n'apparaît plus dans le sidebar admin.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Admin\Pages\LogsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\PostsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\PresetsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\SpaPage;
use Cent_Son\Html_Normalizer\Admin\Pages\TesterPage;

/**
 * Enregistrement du menu admin.
 */
final class Menu {

	public const CAPABILITY     = 'manage_options';
	public const SLUG           = '100son-html-normalizer';

	/**
	 * Slug de la sous-page SPA V1.0 (Phase 6).
	 *
	 * Référencé aussi par `Admin\Assets` pour calculer le hook_suffix
	 * attendu et restreindre l'enqueue du bundle React à cette page
	 * uniquement (cf. cahier §13).
	 */
	public const SPA_PAGE_SLUG = '100son-html-normalizer-spa';

	private PresetsPage $presets_page;
	private TesterPage $tester_page;
	private PostsPage $posts_page;
	private LogsPage $logs_page;
	private SpaPage $spa_page;

	public function __construct(
		PresetsPage $presets_page,
		TesterPage $tester_page,
		PostsPage $posts_page,
		LogsPage $logs_page,
		SpaPage $spa_page
	) {
		$this->presets_page = $presets_page;
		$this->tester_page  = $tester_page;
		$this->posts_page   = $posts_page;
		$this->logs_page    = $logs_page;
		$this->spa_page     = $spa_page;
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

		// Form-handlers `admin_post_*` des sous-pages — branchés ici car
		// admin-post.php n'invoque jamais le menu, seulement les hooks.
		$this->posts_page->register_admin_hooks();
	}

	/**
	 * Callback `admin_menu`.
	 *
	 * @return void
	 */
	public function on_admin_menu(): void {
		// Top-level — sert désormais la SPA V1.0. L'icône et le menu_title
		// restent identiques (« HTML Normalizer ») pour ne pas dérouter
		// l'utilisateur ; seul le callback bascule de PresetsPage à SpaPage.
		add_menu_page(
			__( 'HTML Normalizer', '100son-html-normalizer' ),
			__( 'HTML Normalizer', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this->spa_page, 'render' ),
			'dashicons-editor-removeformatting',
			80
		);

		// === Pages V0.1 — enregistrées pour conserver leurs URLs directes ===
		//
		// L'enregistrement via `add_submenu_page()` est nécessaire pour que
		// WordPress route `?page=<slug>` vers le callback de rendu. Une fois
		// enregistrées, on les retire du menu visible via `remove_submenu_page()`
		// plus bas — l'URL reste fonctionnelle (rollback, non-régression, lien
		// existant dans un favori) mais aucune entrée n'apparaît dans le
		// sidebar admin.
		//
		// Règles prend un slug dédié `…-presets` (au lieu de l'ancien
		// alias sur le top-level) — sinon il entrerait en conflit avec le
		// nouveau top-level qui pointe sur la SPA.
		add_submenu_page(
			self::SLUG,
			__( 'Règles (V0.1)', '100son-html-normalizer' ),
			__( 'Règles (V0.1)', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-presets',
			array( $this->presets_page, 'render' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Tester un fragment (V0.1)', '100son-html-normalizer' ),
			__( 'Tester un fragment (V0.1)', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-tester',
			array( $this->tester_page, 'render' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Normaliser (V0.1)', '100son-html-normalizer' ),
			__( 'Normaliser (V0.1)', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-posts',
			array( $this->posts_page, 'render' )
		);
		add_submenu_page(
			self::SLUG,
			__( 'Journal (V0.1)', '100son-html-normalizer' ),
			__( 'Journal (V0.1)', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-logs',
			array( $this->logs_page, 'render' )
		);

		// SPA — alias submenu sur l'ancien slug `…-spa`, conservé pour que
		// les favoris/bookmarks utilisateur sur `?page=100son-html-normalizer-spa`
		// continuent de marcher. Retiré du menu juste après.
		add_submenu_page(
			self::SLUG,
			__( 'Normaliser', '100son-html-normalizer' ),
			__( 'Normaliser', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SPA_PAGE_SLUG,
			array( $this->spa_page, 'render' )
		);

		// Toutes les sous-pages enregistrées ci-dessus + l'alias auto-créé
		// par WP (qui réutilise le slug du top-level) sortent du menu visible.
		// Le sidebar n'affiche plus que l'entrée top-level « HTML Normalizer ».
		remove_submenu_page( self::SLUG, self::SLUG );                // alias auto-créé du top-level
		remove_submenu_page( self::SLUG, self::SLUG . '-presets' );   // V0.1 Règles
		remove_submenu_page( self::SLUG, self::SLUG . '-tester' );    // V0.1 Tester
		remove_submenu_page( self::SLUG, self::SLUG . '-posts' );     // V0.1 Normaliser
		remove_submenu_page( self::SLUG, self::SLUG . '-logs' );      // V0.1 Journal
		remove_submenu_page( self::SLUG, self::SPA_PAGE_SLUG );       // alias rétro-compat SPA
	}
}
