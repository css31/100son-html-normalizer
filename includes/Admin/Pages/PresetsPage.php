<?php
/**
 * Page admin "Présets" — cocher/décocher et configurer les présets.
 *
 * V0.1 minimale : formulaire PHP classique avec POST handler. Sera remplacée
 * par la SPA React en phase 15 du §11.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin\Pages;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;

/**
 * Vue Présets.
 */
final class PresetsPage {

	private const NONCE_ACTION = 'son100_htmln_presets_save';
	private const NONCE_NAME   = '_son100_htmln_nonce';

	private SettingsRepository $settings;
	private PresetRegistry     $registry;

	public function __construct( SettingsRepository $settings, PresetRegistry $registry ) {
		$this->settings = $settings;
		$this->registry = $registry;
	}

	/**
	 * Render de la page (callback du sous-menu).
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', '100son-html-normalizer' ) );
		}

		$saved = $this->maybe_handle_save();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'HTML Normalizer — Présets', '100son-html-normalizer' ) . '</h1>';
		echo '<p>' . esc_html__( "Activez les règles de nettoyage à appliquer dans la pipeline. L'ordre d'exécution est : P3 → P4 → P8 → P6 → P7 → P5 → P1 → P2.", '100son-html-normalizer' ) . '</p>';

		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Configuration enregistrée.', '100son-html-normalizer' ) . '</p></div>';
		}

		$this->render_form();

		echo '</div>';
	}

	/**
	 * Traite le POST de sauvegarde si présent.
	 *
	 * @return bool True si une sauvegarde a eu lieu.
	 */
	private function maybe_handle_save(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return false;
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$presets   = $this->settings->get_all_presets();
		$post_data = isset( $_POST['preset'] ) && is_array( $_POST['preset'] )
			? wp_unslash( $_POST['preset'] )
			: [];

		foreach ( $this->registry->get_all_presets_metadata() as $preset_id => $_meta ) {
			$config              = $presets[ $preset_id ] ?? [];
			$config['enabled']   = ! empty( $post_data[ $preset_id ]['enabled'] );

			switch ( $preset_id ) {
				case 'P5':
					$threshold        = isset( $post_data['P5']['threshold'] ) ? (int) $post_data['P5']['threshold'] : 2;
					$config['threshold'] = max( 2, $threshold );
					break;
				case 'P6':
					$config['keep_text_align'] = ! empty( $post_data['P6']['keep_text_align'] );
					break;
				case 'P7':
					$threshold = isset( $post_data['P7']['threshold'] ) ? (int) $post_data['P7']['threshold'] : 2;
					$config['threshold'] = max( 2, $threshold );
					$config['markers']   = [
						'dash'    => ! empty( $post_data['P7']['markers']['dash'] ),
						'emdash'  => ! empty( $post_data['P7']['markers']['emdash'] ),
						'asterix' => ! empty( $post_data['P7']['markers']['asterix'] ),
						'bullet'  => ! empty( $post_data['P7']['markers']['bullet'] ),
						'numeric' => ! empty( $post_data['P7']['markers']['numeric'] ),
					];
					$raw_custom               = isset( $post_data['P7']['custom_markers'] )
						? sanitize_textarea_field( (string) $post_data['P7']['custom_markers'] )
						: '';
					$config['custom_markers'] = array_values(
						array_filter(
							array_map( 'trim', explode( "\n", $raw_custom ) ),
							static fn( string $m ): bool => '' !== $m
						)
					);
					break;
				case 'P8':
					$config['mappings'] = [
						'bold'   => ! empty( $post_data['P8']['mappings']['bold'] ),
						'italic' => ! empty( $post_data['P8']['mappings']['italic'] ),
					];
					break;
			}

			$this->settings->set_preset_config( $preset_id, $config );
		}

		return true;
	}

	/**
	 * Render du formulaire des présets.
	 *
	 * @return void
	 */
	private function render_form(): void {
		$presets  = $this->settings->get_all_presets();
		$metadata = $this->registry->get_all_presets_metadata();

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<table class="form-table" role="presentation">';
		echo '<tbody>';

		foreach ( $metadata as $preset_id => $meta ) {
			$config  = $presets[ $preset_id ] ?? [];
			$enabled = ! empty( $config['enabled'] );

			echo '<tr>';
			echo '<th scope="row">' . esc_html( $preset_id ) . ' — ' . esc_html( $meta['label'] ) . '</th>';
			echo '<td>';

			printf(
				'<label><input type="checkbox" name="preset[%1$s][enabled]" value="1" %2$s> %3$s</label>',
				esc_attr( $preset_id ),
				checked( $enabled, true, false ),
				esc_html__( 'Activé', '100son-html-normalizer' )
			);

			if ( $meta['has_options'] ) {
				echo '<div style="margin-top:8px;padding-left:24px;">';
				$this->render_preset_options( $preset_id, $config );
				echo '</div>';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		submit_button( __( 'Enregistrer', '100son-html-normalizer' ) );
		echo '</form>';
	}

	/**
	 * Render des sous-paramètres d'un préset.
	 *
	 * @param string               $preset_id Identifiant.
	 * @param array<string, mixed> $config    Configuration courante.
	 * @return void
	 */
	private function render_preset_options( string $preset_id, array $config ): void {
		switch ( $preset_id ) {
			case 'P5':
				$threshold = isset( $config['threshold'] ) ? (int) $config['threshold'] : 2;
				printf(
					'<label>%s <input type="number" name="preset[P5][threshold]" value="%d" min="2" max="20" style="width:60px;"></label>',
					esc_html__( 'Seuil (≥ 2) :', '100son-html-normalizer' ),
					(int) $threshold
				);
				break;

			case 'P6':
				$keep = ! isset( $config['keep_text_align'] ) || (bool) $config['keep_text_align'];
				printf(
					'<label><input type="checkbox" name="preset[P6][keep_text_align]" value="1" %s> %s</label>',
					checked( $keep, true, false ),
					esc_html__( 'Conserver text-align (sinon strip total)', '100son-html-normalizer' )
				);
				break;

			case 'P7':
				$threshold = isset( $config['threshold'] ) ? (int) $config['threshold'] : 2;
				$markers   = isset( $config['markers'] ) && is_array( $config['markers'] ) ? $config['markers'] : [];
				$custom    = isset( $config['custom_markers'] ) && is_array( $config['custom_markers'] )
					? implode( "\n", array_map( 'strval', $config['custom_markers'] ) )
					: '';

				printf(
					'<p><label>%s <input type="number" name="preset[P7][threshold]" value="%d" min="2" max="20" style="width:60px;"></label></p>',
					esc_html__( 'Seuil (≥ 2) :', '100son-html-normalizer' ),
					(int) $threshold
				);

				$marker_labels = [
					'dash'    => '- (tiret ASCII)',
					'emdash'  => '– (cadratin)',
					'asterix' => '* (astérisque)',
					'bullet'  => '• (puce)',
					'numeric' => '1. 2. 3. (numéros → <ol>)',
				];
				echo '<p>' . esc_html__( 'Marqueurs activés :', '100son-html-normalizer' ) . '</p>';
				echo '<ul style="margin:4px 0 8px 0;">';
				foreach ( $marker_labels as $key => $label ) {
					$checked = ! empty( $markers[ $key ] );
					printf(
						'<li><label><input type="checkbox" name="preset[P7][markers][%s]" value="1" %s> %s</label></li>',
						esc_attr( $key ),
						checked( $checked, true, false ),
						esc_html( $label )
					);
				}
				echo '</ul>';

				printf(
					'<p><label>%s<br><textarea name="preset[P7][custom_markers]" rows="3" cols="40">%s</textarea><br><span class="description">%s</span></label></p>',
					esc_html__( 'Marqueurs custom (1 par ligne) :', '100son-html-normalizer' ),
					esc_textarea( $custom ),
					esc_html__( 'Ex. ▸  ou  ► — un par ligne. Toujours produit <ul>.', '100son-html-normalizer' )
				);
				break;

			case 'P8':
				$mappings = isset( $config['mappings'] ) && is_array( $config['mappings'] ) ? $config['mappings'] : [];
				$bold     = ! isset( $mappings['bold'] ) || (bool) $mappings['bold'];
				$italic   = ! isset( $mappings['italic'] ) || (bool) $mappings['italic'];
				echo '<p>' . esc_html__( 'Mappings sémantiques activés :', '100son-html-normalizer' ) . '</p>';
				printf(
					'<p><label><input type="checkbox" name="preset[P8][mappings][bold]" value="1" %s> %s</label></p>',
					checked( $bold, true, false ),
					esc_html__( 'font-weight: bold (ou ≥ 700) → <strong>', '100son-html-normalizer' )
				);
				printf(
					'<p><label><input type="checkbox" name="preset[P8][mappings][italic]" value="1" %s> %s</label></p>',
					checked( $italic, true, false ),
					esc_html__( 'font-style: italic → <em>', '100son-html-normalizer' )
				);
				break;
		}
	}
}
