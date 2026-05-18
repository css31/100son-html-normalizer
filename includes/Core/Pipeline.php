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

use Cent_Son\Html_Normalizer\Core\Rules\BuilderScopedRule;
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
	 * Si `$context['builder_type']` est fourni (typiquement injecte par
	 * `StepRunner::process_article()` apres classification par
	 * `BuilderClassifier`), les regles implementant `BuilderScopedRule`
	 * dont la liste `excluded_builder_types()` contient ce type sont
	 * silencieusement skippees (pas de warning, pas d'ecriture). Voir
	 * l'interface pour la motivation (Gutenberg invariants).
	 *
	 * @param list<RuleInterface>  $rules    Regles a appliquer (dans l'ordre).
	 * @param string               $html     HTML d'entree.
	 * @param array<string, mixed> $context  Contexte d'appel (transmis a chaque regle).
	 * @param list<string>         $warnings Reference : warnings collectees (output).
	 * @return string HTML normalise.
	 */
	public function run( array $rules, string $html, array $context = array(), array &$warnings = array() ): string {
		$builder_type = isset( $context['builder_type'] ) && is_string( $context['builder_type'] )
			? $context['builder_type']
			: null;
		$current = $html;
		foreach ( $rules as $rule ) {
			if ( null !== $builder_type && self::is_excluded_for_builder( $rule, $builder_type ) ) {
				continue;
			}
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

	/**
	 * Applique uniquement le sous-ensemble de regles dont l'identifiant figure
	 * dans `$rule_ids`, en respectant l'ordre de `$rules` (typiquement l'ordre
	 * canonique PRESETS).
	 *
	 * Utilise par F14 (application par pas) : pour un article, l'utilisateur
	 * choisit N regles a appliquer en sequence isolee, sans toucher aux autres.
	 *
	 * Contrat :
	 *  - `$rule_ids` peut etre dans n'importe quel ordre, le filtrage respecte
	 *    l'ordre de `$rules` ;
	 *  - les ids inconnus dans `$rule_ids` sont silencieusement ignores ;
	 *  - sous-ensemble vide => `$html` retourne tel quel (no-op) ;
	 *  - delegate au pipeline standard pour la robustesse (try/catch, warnings).
	 *
	 * @param list<RuleInterface>  $rules    Toutes les regles disponibles (typiquement get_enabled_rules).
	 * @param list<string>         $rule_ids Identifiants a appliquer parmi `$rules`.
	 * @param string               $html     HTML d'entree.
	 * @param array<string, mixed> $context  Contexte d'appel.
	 * @param list<string>         $warnings Reference : warnings collectees.
	 * @return string HTML normalise apres application du sous-ensemble.
	 */
	public function applySubset( array $rules, array $rule_ids, string $html, array $context = array(), array &$warnings = array() ): string {
		if ( array() === $rule_ids ) {
			return $html;
		}
		$wanted = array_flip( array_map( 'strval', $rule_ids ) );
		$subset = array();
		foreach ( $rules as $rule ) {
			if ( isset( $wanted[ $rule->id() ] ) ) {
				$subset[] = $rule;
			}
		}
		if ( array() === $subset ) {
			return $html;
		}
		return $this->run( $subset, $html, $context, $warnings );
	}

	/**
	 * Indique si une regle doit etre ecartee pour le `builder_type` cible.
	 *
	 * Source de verite : `BuilderScopedRule::excluded_builder_types()`. Les
	 * regles n'implementant pas l'interface s'appliquent partout (retour
	 * `false`).
	 *
	 * @param RuleInterface $rule         Regle a tester.
	 * @param string        $builder_type Type d'article (constante
	 *                                    `BuilderClassifier::TYPE_*`).
	 * @return bool
	 */
	private static function is_excluded_for_builder( RuleInterface $rule, string $builder_type ): bool {
		if ( ! $rule instanceof BuilderScopedRule ) {
			return false;
		}
		return in_array( $builder_type, $rule->excluded_builder_types(), true );
	}
}
