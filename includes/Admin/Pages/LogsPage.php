<?php
/**
 * Page admin "Journal" — historique des actions du plugin.
 *
 * Affichage paginé des entrees du LogRepository avec un bouton de purge.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin\Pages;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Admin\Pages\PostsPage;
use Cent_Son\Html_Normalizer\Core\Logs\LogRepository;
use Cent_Son\Html_Normalizer\Core\Logs\NotesRepository;
use Cent_Son\Html_Normalizer\Core\Metrics\HtmlMetrics;
use Cent_Son\Html_Normalizer\Core\Posts\PostNormalizer;

/**
 * Vue Journal.
 */
final class LogsPage {

	private const NONCE_CLEAR       = 'son100_htmln_logs_clear';
	private const NONCE_NOTES_SAVE  = 'son100_htmln_notes_save';
	private const NONCE_NOTES_CLEAR = 'son100_htmln_notes_clear';
	private const NONCE_NAME        = '_son100_htmln_nonce';
	private const PAGE_SLUG         = '100son-html-normalizer-logs';
	private const PER_PAGE          = 50;

	private LogRepository $repo;
	private NotesRepository $notes;

	public function __construct( LogRepository $repo, NotesRepository $notes ) {
		$this->repo  = $repo;
		$this->notes = $notes;
	}

	/**
	 * Render principal.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', '100son-html-normalizer' ) );
		}

		$cleared       = $this->maybe_handle_clear();
		$notes_saved   = $this->maybe_handle_notes_save();
		$notes_cleared = $this->maybe_handle_notes_clear();

		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page  = $this->repo->paginate( $paged, self::PER_PAGE );
		$total = $page['total'];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'HTML Normalizer — Journal', '100son-html-normalizer' ) . '</h1>';

		// Notices empilées en haut.
		if ( $notes_saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Note enregistrée.', '100son-html-normalizer' ) . '</p></div>';
		}
		if ( $notes_cleared ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Note effacée.', '100son-html-normalizer' ) . '</p></div>';
		}
		if ( $cleared ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Journal vidé.', '100son-html-normalizer' ) . '</p></div>';
		}

		// Zone de saisie libre — au-dessus du journal, indépendante.
		$this->render_notes_form();

		echo '<h2 style="margin-top:32px;">' . esc_html__( 'Historique des actions', '100son-html-normalizer' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %1$d nombre d'entrees, %2$d capacité max */
				__( 'Normalisations, aperçus, changements de configuration. %1$d entrée(s) sur %2$d max — les plus anciennes sont automatiquement évincées.', '100son-html-normalizer' ),
				(int) $total,
				LogRepository::MAX_ENTRIES
			)
		) . '</p>';

		if ( 0 === $total ) {
			echo '<p><em>' . esc_html__( 'Aucune entrée enregistrée pour l\'instant.', '100son-html-normalizer' ) . '</em></p>';
		} else {
			$this->render_table( $page['entries'] );
			$this->render_pagination( $paged, $page['total_pages'] );
		}

		$this->render_clear_form();

		echo '</div>';
	}

	/**
	 * Traite le POST de purge si présent.
	 *
	 * @return bool True si purge effectuée.
	 */
	private function maybe_handle_clear(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return false;
		}
		if ( ! isset( $_POST['son100_htmln_action'] ) || 'clear_logs' !== $_POST['son100_htmln_action'] ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_CLEAR, self::NONCE_NAME );

		$this->repo->clear();
		return true;
	}

	/**
	 * Traite la sauvegarde de la note libre (POST).
	 *
	 * @return bool True si une sauvegarde a eu lieu.
	 */
	private function maybe_handle_notes_save(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return false;
		}
		if ( ! isset( $_POST['son100_htmln_action'] ) || 'save_notes' !== $_POST['son100_htmln_action'] ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_NOTES_SAVE, self::NONCE_NAME );

		$raw = isset( $_POST['son100_htmln_notes'] )
			? sanitize_textarea_field( (string) wp_unslash( $_POST['son100_htmln_notes'] ) )
			: '';

		$this->notes->set( $raw );
		return true;
	}

	/**
	 * Traite l'effacement de la note libre (POST), independant du journal.
	 *
	 * @return bool True si l'effacement a eu lieu.
	 */
	private function maybe_handle_notes_clear(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return false;
		}
		if ( ! isset( $_POST['son100_htmln_action'] ) || 'clear_notes' !== $_POST['son100_htmln_action'] ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_NOTES_CLEAR, self::NONCE_NAME );

		$this->notes->clear();
		return true;
	}

	/**
	 * Render du tableau d'entrées.
	 *
	 * @param list<array<string, mixed>> $entries Entrées (récent en premier).
	 * @return void
	 */
	private function render_table( array $entries ): void {
		echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px;">';
		echo '<thead><tr>';
		echo '<th style="width:160px;">' . esc_html__( 'Date', '100son-html-normalizer' ) . '</th>';
		echo '<th style="width:90px;">' . esc_html__( 'Évènement', '100son-html-normalizer' ) . '</th>';
		echo '<th style="width:100px;">' . esc_html__( 'Statut', '100son-html-normalizer' ) . '</th>';
		echo '<th style="width:70px;">ID</th>';
		echo '<th>' . esc_html__( 'Article / Détail', '100son-html-normalizer' ) . '</th>';
		echo '<th style="width:110px;">' . esc_html__( 'Utilisateur', '100son-html-normalizer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $e ) {
			$timestamp   = (int) ( $e['timestamp'] ?? 0 );
			$event       = (string) ( $e['event'] ?? '' );
			$status      = (string) ( $e['status'] ?? '' );
			$post_id     = isset( $e['post_id'] ) ? (int) $e['post_id'] : 0;
			$post_title  = (string) ( $e['post_title'] ?? '' );
			$user_login  = (string) ( $e['user_login'] ?? '' );
			$user_id     = (int) ( $e['user_id'] ?? 0 );
			$message     = (string) ( $e['message'] ?? '' );
			$revision_id = (int) ( $e['revision_id'] ?? 0 );
			$metrics     = isset( $e['metrics'] ) && is_array( $e['metrics'] ) ? $e['metrics'] : null;
			$metrics_str = self::format_metrics_summary( $metrics );

			$date_str = $timestamp > 0
				? wp_date( 'Y-m-d H:i:s', $timestamp )
				: '—';

			echo '<tr>';
			printf( '<td style="white-space:nowrap;">%s</td>', esc_html( (string) $date_str ) );
			printf( '<td>%s</td>', esc_html( self::label_event( $event ) ) );
			printf( '<td>%s</td>', wp_kses( self::badge_status( $status ), array( 'span' => array( 'style' => true ) ) ) );

			if ( $post_id > 0 ) {
				printf( '<td>%d</td>', (int) $post_id );
				$edit_url = (string) get_edit_post_link( $post_id );
				$rev_link = '';
				if ( $revision_id > 0 ) {
					$rev_link = sprintf(
						' <a href="%s" target="_blank" style="font-size:11px;color:#646970;">[rev #%d]</a>',
						esc_url( admin_url( 'revision.php?revision=' . $revision_id ) ),
						(int) $revision_id
					);
				}
				printf(
					'<td><strong>%s</strong>%s%s%s</td>',
					'' === $edit_url
						? esc_html( '' === trim( $post_title ) ? __( '(sans titre)', '100son-html-normalizer' ) : $post_title )
						: sprintf(
							'<a href="%s" target="_blank">%s</a>',
							esc_url( $edit_url ),
							esc_html( '' === trim( $post_title ) ? __( '(sans titre)', '100son-html-normalizer' ) : $post_title )
						),
					'' !== $message
						? '<br><span class="description" style="font-size:12px;">' . esc_html( $message ) . '</span>'
						: '',
					$rev_link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — déjà esc_url + sprintf typé
					$metrics_str // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped inside helper
				);
			} else {
				echo '<td>—</td>';
				printf(
					'<td><span class="description">%s</span></td>',
					esc_html( '' !== $message ? $message : __( '—', '100son-html-normalizer' ) )
				);
			}

			printf(
				'<td>%s</td>',
				$user_id > 0 && '' !== $user_login
					? esc_html( $user_login )
					: '<em style="color:#646970;">' . esc_html__( 'inconnu', '100son-html-normalizer' ) . '</em>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render de la pagination.
	 *
	 * @param int $paged       Page courante.
	 * @param int $total_pages Total pages.
	 * @return void
	 */
	private function render_pagination( int $paged, int $total_pages ): void {
		if ( $total_pages <= 1 ) {
			return;
		}
		$base  = add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
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
			echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Render de la zone de saisie libre (notes admin) au-dessus du journal.
	 *
	 * Possède 2 actions independantes du journal :
	 *  - Enregistrer (sauvegarde le texte saisi)
	 *  - Effacer (vide la note, sans toucher au journal)
	 *
	 * @return void
	 */
	private function render_notes_form(): void {
		$current = $this->notes->get();

		echo '<div style="margin-top:16px;padding:12px;background:#fff;border:1px solid #c3c4c7;max-width:900px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Note libre', '100son-html-normalizer' ) . '</h2>';
		echo '<p class="description" style="margin-top:0;">' . esc_html__( 'Espace de notes contextuelles persistantes. Indépendant du journal des actions.', '100son-html-normalizer' ) . '</p>';

		// Form 1 : sauvegarde de la note (form principal qui contient la textarea).
		echo '<form id="son100-htmln-save-notes-form" method="post" action="">';
		echo '<input type="hidden" name="son100_htmln_action" value="save_notes">';
		wp_nonce_field( self::NONCE_NOTES_SAVE, self::NONCE_NAME );
		printf(
			'<textarea name="son100_htmln_notes" rows="6" style="width:100%%;font-family:monospace;" placeholder="%s">%s</textarea>',
			esc_attr__( 'Tape ici les notes que tu veux garder à portée de main…', '100son-html-normalizer' ),
			esc_textarea( $current )
		);
		echo '</form>';

		// Form 2 : effacement (frère du form 1, à part pour pouvoir aligner ses boutons).
		echo '<form id="son100-htmln-clear-notes-form" method="post" action="" style="display:none;">';
		echo '<input type="hidden" name="son100_htmln_action" value="clear_notes">';
		wp_nonce_field( self::NONCE_NOTES_CLEAR, self::NONCE_NAME );
		echo '</form>';

		// Barre d'actions : 2 boutons sur la même ligne, Save à gauche, Clear à droite.
		// Les boutons utilisent l'attribut HTML5 `form="..."` pour cibler leur form respectif
		// même s'ils sont rendus en dehors.
		echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">';

		// Save à gauche.
		printf(
			'<button type="submit" form="son100-htmln-save-notes-form" class="button button-primary">%s</button>',
			esc_html__( 'Enregistrer', '100son-html-normalizer' )
		);

		// Clear à droite, avec confirm() JS bloquant. N'apparaît que si une note existe.
		if ( '' !== $current ) {
			$confirm = esc_attr__( "Confirmer l'effacement de la note ?\n\nLe journal des actions n'est PAS affecté.", '100son-html-normalizer' );
			printf(
				'<button type="submit" form="son100-htmln-clear-notes-form" class="button button-link-delete" onclick="return window.confirm(\'%s\');">%s</button>',
				esc_js( $confirm ),
				esc_html__( 'Effacer', '100son-html-normalizer' )
			);
		} else {
			// Aucune note : placeholder vide pour préserver le justify-content:space-between.
			echo '<span></span>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render du formulaire de purge.
	 *
	 * @return void
	 */
	private function render_clear_form(): void {
		if ( 0 === $this->repo->count() ) {
			return;
		}
		$confirm_msg = esc_attr__( 'Confirmer la suppression de toutes les entrées du journal ?', '100son-html-normalizer' );
		echo '<form method="post" action="" style="margin-top:24px;padding:12px;background:#fff;border:1px solid #c3c4c7;max-width:480px;">';
		echo '<input type="hidden" name="son100_htmln_action" value="clear_logs">';
		wp_nonce_field( self::NONCE_CLEAR, self::NONCE_NAME );
		printf(
			'<button type="submit" class="button button-secondary" onclick="return confirm(\'%s\');">%s</button>',
			esc_js( $confirm_msg ),
			esc_html__( 'Vider le journal', '100son-html-normalizer' )
		);
		echo ' <span class="description">' . esc_html__( "L'action est immédiate et irréversible.", '100son-html-normalizer' ) . '</span>';
		echo '</form>';
	}

	// ===================================================================
	// Helpers d'affichage
	// ===================================================================

	private static function label_event( string $event ): string {
		return match ( $event ) {
			'normalize' => __( 'Normalisation', '100son-html-normalizer' ),
			'preview'   => __( 'Aperçu', '100son-html-normalizer' ),
			'settings'  => __( 'Configuration', '100son-html-normalizer' ),
			default     => $event,
		};
	}

	/**
	 * Construit un résumé HTML court des métriques avant/après pour la cellule détail.
	 *
	 * @param array<string, mixed>|null $metrics ['before','after','diff'] ou null.
	 * @return string HTML déjà échappé (vide si rien).
	 */
	private static function format_metrics_summary( ?array $metrics ): string {
		if ( ! is_array( $metrics ) || empty( $metrics['diff'] ) ) {
			return '';
		}
		$diff = $metrics['diff'];
		if ( ! is_array( $diff ) ) {
			return '';
		}
		$word_delta  = (int) ( $diff['word_delta'] ?? 0 );
		$word_pct    = (float) ( $diff['word_pct'] ?? 0.0 );
		$image_delta = (int) ( $diff['image_delta'] ?? 0 );
		$severity    = (string) ( $diff['severity'] ?? HtmlMetrics::SEVERITY_OK );

		// Aucun delta -> rien à afficher.
		if ( 0 === $word_delta && 0 === $image_delta ) {
			return '';
		}

		$bits = array();
		if ( 0 !== $word_delta ) {
			$bits[] = sprintf(
				'%s%d mots (%s%.1f%%)',
				$word_delta > 0 ? '+' : '',
				$word_delta,
				$word_pct > 0 ? '+' : '',
				$word_pct
			);
		}
		if ( 0 !== $image_delta ) {
			$bits[] = sprintf( '%s%d image(s)', $image_delta > 0 ? '+' : '', $image_delta );
		}

		$badge = HtmlMetrics::SEVERITY_OK !== $severity
			? ' ' . PostsPage::severity_badge( $severity )
			: '';

		return sprintf(
			'<br><span class="description" style="font-size:11px;">%s%s</span>',
			esc_html( implode( ' · ', $bits ) ),
			$badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped inside helper
		);
	}

	private static function badge_status( string $status ): string {
		$styles = array(
			PostNormalizer::STATUS_MODIFIED         => array(
				'bg' => '#00a32a',
				'fg' => '#fff',
				'label' => __( 'Modifié', '100son-html-normalizer' ),
			),
			PostNormalizer::STATUS_UNCHANGED        => array(
				'bg' => '#dcdcde',
				'fg' => '#1d2327',
				'label' => __( 'Inchangé', '100son-html-normalizer' ),
			),
			PostNormalizer::STATUS_SKIPPED_SO       => array(
				'bg' => '#f0b849',
				'fg' => '#1d2327',
				'label' => __( 'Refusé SO', '100son-html-normalizer' ),
			),
			PostNormalizer::STATUS_ERROR_NOT_FOUND  => array(
				'bg' => '#d63638',
				'fg' => '#fff',
				'label' => __( 'Introuvable', '100son-html-normalizer' ),
			),
			PostNormalizer::STATUS_ERROR_PERMISSION => array(
				'bg' => '#d63638',
				'fg' => '#fff',
				'label' => __( 'Permission', '100son-html-normalizer' ),
			),
			PostNormalizer::STATUS_ERROR_WRITE      => array(
				'bg' => '#d63638',
				'fg' => '#fff',
				'label' => __( 'Erreur', '100son-html-normalizer' ),
			),
			'updated'                               => array(
				'bg' => '#2271b1',
				'fg' => '#fff',
				'label' => __( 'Mise à jour', '100son-html-normalizer' ),
			),
		);
		$style = $styles[ $status ] ?? array(
			'bg' => '#dcdcde',
			'fg' => '#1d2327',
			'label' => $status,
		);
		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;background:%s;color:%s;border-radius:3px;font-size:11px;font-weight:600;">%s</span>',
			esc_attr( $style['bg'] ),
			esc_attr( $style['fg'] ),
			esc_html( $style['label'] )
		);
	}
}
