<?php
/**
 * SiteOriginDetector — détecte si un article est issu de SiteOrigin Page Builder.
 *
 * Cf. cahier §14 hyp. 21 : « Heuristique de détection : `get_post_meta($id, 'panels_data', true)`
 * non vide ET non `null` ».
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Détection des articles SiteOrigin.
 */
final class SiteOriginDetector {

	/**
	 * Indique si un article a une structure SiteOrigin Page Builder.
	 *
	 * @param int $post_id Identifiant du post.
	 * @return bool
	 */
	public function has_panels_data( int $post_id ): bool {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		$panels_data = get_post_meta( $post_id, 'panels_data', true );
		if ( null === $panels_data || '' === $panels_data ) {
			return false;
		}
		// Le meta `panels_data` peut être un tableau (cas typique) ou une string sérialisée.
		if ( is_array( $panels_data ) ) {
			return ! empty( $panels_data );
		}
		return false !== $panels_data;
	}
}
