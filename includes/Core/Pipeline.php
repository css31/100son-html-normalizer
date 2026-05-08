<?php
/**
 * Pipeline — applique en cascade une liste de regles a un fragment HTML.
 *
 * Cf. cahier section 4.4.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;

/**
 * Application en cascade de regles.
 */
final class Pipeline {

	/**
	 * Applique successivement les regles donnees au HTML.
	 *
	 * Chaque regle est appelee avec la sortie de la precedente. En cas
	 * d'exception lancee par une regle, elle est attrapee : la regle est
	 * "skip" et l'exception loggee dans le tableau $warnings (passe-plat).
	 *
	 * @param list<RuleInterface>  $rules    Regles a appliquer (dans l'ordre).
	 * @param string               $html     HTML d'entree.
	 * @param array<string, mixed> $context  Contexte d'appel (transmis a chaque regle).
	 * @param list<string>         $warnings Reference : warnings collectees (output).
	 * @return string HTML normalise.
	 */
	public function run( array $rules, string $html, array $context = [], array &$warnings = [] ): string {
		$current = $html;
		foreach ( $rules as $rule ) {
			try {
				$result = $rule->apply( $current, $context );
				// Garde-fou : une regle DOIT retourner une string.
				if ( ! is_string( $result ) ) {
					$warnings[] = sprintf(
						'Rule %s returned non-string output, skipping.',
						$rule->id()
					);
					continue;
				}
				$current = $result;
			} catch ( \Throwable $e ) {
				$warnings[] = sprintf(
					'Rule %s threw exception: %s',
					$rule->id(),
					$e->getMessage()
				);
				// On continue avec la sortie precedente intacte.
			}
		}
		return $current;
	}
}
