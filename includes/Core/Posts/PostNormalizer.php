<?php
/**
 * PostNormalizer — applique la normalisation au post_content d'un article.
 *
 * Garde-fous (cf. cahier §13 et §14 hyp. 16, 21) :
 *  - Crée systématiquement une révision WP avant écriture (rollback natif).
 *  - Refuse les articles SiteOrigin sauf flag explicite `force_siteorigin`.
 *  - Si le HTML normalisé est identique à l'original : aucun écriture, statut 'unchanged'.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Posts;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;
use Cent_Son\Html_Normalizer\Core\Logs\Logger;

/**
 * Service de normalisation au niveau article WP.
 */
final class PostNormalizer {

	public const STATUS_MODIFIED         = 'modified';
	public const STATUS_UNCHANGED        = 'unchanged';
	public const STATUS_SKIPPED_SO       = 'skipped_siteorigin';
	public const STATUS_ERROR_NOT_FOUND  = 'error_not_found';
	public const STATUS_ERROR_PERMISSION = 'error_permission';
	public const STATUS_ERROR_WRITE      = 'error_write';

	private HtmlNormalizer    $normalizer;
	private SiteOriginDetector $so_detector;
	private ?Logger            $logger;

	public function __construct( HtmlNormalizer $normalizer, SiteOriginDetector $so_detector, ?Logger $logger = null ) {
		$this->normalizer  = $normalizer;
		$this->so_detector = $so_detector;
		$this->logger      = $logger;
	}

	/**
	 * Aperçu : applique la normalisation au post_content sans aucune écriture.
	 *
	 * @param int $post_id ID du post.
	 * @return array{
	 *     status: string,
	 *     html_before: string,
	 *     html_after: string,
	 *     has_panels_data: bool,
	 *     message?: string
	 * }
	 */
	public function preview( int $post_id ): array {
		$post = $this->fetch_post( $post_id );
		if ( null === $post ) {
			$this->logger?->log_preview( $post_id, '', self::STATUS_ERROR_NOT_FOUND );
			return [
				'status'          => self::STATUS_ERROR_NOT_FOUND,
				'html_before'     => '',
				'html_after'      => '',
				'has_panels_data' => false,
				'message'         => __( "Article introuvable.", '100son-html-normalizer' ),
			];
		}

		$has_so      = $this->so_detector->has_panels_data( $post_id );
		$html_before = (string) $post->post_content;
		$html_after  = $this->normalizer->normalize( $html_before, [ 'source' => 'admin-f8', 'post_id' => $post_id ] );
		$status      = $html_before === $html_after ? self::STATUS_UNCHANGED : self::STATUS_MODIFIED;

		$this->logger?->log_preview( $post_id, (string) $post->post_title, $status );

		return [
			'status'          => $status,
			'html_before'     => $html_before,
			'html_after'      => $html_after,
			'has_panels_data' => $has_so,
		];
	}

	/**
	 * Normalise un article et écrit le résultat (avec création de révision).
	 *
	 * @param int  $post_id           ID du post.
	 * @param bool $force_siteorigin  True pour outrepasser le refus SiteOrigin.
	 * @return array{
	 *     status: string,
	 *     message?: string,
	 *     revision_id?: int,
	 *     has_panels_data: bool
	 * }
	 */
	public function normalize_post( int $post_id, bool $force_siteorigin = false ): array {
		$post = $this->fetch_post( $post_id );
		if ( null === $post ) {
			$this->logger?->log_normalize( $post_id, '', self::STATUS_ERROR_NOT_FOUND, __( "Article introuvable.", '100son-html-normalizer' ) );
			return [
				'status'          => self::STATUS_ERROR_NOT_FOUND,
				'message'         => __( "Article introuvable.", '100son-html-normalizer' ),
				'has_panels_data' => false,
			];
		}

		$post_title = (string) $post->post_title;
		$has_so     = $this->so_detector->has_panels_data( $post_id );
		if ( $has_so && ! $force_siteorigin ) {
			$skip_msg = __( "Article SiteOrigin détecté. Cocher « Continuer quand même » pour outrepasser, ou utiliser SO to Blocks pour la migration.", '100son-html-normalizer' );
			$this->logger?->log_normalize( $post_id, $post_title, self::STATUS_SKIPPED_SO, $skip_msg );
			return [
				'status'          => self::STATUS_SKIPPED_SO,
				'message'         => $skip_msg,
				'has_panels_data' => true,
			];
		}

		$html_before = (string) $post->post_content;
		$html_after  = $this->normalizer->normalize( $html_before, [ 'source' => 'admin-f8', 'post_id' => $post_id ] );

		if ( $html_before === $html_after ) {
			$unchanged_msg = __( "Aucune modification : le HTML est déjà conforme.", '100son-html-normalizer' );
			$this->logger?->log_normalize( $post_id, $post_title, self::STATUS_UNCHANGED, $unchanged_msg );
			return [
				'status'          => self::STATUS_UNCHANGED,
				'message'         => $unchanged_msg,
				'has_panels_data' => $has_so,
			];
		}

		// Crée une révision AVANT écriture pour rollback natif WP.
		$revision_id = 0;
		if ( function_exists( 'wp_save_post_revision' ) ) {
			$rev = wp_save_post_revision( $post_id );
			if ( is_int( $rev ) && $rev > 0 ) {
				$revision_id = $rev;
			}
		}

		$update_result = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $html_after,
			],
			true
		);

		if ( is_wp_error( $update_result ) || 0 === $update_result ) {
			$msg = is_wp_error( $update_result ) ? $update_result->get_error_message() : __( 'Échec de la mise à jour.', '100son-html-normalizer' );
			$this->logger?->log_normalize( $post_id, $post_title, self::STATUS_ERROR_WRITE, (string) $msg );
			return [
				'status'          => self::STATUS_ERROR_WRITE,
				'message'         => (string) $msg,
				'has_panels_data' => $has_so,
			];
		}

		$this->logger?->log_normalize( $post_id, $post_title, self::STATUS_MODIFIED, '', $revision_id );
		return [
			'status'          => self::STATUS_MODIFIED,
			'revision_id'     => $revision_id,
			'has_panels_data' => $has_so,
		];
	}

	/**
	 * Récupère un post sans wrapper.
	 *
	 * @param int $post_id ID.
	 * @return \WP_Post|null
	 */
	private function fetch_post( int $post_id ): ?\WP_Post {
		if ( ! function_exists( 'get_post' ) ) {
			return null;
		}
		$post = get_post( $post_id );
		return ( $post instanceof \WP_Post ) ? $post : null;
	}
}
