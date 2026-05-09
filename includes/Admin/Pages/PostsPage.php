<?php
/**
 * Page admin "Normaliser" (F8).
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

use Cent_Son\Html_Normalizer\Core\Metrics\HtmlMetrics;
use Cent_Son\Html_Normalizer\Core\Posts\PostNormalizer;
use Cent_Son\Html_Normalizer\Core\Posts\SiteOriginDetector;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;

/**
 * Vue F8 — Normaliser.
 */
final class PostsPage {

	private const NONCE_FILTERS    = 'son100_htmln_posts_filters';
	private const NONCE_NORMALIZE  = 'son100_htmln_posts_normalize';
	private const NONCE_BULK       = 'son100_htmln_posts_bulk';
	private const NONCE_OVERRIDE   = 'son100_htmln_posts_override';
	private const NONCE_NAME       = '_son100_htmln_nonce';
	private const PAGE_SLUG        = '100son-html-normalizer-posts';
	private const PER_PAGE_CHOICES = array( 10, 25, 50, 100, 200 );

	/*
	 Hook `admin_post_*` — handler du toggle Out / Auto via admin-post.php.
	 * Indispensable : la callback de menu admin est appelée APRÈS l'envoi des
	 * headers HTTP, donc tout `wp_safe_redirect` y échoue silencieusement.
	 * admin-post.php tourne avant et permet une redirection PRG propre. */
	private const ACTION_SET_OVERRIDE = 'son100_htmln_set_override';

	/* Post-meta de tag manuel (override de la classification auto). */
	private const META_OVERRIDE = '_son100_htmln_builder_override';

	/* Types de constructeur détectés (colonne + filtre « Constructeur »). */
	private const BUILDER_SO      = 'siteorigin';      // panels_data en meta OU bloc <!-- wp:siteorigin-panels
	private const BUILDER_SO_FLAT = 'siteorigin_flat'; // rendu HTML SO aplati dans post_content (classes panel-layout / so-panel sans meta ni bloc)
	private const BUILDER_GUT     = 'gutenberg';
	private const BUILDER_OTHER   = 'other';
	private const BUILDER_OUT     = 'out';             // override manuel : article hors périmètre (à ignorer)

	/**
	 * Colonnes triables : clé GET (lowercase, compatible sanitize_key) => clé orderby WP_Query.
	 *
	 * Note : `sanitize_key()` convertit en lowercase, donc la clé GET DOIT être
	 * en minuscules. La valeur (côté WP_Query) reste avec sa casse d'origine
	 * (`'ID'` en majuscules est la valeur attendue par WP_Query pour trier par ID).
	 */
	private const SORTABLE_COLUMNS = array(
		'id'    => 'ID',
		'title' => 'title',
		'date'  => 'date',
	);

	private SettingsRepository $settings;
	private SiteOriginDetector $so_detector;
	private PostNormalizer $post_normalizer;

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

		// Sauvegarde des préférences (POST persisté : post_type, per_page).
		$this->maybe_handle_filters_save();

		// NB : le tag manuel Out / Auto (toggle colonne Constr.) est traité par
		// `handle_set_override()` côté admin-post.php — il ne passe jamais ici.

		// Action normalize unitaire (POST depuis l'aperçu).
		$normalize_result = $this->maybe_handle_normalize();

		// Action groupée (POST depuis la liste : "Normaliser la sélection").
		$bulk_result = $this->maybe_handle_bulk_normalize();

		$action  = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'HTML Normalizer — Normaliser', '100son-html-normalizer' ) . '</h1>';

		if ( null !== $normalize_result ) {
			$this->render_normalize_notice( $normalize_result );
		}
		if ( null !== $bulk_result ) {
			$this->render_bulk_notice( $bulk_result );
		}

		if ( 'preview' === $action && $post_id > 0 ) {
			$this->render_preview( $post_id );
		} else {
			$this->render_filters_form();
			$this->render_search_form();
			$this->render_posts_list();
		}

		echo '</div>';
	}

	// ===================================================================
	// POST handlers
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
			: array();

		$this->settings->set_f8_post_types_selection( $selected );

		// Sauvegarde du nombre d'articles par page si présent.
		if ( isset( $_POST['per_page'] ) ) {
			$this->settings->set_f8_per_page( (int) $_POST['per_page'] );
		}
	}

	/**
	 * Branche les hooks admin-post.php nécessaires aux form-handlers de la page.
	 *
	 * À appeler une seule fois pendant `admin_init` (cf. `Menu::register()`).
	 * Indispensable pour le toggle Out/Auto qui exige une redirection PRG :
	 * les callbacks de menu sont appelées APRÈS l'envoi des headers HTTP, donc
	 * `wp_safe_redirect` y est inopérant. admin-post.php s'exécute en amont.
	 *
	 * @return void
	 */
	public function register_admin_hooks(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'admin_post_' . self::ACTION_SET_OVERRIDE, array( $this, 'handle_set_override' ) );
	}

	/**
	 * Handler `admin_post_son100_htmln_set_override` — traite le tag manuel
	 * Out / Auto soumis via le toggle inline de la colonne Constr.
	 *
	 * Si l'override est `out` → ajoute la post-meta. Si vide → la supprime
	 * (retour à la classification auto). Termine par une redirection PRG
	 * vers l'URL initiale (avec les filtres GET intacts) pour éviter les
	 * re-soumissions au refresh.
	 *
	 * @return void
	 */
	public function handle_set_override(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', '100son-html-normalizer' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE_OVERRIDE, self::NONCE_NAME );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce déjà vérifié ci-dessus.
		$post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$override = isset( $_POST['override'] ) ? sanitize_key( (string) wp_unslash( $_POST['override'] ) ) : '';

		if ( $post_id > 0 && get_post( $post_id ) instanceof \WP_Post ) {
			if ( self::BUILDER_OUT === $override ) {
				update_post_meta( $post_id, self::META_OVERRIDE, self::BUILDER_OUT );
			} else {
				delete_post_meta( $post_id, self::META_OVERRIDE );
			}
		}

		// PRG : redirection vers l'URI d'origine (préserve filtres + tri + pagination).
		// On n'autorise qu'une URI relative à /wp-admin/ (anti open-redirect),
		// sinon fallback sur la liste vierge.
		$redirect_to = self::page_url();
		if ( isset( $_POST['redirect_to'] ) ) {
			$candidate = (string) wp_unslash( $_POST['redirect_to'] );
			if ( str_starts_with( $candidate, '/wp-admin/' ) ) {
				$redirect_to = site_url( $candidate );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect( $redirect_to );
		exit;
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
		return array(
			'post_id' => $post_id,
			'result' => $result,
		);
	}

	/**
	 * Traite l'action groupée "Normaliser la sélection" (POST depuis la liste).
	 *
	 * @return array{counts: array<string, int>, errors: list<string>}|null
	 */
	private function maybe_handle_bulk_normalize(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return null;
		}
		if ( ! isset( $_POST['son100_htmln_action'] ) || 'bulk_normalize' !== $_POST['son100_htmln_action'] ) {
			return null;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_BULK, self::NONCE_NAME );

		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( (string) $_POST['bulk_action'] ) : '';
		if ( ! in_array( $bulk_action, array( 'normalize', 'normalize_force_so' ), true ) ) {
			return null;
		}

		$ids_raw = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] )
			? wp_unslash( $_POST['post_ids'] )
			: array();

		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids_raw ),
				static fn( int $id ): bool => $id > 0
			)
		);

		if ( array() === $ids ) {
			return array(
				'counts' => array(
					PostNormalizer::STATUS_MODIFIED         => 0,
					PostNormalizer::STATUS_UNCHANGED        => 0,
					PostNormalizer::STATUS_SKIPPED_SO       => 0,
					PostNormalizer::STATUS_ERROR_NOT_FOUND  => 0,
					PostNormalizer::STATUS_ERROR_WRITE      => 0,
				),
				'errors' => array( __( 'Aucun article sélectionné.', '100son-html-normalizer' ) ),
			);
		}

		$force_so = ( 'normalize_force_so' === $bulk_action );
		$counts   = array(
			PostNormalizer::STATUS_MODIFIED         => 0,
			PostNormalizer::STATUS_UNCHANGED        => 0,
			PostNormalizer::STATUS_SKIPPED_SO       => 0,
			PostNormalizer::STATUS_ERROR_NOT_FOUND  => 0,
			PostNormalizer::STATUS_ERROR_WRITE      => 0,
		);
		$errors = array();

		foreach ( $ids as $id ) {
			// Sécurité : on saute systématiquement les articles taggés Out,
			// même si le client a réussi à pousser leur ID dans le POST
			// (la checkbox `disabled` côté UI les exclut déjà, mais le serveur
			// reste seul juge — pas de confiance sur l'input client).
			if ( self::BUILDER_OUT === (string) get_post_meta( $id, self::META_OVERRIDE, true ) ) {
				continue;
			}
			$result = $this->post_normalizer->normalize_post( $id, $force_so );
			$status = (string) ( $result['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
			if ( in_array( $status, array( PostNormalizer::STATUS_ERROR_NOT_FOUND, PostNormalizer::STATUS_ERROR_WRITE ), true ) ) {
				$errors[] = sprintf( '#%d : %s', $id, (string) ( $result['message'] ?? '' ) );
			}
		}

		return array(
			'counts' => $counts,
			'errors' => $errors,
		);
	}

	// ===================================================================
	// Render filters + posts list
	// ===================================================================

	/**
	 * Render du formulaire de filtres post_type.
	 *
	 * @return void
	 */
	private function render_filters_form(): void {
		$selected     = $this->settings->get_f8_post_types_selection();
		$available    = $this->get_available_post_types();
		$current_pp   = $this->settings->get_f8_per_page();
		$page_url     = self::page_url();

		echo '<form method="post" action="' . esc_url( $page_url ) . '" style="margin:16px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;">';
		echo '<input type="hidden" name="son100_htmln_action" value="save_filters">';
		wp_nonce_field( self::NONCE_FILTERS, self::NONCE_NAME );

		echo '<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:center;">';

		// Filtres post_type.
		echo '<div>';
		echo '<strong>' . esc_html__( 'Types de contenu :', '100son-html-normalizer' ) . '</strong> ';
		foreach ( $available as $slug => $label ) {
			$checked = in_array( $slug, $selected, true );
			printf(
				'<label style="margin-right:12px;"><input type="checkbox" name="post_types[]" value="%s" %s> %s</label>',
				esc_attr( $slug ),
				checked( $checked, true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';

		// Sélecteur per_page.
		echo '<div>';
		echo '<label><strong>' . esc_html__( 'Articles / page :', '100son-html-normalizer' ) . '</strong> ';
		echo '<select name="per_page">';
		foreach ( self::PER_PAGE_CHOICES as $choice ) {
			printf(
				'<option value="%1$d" %2$s>%1$d</option>',
				$choice,
				selected( $choice === $current_pp, true, false )
			);
		}
		echo '</select></label>';
		echo '</div>';

		// Bouton.
		echo '<div>';
		submit_button( __( 'Appliquer', '100son-html-normalizer' ), 'secondary', '', false );
		echo '</div>';

		echo '</div>';
		echo '</form>';
	}

	/**
	 * Render de la barre de recherche / filtres (GET, navigationnel).
	 *
	 * @return void
	 */
	private function render_search_form(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- form GET en lecture, sans nonce.
		$search    = isset( $_GET['s'] ) ? (string) wp_unslash( $_GET['s'] ) : '';
		$cat       = isset( $_GET['cat'] ) ? (int) $_GET['cat'] : 0;
		$year      = isset( $_GET['year'] ) ? (int) $_GET['year'] : 0;
		$month     = isset( $_GET['month'] ) ? (int) $_GET['month'] : 0;
		$builder_filter = isset( $_GET['builder'] ) ? sanitize_key( (string) $_GET['builder'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$months = array(
			1  => __( 'Janvier', '100son-html-normalizer' ),
			2  => __( 'Février', '100son-html-normalizer' ),
			3  => __( 'Mars', '100son-html-normalizer' ),
			4  => __( 'Avril', '100son-html-normalizer' ),
			5  => __( 'Mai', '100son-html-normalizer' ),
			6  => __( 'Juin', '100son-html-normalizer' ),
			7  => __( 'Juillet', '100son-html-normalizer' ),
			8  => __( 'Août', '100son-html-normalizer' ),
			9  => __( 'Septembre', '100son-html-normalizer' ),
			10 => __( 'Octobre', '100son-html-normalizer' ),
			11 => __( 'Novembre', '100son-html-normalizer' ),
			12 => __( 'Décembre', '100son-html-normalizer' ),
		);

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:8px 0 16px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::PAGE_SLUG ) );

		echo '<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">';

		// Recherche par titre OU par ID (un nombre seul → recherche ID exacte).
		echo '<div>';
		echo '<label><strong>' . esc_html__( 'Recherche (titre ou ID) :', '100son-html-normalizer' ) . '</strong><br>';
		printf(
			'<input type="search" name="s" value="%s" placeholder="%s" style="width:240px;"></label>',
			esc_attr( $search ),
			esc_attr__( 'Mots du titre, ou ID exact (ex. 11448)', '100son-html-normalizer' )
		);
		echo '</div>';

		// Filtre catégorie (taxonomie 'category').
		echo '<div>';
		echo '<label><strong>' . esc_html__( 'Catégorie :', '100son-html-normalizer' ) . '</strong><br>';
		echo '<select name="cat" style="min-width:180px;">';
		echo '<option value="0">' . esc_html__( 'Toutes', '100son-html-normalizer' ) . '</option>';
		$categories = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'number'     => 200,
			)
		);
		if ( is_array( $categories ) ) {
			foreach ( $categories as $term ) {
				if ( ! is_object( $term ) || ! isset( $term->term_id, $term->name ) ) {
					continue;
				}
				printf(
					'<option value="%1$d" %2$s>%3$s (%4$d)</option>',
					(int) $term->term_id,
					selected( $cat === (int) $term->term_id, true, false ),
					esc_html( (string) $term->name ),
					(int) ( $term->count ?? 0 )
				);
			}
		}
		echo '</select></label>';
		echo '</div>';

		// Filtre année.
		echo '<div>';
		echo '<label><strong>' . esc_html__( 'Année :', '100son-html-normalizer' ) . '</strong><br>';
		echo '<select name="year">';
		echo '<option value="0">' . esc_html__( 'Toutes', '100son-html-normalizer' ) . '</option>';
		foreach ( $this->get_available_years() as $y ) {
			printf(
				'<option value="%1$d" %2$s>%1$d</option>',
				(int) $y,
				selected( $year === (int) $y, true, false )
			);
		}
		echo '</select></label>';
		echo '</div>';

		// Filtre mois.
		echo '<div>';
		echo '<label><strong>' . esc_html__( 'Mois :', '100son-html-normalizer' ) . '</strong><br>';
		echo '<select name="month">';
		echo '<option value="0">' . esc_html__( 'Tous', '100son-html-normalizer' ) . '</option>';
		foreach ( $months as $num => $label ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $num,
				selected( $month === (int) $num, true, false ),
				esc_html( $label )
			);
		}
		echo '</select></label>';
		echo '</div>';

		// Filtre Constructeur (SO / Gutenberg / Autres).
		echo '<div>';
		echo '<label><strong>' . esc_html__( 'Constructeur :', '100son-html-normalizer' ) . '</strong><br>';
		echo '<select name="builder">';
		printf(
			'<option value="" %s>%s</option>',
			selected( '' === $builder_filter, true, false ),
			esc_html__( 'Tous', '100son-html-normalizer' )
		);
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( self::BUILDER_SO ),
			selected( self::BUILDER_SO === $builder_filter, true, false ),
			esc_html__( 'SiteOrigin', '100son-html-normalizer' )
		);
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( self::BUILDER_GUT ),
			selected( self::BUILDER_GUT === $builder_filter, true, false ),
			esc_html__( 'Gutenberg', '100son-html-normalizer' )
		);
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( self::BUILDER_OTHER ),
			selected( self::BUILDER_OTHER === $builder_filter, true, false ),
			esc_html__( 'Autres', '100son-html-normalizer' )
		);
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( self::BUILDER_OUT ),
			selected( self::BUILDER_OUT === $builder_filter, true, false ),
			esc_html__( 'Out (hors périmètre)', '100son-html-normalizer' )
		);
		echo '</select></label>';
		echo '</div>';

		// Boutons.
		echo '<div>';
		submit_button( __( 'Filtrer', '100son-html-normalizer' ), 'primary', '', false );
		printf(
			' <a href="%s" class="button">%s</a>',
			esc_url( self::page_url() ),
			esc_html__( 'Réinitialiser', '100son-html-normalizer' )
		);
		echo '</div>';

		echo '</div>';
		echo '</form>';
	}

	/**
	 * Liste des années pour lesquelles il existe au moins un article.
	 *
	 * @return list<int>
	 */
	private function get_available_years(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$rows = $wpdb->get_col(
			"SELECT DISTINCT YEAR(post_date) FROM {$wpdb->posts}
			 WHERE post_status IN ('publish','draft','private')
			 AND post_type IN ('post','page')
			 ORDER BY post_date DESC"
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$years = array_values( array_filter( array_map( 'intval', $rows ), static fn( int $y ): bool => $y > 0 ) );
		return $years;
	}

	/**
	 * Render de la liste des articles.
	 *
	 * @return void
	 */
	private function render_posts_list(): void {
		$selected = $this->settings->get_f8_post_types_selection();
		if ( array() === $selected ) {
			echo '<p>' . esc_html__( 'Aucun type de contenu sélectionné dans les filtres.', '100son-html-normalizer' ) . '</p>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- params GET de navigation.
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$search    = isset( $_GET['s'] ) ? trim( (string) wp_unslash( $_GET['s'] ) ) : '';
		$cat       = isset( $_GET['cat'] ) ? (int) $_GET['cat'] : 0;
		$year      = isset( $_GET['year'] ) ? (int) $_GET['year'] : 0;
		$month     = isset( $_GET['month'] ) ? (int) $_GET['month'] : 0;
		$builder_filter = isset( $_GET['builder'] ) ? sanitize_key( (string) $_GET['builder'] ) : '';
		$orderby   = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'date';
		$order     = isset( $_GET['order'] ) && 'asc' === strtolower( (string) $_GET['order'] ) ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Validation du tri.
		if ( ! array_key_exists( $orderby, self::SORTABLE_COLUMNS ) ) {
			$orderby = 'date';
		}
		$per_page = $this->settings->get_f8_per_page();

		$query_args = array(
			'post_type'      => $selected,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => self::SORTABLE_COLUMNS[ $orderby ],
			'order'          => $order,
			'no_found_rows'  => false,
		);
		// Si la recherche est purement numérique, on l'interprète comme un ID
		// exact (recherche par clé primaire). On utilise `post__in` plutôt que
		// `p` pour rester combinable avec les autres filtres (cat, date, builder…).
		$search_is_id = '' !== $search && ctype_digit( $search );
		if ( $search_is_id ) {
			$query_args['post__in'] = array( (int) $search );
		} elseif ( '' !== $search ) {
			$query_args['s'] = $search;
		}
		if ( $cat > 0 ) {
			$query_args['cat'] = $cat;
		}
		if ( $year > 0 ) {
			$date_q              = array( 'year' => $year );
			if ( $month >= 1 && $month <= 12 ) {
				$date_q['month'] = $month;
			}
			$query_args['date_query'] = array( $date_q );
		}

		// Filtre Constructeur :
		// - SO        : `panels_data` EXISTS *OU* contenu = bloc siteorigin-panels.
		// Comme WP_Query ne sait pas exprimer le OR entre meta et
		// content, on passe entièrement par `posts_where`.
		// - Gutenberg : pas de `panels_data`, présence de `<!-- wp:`, mais PAS
		// de `<!-- wp:siteorigin-panels` (qui est du SO déguisé).
		// - Autres    : ni `panels_data`, ni aucun marqueur de bloc `<!-- wp:`.
		switch ( $builder_filter ) {
			case self::BUILDER_SO:
				add_filter( 'posts_where', array( self::class, 'restrict_to_siteorigin' ) );
				break;
			case self::BUILDER_GUT:
				$query_args['meta_query'] = array(
					array(
						'key' => 'panels_data',
						'compare' => 'NOT EXISTS',
					),
				);
				add_filter( 'posts_where', array( self::class, 'restrict_to_gutenberg' ) );
				break;
			case self::BUILDER_OTHER:
				$query_args['meta_query'] = array(
					array(
						'key' => 'panels_data',
						'compare' => 'NOT EXISTS',
					),
				);
				add_filter( 'posts_where', array( self::class, 'restrict_to_other' ) );
				break;
			case self::BUILDER_OUT:
				// Filtre Out : tag manuel uniquement (post-meta override = 'out').
				$query_args['meta_query'] = array(
					array(
						'key'     => self::META_OVERRIDE,
						'value'   => self::BUILDER_OUT,
						'compare' => '=',
					),
				);
				break;
		}

		// Restriction de `s` au seul post_title (pas content / excerpt) —
		// uniquement quand la recherche est textuelle (sinon on est en mode ID).
		if ( '' !== $search && ! $search_is_id ) {
			add_filter( 'posts_search', array( self::class, 'restrict_search_to_title' ), 10, 2 );
		}

		$query = new \WP_Query( $query_args );

		if ( '' !== $search && ! $search_is_id ) {
			remove_filter( 'posts_search', array( self::class, 'restrict_search_to_title' ), 10 );
		}
		if ( self::BUILDER_SO === $builder_filter ) {
			remove_filter( 'posts_where', array( self::class, 'restrict_to_siteorigin' ) );
		} elseif ( self::BUILDER_GUT === $builder_filter ) {
			remove_filter( 'posts_where', array( self::class, 'restrict_to_gutenberg' ) );
		} elseif ( self::BUILDER_OTHER === $builder_filter ) {
			remove_filter( 'posts_where', array( self::class, 'restrict_to_other' ) );
		}

		if ( 0 === $query->found_posts ) {
			echo '<p>' . esc_html__( 'Aucun article trouvé.', '100son-html-normalizer' ) . '</p>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
				/* translators: %d: number of posts found */
					_n( '%d article trouvé.', '%d articles trouvés.', (int) $query->found_posts, '100son-html-normalizer' ),
					(int) $query->found_posts
				)
			)
		);

		// Form englobant tableau + actions groupées (POST bulk).
		$bulk_action_url = self::page_url();
		echo '<form method="post" action="' . esc_url( $bulk_action_url ) . '">';
		echo '<input type="hidden" name="son100_htmln_action" value="bulk_normalize">';
		wp_nonce_field( self::NONCE_BULK, self::NONCE_NAME );

		// Top tablenav : actions groupées + pagination.
		$this->render_bulk_actions_top( $query, $paged );

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<td class="manage-column column-cb check-column" style="width:30px;"><input type="checkbox" id="son100-htmln-select-all"></td>';
		echo self::sortable_th( 'id', 'ID', $orderby, $order, 'width:60px;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::sortable_th( 'title', __( 'Titre', '100son-html-normalizer' ), $orderby, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::sortable_th( 'date', __( 'Date', '100son-html-normalizer' ), $orderby, $order, 'width:120px;' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<th class="manage-column" style="width:80px;">' . esc_html__( 'Type', '100son-html-normalizer' ) . '</th>';
		echo '<th class="manage-column" style="width:200px;">' . esc_html__( 'Catégories', '100son-html-normalizer' ) . '</th>';
		echo '<th class="manage-column" style="width:70px;text-align:right;">' . esc_html__( 'Mots', '100son-html-normalizer' ) . '</th>';
		echo '<th class="manage-column" style="width:130px;" title="' . esc_attr__( 'Constructeur', '100son-html-normalizer' ) . '">' . esc_html__( 'Constr.', '100son-html-normalizer' ) . '</th>';
		echo '<th class="manage-column" style="width:100px;">' . esc_html__( 'Actions', '100son-html-normalizer' ) . '</th>';
		echo '</tr></thead><tbody>';

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id   = (int) get_the_ID();
			$title     = get_the_title( $post_id );
			$post_type = (string) get_post_type( $post_id );
			$has_so    = $this->so_detector->has_panels_data( $post_id );
			$date_str  = (string) get_the_date( 'Y-m-d', $post_id );
			$cats_html = self::format_terms( $post_id, $post_type );

			$preview_url = add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'action'  => 'preview',
					'post_id' => $post_id,
				),
				admin_url( 'admin.php' )
			);
			$edit_url    = (string) get_edit_post_link( $post_id );

			$builder_type = self::classify_builder( $post_id, $has_so );
			$is_out       = ( self::BUILDER_OUT === $builder_type );

			echo '<tr' . ( $is_out ? ' style="opacity:0.55;"' : '' ) . '>';

			// Checkbox : désactivée sur les Out (exclus des bulk actions).
			printf(
				'<td><input type="checkbox" class="son100-htmln-row-select" name="post_ids[]" value="%d"%s></td>',
				(int) $post_id,
				$is_out ? ' disabled title="' . esc_attr__( 'Article hors périmètre — exclu des actions groupées', '100son-html-normalizer' ) . '"' : ''
			);

			printf( '<td>%d</td>', (int) $post_id );
			printf(
				'<td><strong>%s</strong>%s</td>',
				esc_html( '' === trim( $title ) ? __( '(sans titre)', '100son-html-normalizer' ) : $title ),
				'' !== $edit_url
					? sprintf( ' <a href="%s" target="_blank" style="text-decoration:none;color:#646970;">↗</a>', esc_url( $edit_url ) )
					: ''
			);
			printf( '<td style="white-space:nowrap;">%s</td>', esc_html( $date_str ) );
			printf( '<td>%s</td>', esc_html( $post_type ) );
			printf( '<td>%s</td>', $cats_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped inside helper.
			$word_count = HtmlMetrics::compute( (string) get_post_field( 'post_content', $post_id ) )['word_count'];
			printf( '<td style="text-align:right;font-variant-numeric:tabular-nums;">%s</td>', esc_html( number_format_i18n( $word_count ) ) );

			// Cellule Constr. : badge + mini-toggle « → Out » / « → Auto ».
			echo '<td>';
			echo self::builder_badge( $builder_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped inside helper.
			echo self::render_override_toggle( $post_id, $is_out ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped inside helper.
			echo '</td>';

			// Bouton Aperçu : grisé sur les Out (article hors périmètre).
			if ( $is_out ) {
				printf(
					'<td><span class="button button-small" disabled title="%s" style="cursor:not-allowed;opacity:0.6;">%s</span></td>',
					esc_attr__( 'Article hors périmètre', '100son-html-normalizer' ),
					esc_html__( 'Aperçu', '100son-html-normalizer' )
				);
			} else {
				printf(
					'<td><a href="%s" class="button button-small">%s</a></td>',
					esc_url( $preview_url ),
					esc_html__( 'Aperçu', '100son-html-normalizer' )
				);
			}
			echo '</tr>';
		}
		wp_reset_postdata();

		echo '</tbody></table>';

		// Bottom tablenav : actions groupées dupliquées + pagination.
		$this->render_bulk_actions_bottom( $query, $paged );

		echo '</form>';

		// JS minimal pour la case "tout sélectionner".
		echo "<script>(function(){var a=document.getElementById('son100-htmln-select-all');if(!a)return;a.addEventListener('change',function(){document.querySelectorAll('input.son100-htmln-row-select').forEach(function(c){c.checked=a.checked;});});})();</script>";
	}

	/**
	 * Filtre `posts_search` qui restreint la recherche au seul `post_title`
	 * (au lieu de title + content + excerpt).
	 *
	 * @param string    $search Clause SQL de recherche.
	 * @param \WP_Query $query  Requête.
	 * @return string
	 */
	public static function restrict_search_to_title( string $search, \WP_Query $query ): string {
		global $wpdb;
		if ( '' === $search || ! isset( $wpdb ) ) {
			return $search;
		}
		$terms = (array) $query->get( 'search_terms' );
		if ( array() === $terms ) {
			return $search;
		}
		$clauses = array();
		foreach ( $terms as $term ) {
			$like      = '%' . $wpdb->esc_like( (string) $term ) . '%';
			$clauses[] = $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s)", $like );
		}
		return ' AND ' . implode( ' AND ', $clauses ) . ' ';
	}

	/**
	 * Filtre `posts_where` : ne garder que les articles **avec blocs Gutenberg
	 * « purs »** — présence du marqueur `<!-- wp:` MAIS pas du bloc hybride
	 * `<!-- wp:siteorigin-panels` (qui est en réalité du SiteOrigin emballé
	 * dans un bloc Gutenberg, cf. `classify_builder()`).
	 *
	 * Utilisé conjointement à `meta_query NOT EXISTS panels_data`.
	 *
	 * @param string $where Clause WHERE SQL.
	 * @return string
	 */
	public static function restrict_to_gutenberg( string $where ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return $where;
		}
		return $where
			. " AND {$wpdb->posts}.post_content LIKE '%<!-- wp:%'"
			. " AND {$wpdb->posts}.post_content NOT LIKE '%<!-- wp:siteorigin-panels%'";
	}

	/**
	 * Filtre `posts_where` : ne garder que les articles **« Autres »** —
	 * ni SiteOrigin (sous aucun de ses 3 modes : classique / bloc / aplati),
	 * ni Gutenberg.
	 *
	 * Le NOT EXISTS sur `panels_data` est déjà appliqué via `meta_query` en amont.
	 * Ici on exclut les marqueurs HTML : `<!-- wp:`, `panel-layout`, `so-panel`.
	 *
	 * @param string $where Clause WHERE SQL.
	 * @return string
	 */
	public static function restrict_to_other( string $where ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return $where;
		}
		return $where
			. " AND {$wpdb->posts}.post_content NOT LIKE '%<!-- wp:%'"
			. " AND {$wpdb->posts}.post_content NOT LIKE '%panel-layout%'"
			. " AND {$wpdb->posts}.post_content NOT LIKE '%so-panel%'";
	}

	/**
	 * Filtre `posts_where` : ne garder que les articles **SiteOrigin** sous
	 * l'un de ses 3 modes :
	 *  - classique : `panels_data` non vide en post_meta,
	 *  - bloc     : `post_content` contient `<!-- wp:siteorigin-panels`,
	 *  - aplati   : `post_content` contient les classes du rendu front
	 *               (`panel-layout` ou `so-panel`) sans structure native.
	 *
	 * Construit une clause `OR` que `meta_query` seul ne sait pas exprimer
	 * (impossible de mêler une condition meta et une condition de contenu
	 * en disjonction via WP_Query).
	 *
	 * @param string $where Clause WHERE SQL.
	 * @return string
	 */
	public static function restrict_to_siteorigin( string $where ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return $where;
		}
		return $where . ' AND ('
			. "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm_so WHERE pm_so.post_id = {$wpdb->posts}.ID AND pm_so.meta_key = 'panels_data' )"
			. " OR {$wpdb->posts}.post_content LIKE '%<!-- wp:siteorigin-panels%'"
			. " OR {$wpdb->posts}.post_content LIKE '%panel-layout%'"
			. " OR {$wpdb->posts}.post_content LIKE '%so-panel%'"
			. ')';
	}

	/**
	 * Classifie un article selon son constructeur :
	 *  1. SiteOrigin (mode classique) si `panels_data` non vide,
	 *  2. SiteOrigin (mode bloc) si le contenu contient `<!-- wp:siteorigin-panels`
	 *     (depuis SiteOrigin 2.10+, le builder peut être encapsulé dans un bloc
	 *     Gutenberg `siteorigin-panels/layout-block` sans `panels_data` en meta),
	 *  3. SiteOrigin (mode aplati) si le contenu porte les classes du rendu front
	 *     SO (`panel-layout`, `so-panel`) sans avoir aucune des deux structures
	 *     natives ci-dessus — c'est du HTML SO figé après aplatissement,
	 *     potentiellement issu d'une édition Gutenberg ou d'un script de migration
	 *     incomplet. À traiter avec précaution (la normalisation HTML naïve peut
	 *     casser la structure en grilles imbriquées).
	 *  4. sinon Gutenberg si `has_blocks()` détecte un autre marqueur `<!-- wp:`,
	 *  5. sinon « Autres » (HTML libre, éditeur classique, …).
	 *
	 * L'ordre est figé : un article SO peut techniquement contenir aussi
	 * des blocs (édition mixte rare), mais la présence d'un marqueur SO
	 * prévaut — c'est lui qui pilote le rendu front.
	 *
	 * @param int  $post_id Identifiant.
	 * @param bool $has_so  Résultat pré-calculé de `SiteOriginDetector::has_panels_data()`.
	 * @return string Une des constantes `BUILDER_*`.
	 */
	private static function classify_builder( int $post_id, bool $has_so ): string {
		// Override manuel : prend la priorité absolue sur la détection auto.
		// Posé via le toggle « → Out » de la colonne Constr., stocké en post_meta.
		$override = (string) get_post_meta( $post_id, self::META_OVERRIDE, true );
		if ( self::BUILDER_OUT === $override ) {
			return self::BUILDER_OUT;
		}

		if ( $has_so ) {
			return self::BUILDER_SO;
		}
		$content = (string) get_post_field( 'post_content', $post_id );

		// Mode hybride : SiteOrigin packagé en bloc Gutenberg.
		if ( str_contains( $content, '<!-- wp:siteorigin-panels' ) ) {
			return self::BUILDER_SO;
		}

		// Mode aplati : rendu HTML SO figé dans post_content.
		if ( str_contains( $content, 'panel-layout' ) || str_contains( $content, 'so-panel' ) ) {
			return self::BUILDER_SO_FLAT;
		}

		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			return self::BUILDER_GUT;
		}
		return self::BUILDER_OTHER;
	}

	/**
	 * Badge HTML correspondant à un type de constructeur.
	 *
	 * Couleurs alignées sur les conventions WP admin :
	 *  - SO  → rouge `#d63638` (déjà utilisé pour les avertissements forts)
	 *  - Gut → vert `#00a32a` (succès)
	 *  - ?   → jaune `#f0b849` (attention/inconnu)
	 *
	 * @param string $type Une des constantes `BUILDER_*`.
	 * @return string HTML déjà échappé (single `<span>`).
	 */
	private static function builder_badge( string $type ): string {
		$presets = array(
			self::BUILDER_SO      => array(
				'bg'    => '#d63638',
				'fg'    => '#fff',
				'label' => 'SO',
				'title' => __( 'SiteOrigin Page Builder (mode natif : panels_data ou bloc siteorigin-panels)', '100son-html-normalizer' ),
			),
			self::BUILDER_SO_FLAT => array(
				'bg'    => '#dba617',
				'fg'    => '#fff',
				'label' => 'SO~',
				'title' => __( 'SiteOrigin (mode aplati) — rendu HTML figé sans panels_data ni bloc, normalisation à risque', '100son-html-normalizer' ),
			),
			self::BUILDER_GUT     => array(
				'bg'    => '#00a32a',
				'fg'    => '#fff',
				'label' => 'Gut',
				'title' => __( 'Gutenberg (blocs FSE)', '100son-html-normalizer' ),
			),
			self::BUILDER_OTHER   => array(
				'bg'    => '#f0b849',
				'fg'    => '#1d2327',
				'label' => '?',
				'title' => __( 'Constructeur inconnu (HTML libre / éditeur classique)', '100son-html-normalizer' ),
			),
			self::BUILDER_OUT     => array(
				'bg'    => '#646970',
				'fg'    => '#fff',
				'label' => 'Out',
				'title' => __( 'Hors périmètre (tag manuel) — actions de normalisation désactivées', '100son-html-normalizer' ),
			),
		);
		$p = $presets[ $type ] ?? $presets[ self::BUILDER_OTHER ];

		return sprintf(
			'<span title="%s" style="display:inline-block;padding:2px 8px;background:%s;color:%s;border-radius:3px;font-size:11px;font-weight:600;">%s</span>',
			esc_attr( (string) $p['title'] ),
			esc_attr( (string) $p['bg'] ),
			esc_attr( (string) $p['fg'] ),
			esc_html( (string) $p['label'] )
		);
	}

	/**
	 * Mini-form inline pour basculer le tag manuel d'un article entre Out
	 * et Auto (suppression de l'override → retour à la classification auto).
	 *
	 * Stratégie POST classique avec PRG (cf. `maybe_handle_set_override()` qui
	 * redirige vers la liste filtrée). L'URL de retour est portée par un champ
	 * caché `redirect_to` qui mémorise les filtres GET courants.
	 *
	 * @param int  $post_id ID de l'article.
	 * @param bool $is_out  Vrai si l'article est actuellement Out.
	 * @return string HTML déjà échappé.
	 */
	private static function render_override_toggle( int $post_id, bool $is_out ): string {
		$next_value = $is_out ? '' : self::BUILDER_OUT;
		$label      = $is_out
			? __( '→ Auto', '100son-html-normalizer' )
			: __( '→ Out', '100son-html-normalizer' );
		$title      = $is_out
			? __( 'Retirer le tag Out (revenir à la classification automatique)', '100son-html-normalizer' )
			: __( 'Marquer cet article comme hors périmètre', '100son-html-normalizer' );

		// URL de retour = page courante avec ses filtres GET préservés.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_query = $_SERVER['REQUEST_URI'] ?? '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$nonce = wp_nonce_field( self::NONCE_OVERRIDE, self::NONCE_NAME, true, false );

		return sprintf(
			'<form method="post" action="%s" style="display:inline;margin-left:6px;">'
				. '<input type="hidden" name="action" value="%s">'
				. '<input type="hidden" name="post_id" value="%d">'
				. '<input type="hidden" name="override" value="%s">'
				. '<input type="hidden" name="redirect_to" value="%s">'
				. '%s'
				. '<button type="submit" class="button-link" style="font-size:11px;color:#2271b1;text-decoration:none;cursor:pointer;" title="%s">%s</button>'
				. '</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::ACTION_SET_OVERRIDE ),
			(int) $post_id,
			esc_attr( $next_value ),
			esc_attr( (string) $current_query ),
			$nonce, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_nonce_field renvoie déjà du HTML échappé.
			esc_attr( $title ),
			esc_html( $label )
		);
	}

	/**
	 * Construit un `<th>` triable au format natif WP admin.
	 *
	 * Utilise les classes `sortable` / `sorted asc|desc` reconnues par les
	 * styles CSS standard de l'admin WP, qui appliquent automatiquement :
	 *  - cursor:pointer
	 *  - flèche `<span class="sorting-indicator">` stylée
	 *  - hover/active states
	 *
	 * @param string $key             Clé de la colonne (ID, title, date).
	 * @param string $label           Libellé affiché.
	 * @param string $current_orderby Colonne triée actuellement.
	 * @param string $current_order   Sens courant (ASC/DESC).
	 * @param string $extra_style     Styles inline supplémentaires (ex: width).
	 * @return string HTML complet d'un `<th>` (déjà échappé).
	 */
	private static function sortable_th( string $key, string $label, string $current_orderby, string $current_order, string $extra_style = '' ): string {
		$is_current = ( $key === $current_orderby );
		$cur_lower  = strtolower( $current_order );
		$next_order = $is_current && 'asc' === $cur_lower ? 'desc' : 'asc';

		// Classes WP : `sortable` (toujours), `sorted` + asc|desc (si actif).
		$th_classes = 'manage-column column-' . sanitize_html_class( $key ) . ' sortable';
		if ( $is_current ) {
			$th_classes .= ' sorted ' . $cur_lower;
		} else {
			$th_classes .= ' desc'; // état non-trié : icône par défaut WP
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$url = add_query_arg(
			array_filter(
				array(
					'page'    => self::PAGE_SLUG,
					'orderby' => $key,
					'order'   => $next_order,
					's'       => isset( $_GET['s'] ) ? (string) wp_unslash( $_GET['s'] ) : null,
					'cat'     => isset( $_GET['cat'] ) && (int) $_GET['cat'] > 0 ? (int) $_GET['cat'] : null,
					'year'    => isset( $_GET['year'] ) && (int) $_GET['year'] > 0 ? (int) $_GET['year'] : null,
					'month'   => isset( $_GET['month'] ) && (int) $_GET['month'] > 0 ? (int) $_GET['month'] : null,
					'builder' => isset( $_GET['builder'] ) && '' !== (string) $_GET['builder'] ? sanitize_key( (string) $_GET['builder'] ) : null,
				),
				static fn( $v ): bool => null !== $v
			),
			admin_url( 'admin.php' )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$style_attr = '' !== $extra_style ? sprintf( ' style="%s"', esc_attr( $extra_style ) ) : '';

		return sprintf(
			'<th scope="col" class="%s"%s><a href="%s"><span>%s</span><span class="sorting-indicator"></span></a></th>',
			esc_attr( $th_classes ),
			$style_attr,
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Render des actions groupées + pagination au-dessus du tableau.
	 *
	 * @param \WP_Query $query Requête courante.
	 * @param int       $paged Page courante.
	 * @return void
	 */
	private function render_bulk_actions_top( \WP_Query $query, int $paged ): void {
		echo '<div class="tablenav top" style="padding:6px 0;">';
		echo '<div class="alignleft actions bulkactions" style="display:inline-flex;gap:8px;align-items:center;">';
		echo '<select name="bulk_action">';
		printf( '<option value="">%s</option>', esc_html__( 'Action groupée…', '100son-html-normalizer' ) );
		printf( '<option value="normalize">%s</option>', esc_html__( 'Normaliser la sélection', '100son-html-normalizer' ) );
		printf( '<option value="normalize_force_so">%s</option>', esc_html__( 'Normaliser (forcer SO)', '100son-html-normalizer' ) );
		echo '</select>';
		printf(
			'<button type="submit" class="button" onclick="return confirm(\'%s\');">%s</button>',
			esc_js( __( 'Confirmer la normalisation des articles sélectionnés ?', '100son-html-normalizer' ) ),
			esc_html__( 'Appliquer', '100son-html-normalizer' )
		);
		echo '</div>';
		echo '<div class="alignright">';
		$this->render_pagination( $query, $paged );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render des actions groupées + pagination en bas du tableau.
	 *
	 * @param \WP_Query $query Requête courante.
	 * @param int       $paged Page courante.
	 * @return void
	 */
	private function render_bulk_actions_bottom( \WP_Query $query, int $paged ): void {
		echo '<div class="tablenav bottom" style="padding:6px 0;">';
		echo '<div class="alignleft actions bulkactions" style="display:inline-flex;gap:8px;align-items:center;">';
		echo '<select name="bulk_action2">';
		printf( '<option value="">%s</option>', esc_html__( 'Action groupée…', '100son-html-normalizer' ) );
		printf( '<option value="normalize">%s</option>', esc_html__( 'Normaliser la sélection', '100son-html-normalizer' ) );
		printf( '<option value="normalize_force_so">%s</option>', esc_html__( 'Normaliser (forcer SO)', '100son-html-normalizer' ) );
		echo '</select>';
		printf(
			'<button type="submit" class="button" onclick="document.getElementsByName(\'bulk_action\')[0].value=document.getElementsByName(\'bulk_action2\')[0].value;return confirm(\'%s\');">%s</button>',
			esc_js( __( 'Confirmer la normalisation des articles sélectionnés ?', '100son-html-normalizer' ) ),
			esc_html__( 'Appliquer', '100son-html-normalizer' )
		);
		echo '</div>';
		echo '<div class="alignright">';
		$this->render_pagination( $query, $paged );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Liste les termes hiérarchiques d'un post (catégories pour les `post`,
	 * autres taxonomies hiérarchiques pour les CPT). Output déjà échappé.
	 *
	 * @param int    $post_id   ID.
	 * @param string $post_type Type de contenu.
	 * @return string HTML déjà échappé (liste de termes séparés par virgule, ou « — »).
	 */
	private static function format_terms( int $post_id, string $post_type ): string {
		// Récupère les taxonomies hiérarchiques attachées au post_type.
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$names      = array();
		foreach ( $taxonomies as $tax ) {
			if ( ! is_object( $tax ) ) {
				continue;
			}
			$slug         = (string) ( $tax->name ?? '' );
			$is_hierarchic = (bool) ( $tax->hierarchical ?? false );
			if ( '' === $slug || ! $is_hierarchic ) {
				continue;
			}
			$terms = get_the_terms( $post_id, $slug );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( is_object( $term ) && isset( $term->name ) ) {
					$names[] = (string) $term->name;
				}
			}
		}
		if ( array() === $names ) {
			return '<span style="color:#646970;">—</span>';
		}
		return esc_html( implode( ', ', $names ) );
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
		// Préserve filtres + tri lors du changement de page.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$preserved = array_filter(
			array(
				'page'    => self::PAGE_SLUG,
				's'       => isset( $_GET['s'] ) ? (string) wp_unslash( $_GET['s'] ) : null,
				'cat'     => isset( $_GET['cat'] ) && (int) $_GET['cat'] > 0 ? (int) $_GET['cat'] : null,
				'year'    => isset( $_GET['year'] ) && (int) $_GET['year'] > 0 ? (int) $_GET['year'] : null,
				'month'   => isset( $_GET['month'] ) && (int) $_GET['month'] > 0 ? (int) $_GET['month'] : null,
				'builder' => isset( $_GET['builder'] ) && '' !== (string) $_GET['builder'] ? sanitize_key( (string) $_GET['builder'] ) : null,
				'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : null,
				'order'   => isset( $_GET['order'] ) ? sanitize_key( (string) $_GET['order'] ) : null,
			),
			static fn( $v ): bool => null !== $v && '' !== $v
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$base  = add_query_arg( $preserved, admin_url( 'admin.php' ) );
			$links = paginate_links(
				array(
					'base'      => $base . '%_%',
					'format'    => '&paged=%#%',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			);
		if ( ! empty( $links ) ) {
			echo '<div class="tablenav-pages">' . $links . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	// ===================================================================
	// Render preview (avant / après + confirm)
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
			echo '<p>' . esc_html__( 'Cet article utilise SiteOrigin Page Builder. Normaliser le post_content brut ignorera la structure du builder. Préférer SO to Blocks pour la migration. Si vous savez ce que vous faites, cochez « Continuer quand même » avant de cliquer sur Normaliser.', '100son-html-normalizer' ) . '</p>';
			echo '</div>';
		}

		if ( PostNormalizer::STATUS_UNCHANGED === $preview['status'] ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Aucune modification : le HTML est déjà conforme aux préréglages actifs.', '100son-html-normalizer' ) . '</p></div>';
		}

		// Encart métriques avant/après.
		if ( isset( $preview['metrics'] ) && is_array( $preview['metrics'] ) ) {
			$this->render_metrics_box( $preview['metrics'] );
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
				array(
					'page'    => self::PAGE_SLUG,
					'action'  => 'preview',
					'post_id' => $post_id,
				),
				admin_url( 'admin.php' )
			);
			echo '<form method="post" action="' . esc_url( $action_url ) . '" style="margin-top:24px;padding:16px;background:#fff;border:1px solid #c3c4c7;">';
			echo '<input type="hidden" name="son100_htmln_action" value="normalize">';
			printf( '<input type="hidden" name="post_id" value="%d">', (int) $post_id );
			wp_nonce_field( self::NONCE_NORMALIZE, self::NONCE_NAME );

			if ( $preview['has_panels_data'] ) {
				echo '<p><label><input type="checkbox" name="force_siteorigin" value="1" required> ';
				echo esc_html__( 'Continuer quand même (article SiteOrigin)', '100son-html-normalizer' );
				echo '</label></p>';
			}

			echo '<p>' . esc_html__( "Une révision WP sera créée juste avant l'écriture. Vous pourrez restaurer la version précédente depuis l'écran d'édition de l'article.", '100son-html-normalizer' ) . '</p>';
			submit_button( __( 'Normaliser et enregistrer', '100son-html-normalizer' ), 'primary' );
			echo '</form>';
		}
	}

	/**
	 * Affiche une notice après une action groupée de normalisation.
	 *
	 * @param array{counts: array<string, int>, errors: list<string>} $info Compte par statut.
	 * @return void
	 */
	private function render_bulk_notice( array $info ): void {
		$counts = $info['counts'];
		$errors = $info['errors'];

		$total_processed = array_sum( $counts );
		if ( 0 === $total_processed && array() !== $errors ) {
			echo '<div class="notice notice-error"><p>' . esc_html( implode( ' / ', $errors ) ) . '</p></div>';
			return;
		}

		$pieces   = array();
		$pieces[] = sprintf(
			/* translators: %d: total */
			_n( '%d article traité.', '%d articles traités.', $total_processed, '100son-html-normalizer' ),
			$total_processed
		);
		if ( $counts[ PostNormalizer::STATUS_MODIFIED ] > 0 ) {
			$pieces[] = sprintf( __( 'Modifiés : %d', '100son-html-normalizer' ), $counts[ PostNormalizer::STATUS_MODIFIED ] );
		}
		if ( $counts[ PostNormalizer::STATUS_UNCHANGED ] > 0 ) {
			$pieces[] = sprintf( __( 'Inchangés : %d', '100son-html-normalizer' ), $counts[ PostNormalizer::STATUS_UNCHANGED ] );
		}
		if ( $counts[ PostNormalizer::STATUS_SKIPPED_SO ] > 0 ) {
			$pieces[] = sprintf( __( 'Refusés (SO) : %d', '100son-html-normalizer' ), $counts[ PostNormalizer::STATUS_SKIPPED_SO ] );
		}
		if ( $counts[ PostNormalizer::STATUS_ERROR_NOT_FOUND ] + $counts[ PostNormalizer::STATUS_ERROR_WRITE ] > 0 ) {
			$pieces[] = sprintf(
				__( 'Erreurs : %d', '100son-html-normalizer' ),
				$counts[ PostNormalizer::STATUS_ERROR_NOT_FOUND ] + $counts[ PostNormalizer::STATUS_ERROR_WRITE ]
			);
		}

		$class = $counts[ PostNormalizer::STATUS_ERROR_NOT_FOUND ] + $counts[ PostNormalizer::STATUS_ERROR_WRITE ] > 0
			? 'notice-warning'
			: 'notice-success';

		printf( '<div class="notice %s is-dismissible"><p>%s</p>', esc_attr( $class ), esc_html( implode( ' — ', $pieces ) ) );
		if ( array() !== $errors ) {
			echo '<ul style="margin-left:24px;">';
			foreach ( $errors as $err ) {
				printf( '<li>%s</li>', esc_html( $err ) );
			}
			echo '</ul>';
		}
		echo '</div>';
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
					__( 'Article #%d normalisé avec succès. Une révision a été créée pour rollback.', '100son-html-normalizer' ),
					(int) $info['post_id']
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
				break;
			case PostNormalizer::STATUS_UNCHANGED:
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Aucune modification appliquée.', '100son-html-normalizer' ) . '</p></div>';
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

	/**
	 * Render de l'encart métriques avant/après dans la vue Aperçu.
	 *
	 * @param array<string, mixed> $metrics ['before' => [...], 'after' => [...], 'diff' => [...]].
	 * @return void
	 */
	private function render_metrics_box( array $metrics ): void {
		$before = $metrics['before'] ?? array();
		$after  = $metrics['after'] ?? array();
		$diff   = $metrics['diff'] ?? array();
		if ( ! is_array( $before ) || ! is_array( $after ) || ! is_array( $diff ) ) {
			return;
		}
		$severity = (string) ( $diff['severity'] ?? HtmlMetrics::SEVERITY_OK );
		$badge    = self::severity_badge( $severity );

		echo '<div style="margin:16px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;">';
		echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
		echo '<strong>' . esc_html__( 'Garde-fou perte de contenu', '100son-html-normalizer' ) . '</strong>';
		echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<table class="widefat" style="max-width:640px;">';
		echo '<thead><tr>';
		echo '<th></th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Avant', '100son-html-normalizer' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Après', '100son-html-normalizer' ) . '</th>';
		echo '<th style="text-align:right;">' . esc_html__( 'Δ', '100son-html-normalizer' ) . '</th>';
		echo '<th style="text-align:right;">%</th>';
		echo '</tr></thead><tbody>';

		$rows = array(
			array( __( 'Mots', '100son-html-normalizer' ), 'word_count', 'word_delta', 'word_pct' ),
			array( __( 'Caractères', '100son-html-normalizer' ), 'char_count', 'char_delta', 'char_pct' ),
			array( __( 'Images', '100son-html-normalizer' ), 'image_count', 'image_delta', null ),
		);
		foreach ( $rows as $row ) {
			[ $label, $count_key, $delta_key, $pct_key ] = $row;
			$b   = (int) ( $before[ $count_key ] ?? 0 );
			$a   = (int) ( $after[ $count_key ] ?? 0 );
			$d   = (int) ( $diff[ $delta_key ] ?? 0 );
			$pct = null !== $pct_key ? (float) ( $diff[ $pct_key ] ?? 0.0 ) : null;
			$color = 0 === $d ? '' : ( $d < 0 ? 'color:#d63638;font-weight:600;' : 'color:#00a32a;font-weight:600;' );

			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( $label ) );
			printf( '<td style="text-align:right;font-variant-numeric:tabular-nums;">%s</td>', esc_html( number_format_i18n( $b ) ) );
			printf( '<td style="text-align:right;font-variant-numeric:tabular-nums;">%s</td>', esc_html( number_format_i18n( $a ) ) );
			printf(
				'<td style="text-align:right;font-variant-numeric:tabular-nums;%s">%s%s</td>',
				esc_attr( $color ),
				$d > 0 ? '+' : '',
				esc_html( number_format_i18n( $d ) )
			);
			if ( null === $pct ) {
				echo '<td style="text-align:right;color:#646970;">—</td>';
			} else {
				printf(
					'<td style="text-align:right;font-variant-numeric:tabular-nums;%s">%s%s%%</td>',
					esc_attr( $color ),
					$pct > 0 ? '+' : '',
					esc_html( number_format_i18n( $pct, 1 ) )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Badge HTML colore selon le niveau de severite.
	 *
	 * @param string $severity HtmlMetrics::SEVERITY_*.
	 * @return string HTML déjà échappé.
	 */
	public static function severity_badge( string $severity ): string {
		$presets = array(
			HtmlMetrics::SEVERITY_OK       => array(
				'bg' => '#00a32a',
				'fg' => '#fff',
				'label' => __( 'OK', '100son-html-normalizer' ),
			),
			HtmlMetrics::SEVERITY_WARNING  => array(
				'bg' => '#f0b849',
				'fg' => '#1d2327',
				'label' => __( 'Attention', '100son-html-normalizer' ),
			),
			HtmlMetrics::SEVERITY_CRITICAL => array(
				'bg' => '#d63638',
				'fg' => '#fff',
				'label' => __( 'Perte significative', '100son-html-normalizer' ),
			),
		);
		$p = $presets[ $severity ] ?? $presets[ HtmlMetrics::SEVERITY_OK ];
		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;background:%s;color:%s;border-radius:3px;font-size:11px;font-weight:600;">%s</span>',
			esc_attr( $p['bg'] ),
			esc_attr( $p['fg'] ),
			esc_html( $p['label'] )
		);
	}

	// ===================================================================
	// Helpers
	// ===================================================================

	/**
	 * Liste les post_types eligibles : post, page, et CPT publics custom.
	 *
	 * @return array<string, string> slug => label
	 */
	private function get_available_post_types(): array {
		$out = array();
		$pt  = get_post_types( array( 'public' => true ), 'objects' );
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
		return add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
	}
}
