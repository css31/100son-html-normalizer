<?php
/**
 * Page admin "Normaliser des articles" (F8).
 *
 * UI PHP classique V0.1 — workflow article par article :
 *  - Liste paginée des articles, filtrable par post_type
 *  - Badge "SO" pour les articles SiteOrigin (panels_data)
 *  - Boutons Aperçu / Normaliser par ligne
 *  - Vue Aperçu : avant/après + bouton Confirmer (avec case `force_siteorigin`)
 *  - Création de révision WP avant chaque écriture
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin\Pages;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Posts\PostNormalizer;
use Cent_Son\Html_Normalizer\Core\Posts\SiteOriginDetector;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;

/**
 * Vue F8 — Normaliser des articles.
 */
final class PostsPage {

	private const NONCE_FILTERS    = 'son100_htmln_posts_filters';
	private const NONCE_NORMALIZE  = 'son100_htmln_posts_normalize';
	private const NONCE_NAME       = '_son100_htmln_nonce';
	private const PAGE_SLUG        = '100son-html-normalizer-posts';
	private const PER_PAGE         = 25;

	private SettingsRepository $settings;
	private SiteOriginDetector $so_detector;
	private PostNormalizer     $post_normalizer;

	public function __construct(
		SettingsRepository $settings,
		SiteOriginDetector $so_detector,
		PostNormalizer $post_normalizer
	) {
		$this->settings        = $settings;
		$this->so_detector     = $so_detector;
		$this->post_normalizer = $post_normalizer;
	}

	/**
	 * Render principal : route entre liste, aperçu, et POST normalize.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', '100son-html-normalizer' ) );
		}

		// Sauvegarde des filtres post_type (POST séparé du normalize).
		$this->maybe_handle_filters_save();

		// Action normalize (POST de confirmation depuis l'aperçu).
		$normalize_result = $this->maybe_handle_normalize();

		$action  = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'HTML Normalizer — Normaliser des articles', '100son-html-normalizer' ) . '</h1>';

		if ( null !== $normalize_result ) {
			$this->render_normalize_notice( $normalize_result );
		}

		if ( 'preview' === $action && $post_id > 0 ) {
			$this->render_preview( $post_id );
		} else {
			$this->render_filters_form();
			$this->render_posts_list();
		}

		echo '</div>';
	}

	// ===================================================================
	//  POST handlers
	// ===================================================================

	/**
	 * Traite la sauvegarde des filtres post_type si POST présent.
	 *
	 * @return void
	 */
	private function maybe_handle_filters_save(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( ! isset( $_POST['son100_htmln_action'] ) || 'save_filters' !== $_POST['son100_htmln_action'] ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_FILTERS, self::NONCE_NAME );

		$selected = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['post_types'] ) )
			: [];

		$this->settings->set_f8_post_types_selection( $selected );
	}

	/**
	 * Traite l'action normalize (POST depuis l'aperçu).
	 *
	 * @return array{post_id: int, result: array<string, mixed>}|null
	 */
	private function maybe_handle_normalize(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return null;
		}
		if ( ! isset( $_POST['son100_htmln_action'] ) || 'normalize' !== $_POST['son100_htmln_action'] ) {
			return null;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_NORMALIZE, self::NONCE_NAME );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$force   = ! empty( $_POST['force_siteorigin'] );

		if ( $post_id <= 0 ) {
			return null;
		}

		$result = $this->post_normalizer->normalize_post( $post_id, $force );
		return [ 'post_id' => $post_id, 'result' => $result ];
	}

	// ===================================================================
	//  Render filters + posts list
	// ===================================================================

	/**
	 * Render du formulaire de filtres post_type.
	 *
	 * @return void
	 */
	private function render_filters_form(): void {
		$selected      = $this->settings->get_f8_post_types_selection();
		$available     = $this->get_available_post_types();
		$page_url      = self::page_url();

		echo '<form method="post" action="' . esc_url( $page_url ) . '" style="margin:16px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;">';
		echo '<input type="hidden" name="son100_htmln_action" value="save_filters">';
		wp_nonce_field( self::NONCE_FILTERS, self::NONCE_NAME );

		echo '<strong>' . esc_html__( 'Types de contenu à afficher :', '100son-html-normalizer' ) . '</strong> ';
		foreach ( $available as $slug => $label ) {
			$checked = in_array( $slug, $selected, true );
			printf(
				'<label style="margin-right:12px;"><input type="checkbox" name="post_types[]" value="%s" %s> %s</label>',
				esc_attr( $slug ),
				checked( $checked, true, false ),
				esc_html( $label )
			);
		}
		submit_button( __( 'Filtrer', '100son-html-normalizer' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Render de la liste des articles.
	 *
	 * @return void
	 */
	private function render_posts_list(): void {
		$selected = $this->settings->get_f8_post_types_selection();
		if ( [] === $selected ) {
			echo '<p>' . esc_html__( "Aucun type de contenu sélectionné dans les filtres.", '100son-html-normalizer' ) . '</p>';
			return;
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$query = new \WP_Query(
			[
				'post_type'      => $selected,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			]
		);

		if ( 0 === $query->found_posts ) {
			echo '<p>' . esc_html__( 'Aucun article trouvé.', '100son-html-normalizer' ) . '</p>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html( sprintf(
				/* translators: %d: number of posts found */
				_n( '%d article trouvé.', '%d articles trouvés.', (int) $query->found_posts, '100son-html-normalizer' ),
				(int) $query->found_posts
			) )
		);

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th style="width:80px;">ID</th>';
		echo '<th>' . esc_html__( 'Titre', '100son-html-normalizer' ) . '</th>';
		echo '<th style="width:80px;">' . esc_html__( 'Type', '100son-html-normalizer' ) . '</th>';
		echo '<th style="width:80px;">SO</th>';
		echo '<th style="width:200px;">' . esc_html__( 'Actions', '100son-html-normalizer' ) . '</th>';
		echo '</tr></thead><tbody>';

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id   = (int) get_the_ID();
			$title     = get_the_title( $post_id );
			$post_type = get_post_type( $post_id );
			$has_so    = $this->so_detector->has_panels_data( $post_id );

			$preview_url = add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'action'  => 'preview',
					'post_id' => $post_id,
				],
				admin_url( 'admin.php' )
			);
			$edit_url    = (string) get_edit_post_link( $post_id );

			echo '<tr>';
			printf( '<td>%d</td>', (int) $post_id );
			printf(
				'<td><strong>%s</strong>%s</td>',
				esc_html( '' === trim( $title ) ? __( '(sans titre)', '100son-html-normalizer' ) : $title ),
				'' !== $edit_url
					? sprintf( ' <a href="%s" target="_blank" style="text-decoration:none;color:#646970;">↗</a>', esc_url( $edit_url ) )
					: ''
			);
			printf( '<td>%s</td>', esc_html( (string) $post_type ) );
			echo '<td>' . ( $has_so
				? '<span style="display:inline-block;padding:2px 8px;background:#d63638;color:#fff;border-radius:3px;font-size:11px;font-weight:600;">SO</span>'
				: '—'
			) . '</td>';
			printf(
				'<td><a href="%s" class="button button-small">%s</a></td>',
				esc_url( $preview_url ),
				esc_html__( 'Aperçu', '100son-html-normalizer' )
			);
			echo '</tr>';
		}
		wp_reset_postdata();

		echo '</tbody></table>';

		$this->render_pagination( $query, $paged );
	}

	/**
	 * Render de la pagination.
	 *
	 * @param \WP_Query $query Requête.
	 * @param int       $paged Page courante.
	 * @return void
	 */
	private function render_pagination( \WP_Query $query, int $paged ): void {
		$total_pages = (int) $query->max_num_pages;
		if ( $total_pages <= 1 ) {
			return;
		}
		$base = add_query_arg(
			[ 'page' => self::PAGE_SLUG ],
			admin_url( 'admin.php' )
		);
		$links = paginate_links(
			[
				'base'      => $base . '%_%',
				'format'    => '&paged=%#%',
				'current'   => $paged,
				'total'     => $total_pages,
				'prev_text' => '‹',
				'next_text' => '›',
			]
		);
		if ( ! empty( $links ) ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	// ===================================================================
	//  Render preview (avant / après + confirm)
	// ===================================================================

	/**
	 * Render de la vue Aperçu d'un article.
	 *
	 * @param int $post_id ID.
	 * @return void
	 */
	private function render_preview( int $post_id ): void {
		$preview  = $this->post_normalizer->preview( $post_id );
		$post     = get_post( $post_id );
		$title    = $post instanceof \WP_Post ? get_the_title( $post_id ) : '';
		$back_url = self::page_url();

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $back_url ),
			esc_html__( '← Retour à la liste', '100son-html-normalizer' )
		);

		if ( PostNormalizer::STATUS_ERROR_NOT_FOUND === $preview['status'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html( (string) ( $preview['message'] ?? '' ) ) . '</p></div>';
			return;
		}

		printf(
			'<h2>%s — <span style="color:#646970;">#%d</span></h2>',
			esc_html( '' === trim( $title ) ? __( '(sans titre)', '100son-html-normalizer' ) : $title ),
			(int) $post_id
		);

		if ( $preview['has_panels_data'] ) {
			echo '<div class="notice notice-warning" style="margin:16px 0;">';
			echo '<p><strong>' . esc_html__( '⚠ Article SiteOrigin détecté', '100son-html-normalizer' ) . '</strong></p>';
			echo '<p>' . esc_html__( "Cet article utilise SiteOrigin Page Builder. Normaliser le post_content brut ignorera la structure du builder. Préférer SO to Blocks pour la migration. Si vous savez ce que vous faites, cochez « Continuer quand même » avant de cliquer sur Normaliser.", '100son-html-normalizer' ) . '</p>';
			echo '</div>';
		}

		if ( PostNormalizer::STATUS_UNCHANGED === $preview['status'] ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( "Aucune modification : le HTML est déjà conforme aux présets actifs.", '100son-html-normalizer' ) . '</p></div>';
		}

		echo '<div style="display:flex;gap:16px;margin-top:16px;">';

		echo '<div style="flex:1;min-width:0;">';
		echo '<h3>' . esc_html__( 'Avant', '100son-html-normalizer' ) . '</h3>';
		printf(
			'<pre style="background:#f6f7f7;padding:12px;border:1px solid #c3c4c7;overflow:auto;max-height:500px;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;">%s</pre>',
			esc_html( (string) $preview['html_before'] )
		);
		echo '</div>';

		echo '<div style="flex:1;min-width:0;">';
		echo '<h3>' . esc_html__( 'Après', '100son-html-normalizer' ) . '</h3>';
		printf(
			'<pre style="background:#f0f6fc;padding:12px;border:1px solid #72aee6;overflow:auto;max-height:500px;font-family:monospace;font-size:12px;white-space:pre-wrap;word-break:break-word;">%s</pre>',
			esc_html( (string) $preview['html_after'] )
		);
		echo '</div>';

		echo '</div>';

		// Form de confirmation (sauf si unchanged).
		if ( PostNormalizer::STATUS_MODIFIED === $preview['status'] ) {
			$action_url = add_query_arg(
				[
					'page'    => self::PAGE_SLUG,
					'action'  => 'preview',
					'post_id' => $post_id,
				],
				admin_url( 'admin.php' )
			);
			echo '<form method="post" action="' . esc_url( $action_url ) . '" style="margin-top:24px;padding:16px;background:#fff;border:1px solid #c3c4c7;">';
			echo '<input type="hidden" name="son100_htmln_action" value="normalize">';
			printf( '<input type="hidden" name="post_id" value="%d">', (int) $post_id );
			wp_nonce_field( self::NONCE_NORMALIZE, self::NONCE_NAME );

			if ( $preview['has_panels_data'] ) {
				echo '<p><label><input type="checkbox" name="force_siteorigin" value="1" required> ';
				echo esc_html__( "Continuer quand même (article SiteOrigin)", '100son-html-normalizer' );
				echo '</label></p>';
			}

			echo '<p>' . esc_html__( "Une révision WP sera créée juste avant l'écriture. Vous pourrez restaurer la version précédente depuis l'écran d'édition de l'article.", '100son-html-normalizer' ) . '</p>';
			submit_button( __( 'Normaliser et enregistrer', '100son-html-normalizer' ), 'primary' );
			echo '</form>';
		}
	}

	/**
	 * Affiche une notice après normalisation.
	 *
	 * @param array{post_id: int, result: array<string, mixed>} $info Info.
	 * @return void
	 */
	private function render_normalize_notice( array $info ): void {
		$result = $info['result'];
		$status = (string) ( $result['status'] ?? '' );

		switch ( $status ) {
			case PostNormalizer::STATUS_MODIFIED:
				$msg = sprintf(
					/* translators: %d: post id */
					__( "Article #%d normalisé avec succès. Une révision a été créée pour rollback.", '100son-html-normalizer' ),
					(int) $info['post_id']
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
				break;
			case PostNormalizer::STATUS_UNCHANGED:
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( "Aucune modification appliquée.", '100son-html-normalizer' ) . '</p></div>';
				break;
			case PostNormalizer::STATUS_SKIPPED_SO:
				echo '<div class="notice notice-warning"><p>' . esc_html( (string) ( $result['message'] ?? '' ) ) . '</p></div>';
				break;
			default:
				$msg = (string) ( $result['message'] ?? __( 'Erreur inconnue.', '100son-html-normalizer' ) );
				echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
				break;
		}
	}

	// ===================================================================
	//  Helpers
	// ===================================================================

	/**
	 * Liste les post_types eligibles : post, page, et CPT publics custom.
	 *
	 * @return array<string, string> slug => label
	 */
	private function get_available_post_types(): array {
		$out = [];
		$pt  = get_post_types( [ 'public' => true ], 'objects' );
		foreach ( $pt as $obj ) {
			if ( ! is_object( $obj ) ) {
				continue;
			}
			$slug = (string) ( $obj->name ?? '' );
			if ( '' === $slug || 'attachment' === $slug ) {
				continue;
			}
			$label      = (string) ( $obj->label ?? $slug );
			$out[ $slug ] = $label;
		}
		return $out;
	}

	/**
	 * URL canonique de la page (sans query args additionnels).
	 *
	 * @return string
	 */
	private static function page_url(): string {
		return add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'admin.php' ) );
	}
}
