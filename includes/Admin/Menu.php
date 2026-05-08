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
	private TesterPage  $tester_page;
	private PostsPage   $posts_page;

	public function __construct( PresetsPage $presets_page, TesterPage $tester_page, PostsPage $posts_page ) {
		$this->presets_page = $presets_page;
		$this->tester_page  = $tester_page;
		$this->posts_page   = $posts_page;
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
		add_action( 'admin_menu', [ $this, 'on_admin_menu' ] );
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
			[ $this->presets_page, 'render' ],
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
			[ $this->presets_page, 'render' ]
		);

		// Sous-page "Tester un fragment".
		add_submenu_page(
			self::SLUG,
			__( 'Tester un fragment', '100son-html-normalizer' ),
			__( 'Tester un fragment', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-tester',
			[ $this->tester_page, 'render' ]
		);

		// Sous-page "Normaliser des articles" (F8).
		add_submenu_page(
			self::SLUG,
			__( 'Normaliser des articles', '100son-html-normalizer' ),
			__( 'Normaliser des articles', '100son-html-normalizer' ),
			self::CAPABILITY,
			self::SLUG . '-posts',
			[ $this->posts_page, 'render' ]
		);
	}
}
