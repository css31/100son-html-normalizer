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
	public function apply( string $html, array $context = [] ): string;
}
