<?php
/**
 * DiagnosticInvalidator — invalide le diagnostic d'un post au save_post.
 *
 * Cf. cahier v2.0 §3.1 F12 (invalidation), §4.3, §11.20 et §13 garde-fou
 * « non bloquant ».
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Throwable;
use WP_Post;

/**
 * Branche `save_post` à priorité 999 et marque le diagnostic du post comme
 * `is_stale=1` si présent dans `son100_htmln_diagnostics`.
 *
 * Sémantique :
 *  - Pas de recalcul automatique (cf. cahier §3.1 F12, hyp. Q54a) — uniquement
 *    invalidation. Le recalcul est déclenché manuellement par la SPA ou par
 *    un pas F14.
 *  - Filtre les sauvegardes non significatives : révisions, autosaves, non
 *    publish, post_types hors F8.
 *  - Non bloquant : tout `Throwable` côté repo est attrapé silencieusement.
 *    L'utilisateur de Gutenberg ne doit jamais voir un échec d'invalidation.
 *  - O(1) (UPDATE indexé sur `post_id`).
 */
final class DiagnosticInvalidator {

	/**
	 * Priorité du hook (élevée pour que tous les autres handlers aient déjà
	 * tourné quand on invalide).
	 */
	public const HOOK_PRIORITY = 999;

	/**
	 * @param DiagnosticsRepository $repository Persistance des diagnostics.
	 * @param SettingsRepository    $settings   Source du filtre F8 post_types.
	 */
	public function __construct(
		private readonly DiagnosticsRepository $repository,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * Branche le hook `save_post`. Idempotent côté WP (l'ajout d'un même
	 * callable au même hook + priorité est déduplé par `add_action`).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), self::HOOK_PRIORITY, 2 );
	}

	/**
	 * Handler du hook `save_post`. Marque is_stale=1 si le post est éligible.
	 *
	 * Aucune valeur de retour : WordPress ignore le retour des actions.
	 *
	 * @param int          $post_id ID du post sauvegardé.
	 * @param WP_Post|null $post    Objet post (peut être null en théorie selon WP).
	 * @return void
	 */
	public function on_save_post( int $post_id, ?WP_Post $post = null ): void {
		// 1. Skip révisions et autosaves : ce ne sont pas des changements significatifs.
		if ( false !== wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( false !== wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// 2. Le hook peut être appelé sans WP_Post dans certains chemins ; on s'en méfie.
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// 3. Scope : posts publics uniquement (pas drafts, ni trash, ni autres statuses).
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// 4. Scope : post_types listés dans F8 settings.
		$allowed_types = $this->settings->get_f8_post_types_selection();
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		// 5. Tentative d'invalidation, non bloquante.
		try {
			$this->repository->mark_stale_for_post( $post_id );
		} catch ( Throwable $e ) {
			// Non-bloquant : si la BDD est indisponible, l'utilisateur de Gutenberg
			// ne doit pas voir d'erreur. On log silencieusement via error_log.
			error_log( sprintf(
				'[100son-html-normalizer] DiagnosticInvalidator failed for post %d: %s',
				$post_id,
				$e->getMessage()
			) );
		}
	}
}
