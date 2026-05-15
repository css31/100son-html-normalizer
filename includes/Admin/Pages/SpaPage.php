<?php
/**
 * Page admin SPA — conteneur racine de l'interface React V1.0.
 *
 * Cf. cahier v2.0 §11.26 (SPA admin enrichie). Phase 6.1 : la page se
 * limite à rendre le conteneur `<div id="htmln-app">` ; le bundle JS
 * monté par `Admin\Assets` consomme ce conteneur et y monte le composant
 * `<App />`. Les vues métier (Normalize F13/F14, StepsHistory F16,
 * Settings γ) sont implémentées côté React en Phases 6.3 à 6.7.
 *
 * Coexistence V1.0 : cette page est ajoutée au menu en parallèle des
 * pages V0.1 PHP classiques (Règles, Tester, Normaliser, Journal).
 * La migration complète de l'UI vers la SPA est différée V1.1.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Vue racine de la SPA — render minimal.
 */
final class SpaPage {

	/**
	 * Render de la page : un wrapper `.wrap` natif WordPress + le conteneur
	 * racine de la SPA. Aucun JS injecté ici — c'est `Admin\Assets` qui
	 * branche le bundle conditionnellement au hook_suffix.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', '100son-html-normalizer' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'HTML Normalizer — Normalisation par lots (V1.0)', '100son-html-normalizer' ) . '</h1>';
		// Conteneur consommé par `assets/src/admin-spa/index.js`. Vide tant
		// que le bundle React n'est pas chargé — l'utilisateur voit alors
		// simplement le titre, signal explicite que les assets manquent.
		echo '<div id="htmln-app"></div>';
		echo '</div>';
	}
}
