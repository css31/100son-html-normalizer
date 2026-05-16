<?php
/**
 * NotesController — endpoints REST de la note libre riche SPA V1.0.
 *
 * Cf. ajout V1.0 post-rc1 : onglet « Notes » de la SPA (édition Gutenberg
 * restreinte). Cohabite avec la zone de notes plain-text de la page Journal
 * V0.1 (différent repo, différente option) — cf. RichNotesRepository docblock.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Notes\RichNotesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surface REST de la note libre riche.
 *
 * Routes (namespace `htmln/v1`) :
 *
 *  - `GET    /notes` — `{ content: string }`. Le contenu est de la *block
 *    grammar* Gutenberg brute (commentaires `<!-- wp:* -->` inclus) que la
 *    SPA passe à `parse()` côté client.
 *  - `PUT    /notes` — body `{ content: string }`, retourne `{ content }`
 *    après sanitization serveur (`wp_kses_post`). Le serveur est l'autorité :
 *    si la sanitization a modifié quelque chose, la SPA récupère la version
 *    persistée pour resynchroniser l'éditeur (évite la dérive client/serveur).
 *  - `DELETE /notes` — vide la note, retourne `{ content: '' }`.
 *
 * Toutes les routes : capability `manage_options` (cf. cahier §14 hyp. 14 —
 * homogène avec le reste de la REST V1.0).
 *
 * Pourquoi `PUT` et pas `POST` : la note est une ressource singleton qu'on
 * remplace intégralement. `PUT` rend explicite l'idempotence côté API et
 * évite de suggérer une sémantique de création multi-instance (qui serait
 * trompeuse — il n'y a qu'une note).
 */
final class NotesController extends BaseController {

	/**
	 * @param RichNotesRepository $notes Repo de la note riche.
	 */
	public function __construct(
		private readonly RichNotesRepository $notes,
	) {}

	/**
	 * Enregistre la route unique `/notes` (3 méthodes GET/PUT/DELETE).
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$cap       = array( $this, 'permission_check_manage_options' );
		$can_write = array( $this, 'permission_check_locked' );

		register_rest_route( self::REST_NAMESPACE, '/notes', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_notes' ),
				'permission_callback' => $cap,
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_notes' ),
				'permission_callback' => $can_write,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_notes' ),
				'permission_callback' => $can_write,
			),
		) );
	}

	/**
	 * `GET /notes`
	 *
	 * Réponse 200 : `{ content: string }`. Chaîne vide acceptée (état
	 * « jamais saisi » ou « vidé »).
	 *
	 * @param WP_REST_Request $request Requête (inutilisée — pas de paramètres).
	 * @return WP_REST_Response
	 */
	public function get_notes( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return $this->respond( array(
			'content' => $this->notes->get(),
		) );
	}

	/**
	 * `PUT /notes`
	 *
	 * Body : `{ content: string }`. Si `content` est absent ou non-scalaire,
	 * 400 ; un body `{ content: "" }` est un cas légitime (équivaut à clear,
	 * mais en sémantique « j'ai vidé l'éditeur et je sauvegarde »).
	 *
	 * Réponse 200 : `{ content: string }` après sanitization serveur.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function update_notes( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_param( 'content' );
		if ( ! is_string( $payload ) ) {
			return $this->rest_error(
				'invalid_content',
				'content must be a string',
				400,
			);
		}
		$this->notes->set( $payload );
		return $this->respond( array(
			'content' => $this->notes->get(),
		) );
	}

	/**
	 * `DELETE /notes`
	 *
	 * Réponse 200 : `{ content: '' }`. On préfère 200 + payload à 204 pour
	 * homogénéité avec PUT — la SPA peut câbler la même handler de retour.
	 *
	 * @param WP_REST_Request $request Requête (inutilisée).
	 * @return WP_REST_Response
	 */
	public function delete_notes( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$this->notes->clear();
		return $this->respond( array(
			'content' => '',
		) );
	}
}
