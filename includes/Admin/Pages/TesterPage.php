<?php
/**
 * Page admin "Tester un fragment".
 *
 * Textarea + bouton "Normaliser" + affichage avant/après. Utile pour valider
 * la configuration des préréglages sur du HTML réel sans toucher d'article.
 *
 * V0.1 minimale (POST handler classique). Sera complétée par la SPA + REST
 * en phase 11/15 du §11.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin\Pages;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;

/**
 * Vue Tester.
 */
final class TesterPage {

	private const NONCE_ACTION = 'son100_htmln_tester_run';
	private const NONCE_NAME   = '_son100_htmln_nonce';
	private const SAMPLE_LIMIT = 51200; // 50 Ko (cf. cahier §7.1).

	private HtmlNormalizer $normalizer;

	public function __construct( HtmlNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Render de la page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', '100son-html-normalizer' ) );
		}

		[ $input, $output, $error ] = $this->maybe_handle_run();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'HTML Normalizer — Tester un fragment', '100son-html-normalizer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Collez un fragment HTML pour voir le résultat avec votre configuration de préréglages. Limité à 50 Ko.', '100son-html-normalizer' ) . '</p>';

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		echo '<form method="post" action="">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<p><label for="son100_htmln_input"><strong>' . esc_html__( 'HTML d\'entrée', '100son-html-normalizer' ) . '</strong></label></p>';
		printf(
			'<p><textarea id="son100_htmln_input" name="son100_htmln_input" rows="12" cols="80" style="width:100%%;font-family:monospace;">%s</textarea></p>',
			esc_textarea( $input )
		);
		submit_button( __( 'Normaliser', '100son-html-normalizer' ) );
		echo '</form>';

		if ( null !== $output ) {
			echo '<hr>';
			echo '<h2>' . esc_html__( 'Résultat', '100son-html-normalizer' ) . '</h2>';

			echo '<h3>' . esc_html__( 'HTML normalisé (source)', '100son-html-normalizer' ) . '</h3>';
			printf(
				'<pre style="background:#f6f7f7;padding:12px;border:1px solid #c3c4c7;overflow:auto;max-height:300px;">%s</pre>',
				esc_html( $output )
			);

			echo '<h3>' . esc_html__( 'Aperçu rendu', '100son-html-normalizer' ) . '</h3>';
			echo '<div style="background:#fff;padding:12px;border:1px solid #c3c4c7;">';
			// Aperçu rendu : le HTML est sorti tel quel pour que l'admin voie
			// visuellement le résultat. wp_kses_post pour garde-fou XSS minimum.
			echo wp_kses_post( $output ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Traite le POST si présent.
	 *
	 * @return array{0: string, 1: string|null, 2: string} Tuple [input, output|null, error].
	 */
	private function maybe_handle_run(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return array( '', null, '' );
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return array( '', null, '' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$raw_input = isset( $_POST['son100_htmln_input'] )
			? wp_unslash( (string) $_POST['son100_htmln_input'] )
			: '';

		if ( strlen( $raw_input ) > self::SAMPLE_LIMIT ) {
			return array( $raw_input, null, __( 'Fragment trop volumineux (max 50 Ko).', '100son-html-normalizer' ) );
		}

		$output = $this->normalizer->normalize( $raw_input, array( 'source' => 'admin-tester' ) );

		return array( $raw_input, $output, '' );
	}
}
