<?php
/**
 * Menu admin — enregistre le top-level et les sous-pages.
 *
 * V0.1 : UI minimale en PHP classique (pas de SPA React, ce sera la phase 15
 * du §11). 2 sous-pages : Préréglages (cocher/décocher + paramètres) et Tester
 * (normalisation interactive d'un fragment).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Admin\Pages\LogsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\PostsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\PresetsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\TesterPage;

/**
 * Enregistrement du menu admin.
 */
final class Menu {

	public const CAPABILITY = 'manage_options';
	public const SLUG       = '100son-html-normalizer';

	private PresetsPage $presets_page;
	private TesterPage $tester_page;
	private PostsPage $posts_page;
	private LogsPage $logs_page;

	public function __construct( PresetsPage $presets_page, TesterPage $tester_page, PostsPage $posts_page, LogsPage $logs_page ) {
		$this->presets_page = $presets_page;
		$this->tester_page  = $tester_page;
		$this->posts_page   = $posts_page;
		$this->logs_page    = $logs_page;
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
		add_menu_page(
			__( 'HTML Normalizer', '100son-html-normalizer' ),
			__( 'HTML Normalizer', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this->presets_page, 'render' ),
			'dashicons-editor-removeformatting',
			80
		);

		// Sous-page "Préréglages" (alias de la top-level — point d'entrée naturel V0.1).
		add_submenu_page(
			self::SLUG,
			__( 'Préréglages', '100son-html-normalizer' ),
			__( 'Préréglages', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this->presets_page, 'render' )
		);

		// Sous-page "Tester un fragment".
		add_submenu_page(
			self::SLUG,
			__( 'Tester un fragment', '100son-html-normalizer' ),
			__( 'Tester un fragment', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-tester',
			array( $this->tester_page, 'render' )
		);

		// Sous-page "Normaliser" (F8).
		add_submenu_page(
			self::SLUG,
			__( 'Normaliser', '100son-html-normalizer' ),
			__( 'Normaliser', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-posts',
			array( $this->posts_page, 'render' )
		);

		// Sous-page "Journal".
		add_submenu_page(
			self::SLUG,
			__( 'Journal', '100son-html-normalizer' ),
			__( 'Journal', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-logs',
			array( $this->logs_page, 'render' )
		);
	}
}
