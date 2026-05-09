<?php
/**
 * RuleInterface — contrat commun à toutes les règles (préréglages + custom).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Toute règle de normalisation implémente cette interface.
 *
 * Une règle reçoit du HTML (string), retourne du HTML normalisé (string).
 * Les règles peuvent éventuellement opérer en interne sur un DOMDocument
 * partagé via `Pipeline::run_on_dom()` pour éviter les parse-roundtrips,
 * mais la signature contractuelle reste string→string.
 */
interface RuleInterface {

	/**
	 * Identifiant stable de la règle (ex. "P1", "P7", ou un UUID utilisateur).
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Libellé humain affiché en UI / CLI.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Applique la règle au HTML fourni.
	 *
	 * @param string               $html    HTML d'entrée.
	 * @param array<string, mixed> $context Contexte d'appel (cf. §4.4 et hyp. 20).
	 * @return string HTML normalisé (jamais null, jamais throw : en cas
	 *                d'échec interne, retourner $html inchangé).
	 */
	public function apply( string $html, array $context = array() ): string;

	/**
	 * Compte les correspondances que `apply()` traiterait sur le HTML fourni,
	 * SANS modifier la chaîne. Sert à F12 (DiagnosticEngine) pour qualifier
	 * un article `normal` / `to_improve` et à F14 (StepRunner) pour afficher
	 * la volumétrie attendue avant exécution.
	 *
	 * Contrat :
	 *  - retour ≥ 0 ;
	 *  - ne lève jamais — en cas de parse error, retourner 0 ;
	 *  - sémantique « ce que apply() supprimerait/transformerait », pas
	 *    « occurrences brutes du pattern ». Ex. P5 ne compte pas chaque
	 *    `<br>` mais chaque séquence ≥ N qui serait fusionnée.
	 *  - idempotence implicite : `countMatches(apply($html)) == 0` doit
	 *    être vrai pour les règles déterministes (cf. tests Phase 1).
	 *
	 * @param string               $html    HTML d'entrée.
	 * @param array<string, mixed> $context Contexte d'appel (mêmes clés que apply()).
	 * @return int<0, max>
	 */
	public function countMatches( string $html, array $context = array() ): int;
}
