<?php
/**
 * UserRulesRepository — CRUD des règles custom utilisateur.
 *
 * Schéma de stockage cf. §4.2 du cahier (option `son100_htmln_rules_user`).
 * Aucune validation ici : c'est le rôle de RuleValidator (étape 9 §11).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Repository des règles custom (mode simple / avancé).
 */
final class UserRulesRepository {

	private const OPT_RULES = 'son100_htmln_rules_user';

	/**
	 * Liste toutes les règles persistées (ordre de stockage).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		$rules = get_option( self::OPT_RULES, [] );
		if ( ! is_array( $rules ) ) {
			return [];
		}
		/** @var list<array<string, mixed>> $rules */
		return array_values( $rules );
	}

	/**
	 * Liste les règles activées, triées par label alphabétique (cf. §4.4 pipeline).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function enabled_sorted(): array {
		$rules = array_filter(
			$this->all(),
			static fn( array $rule ): bool => ! empty( $rule['enabled'] )
		);

		usort(
			$rules,
			static function ( array $a, array $b ): int {
				$la = (string) ( $a['label'] ?? '' );
				$lb = (string) ( $b['label'] ?? '' );
				return strcasecmp( $la, $lb );
			}
		);

		return array_values( $rules );
	}

	/**
	 * Récupère une règle par son UUID.
	 *
	 * @param string $id UUID de la règle.
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->all() as $rule ) {
			if ( ( $rule['id'] ?? null ) === $id ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Crée une nouvelle règle (UUID supposé déjà généré et non collisionnant).
	 *
	 * @param array<string, mixed> $rule Règle complète.
	 * @return void
	 */
	public function create( array $rule ): void {
		$rules   = $this->all();
		$rules[] = $rule;
		update_option( self::OPT_RULES, $rules, false );
	}

	/**
	 * Met à jour une règle existante (par id). Aucune action si id inconnu.
	 *
	 * @param string               $id          UUID.
	 * @param array<string, mixed> $replacement Règle complète remplaçante.
	 * @return bool True si la règle a été trouvée et mise à jour.
	 */
	public function update( string $id, array $replacement ): bool {
		$rules = $this->all();
		foreach ( $rules as $key => $rule ) {
			if ( ( $rule['id'] ?? null ) === $id ) {
				$rules[ $key ] = $replacement;
				update_option( self::OPT_RULES, $rules, false );
				return true;
			}
		}
		return false;
	}

	/**
	 * Supprime une règle par UUID.
	 *
	 * @param string $id UUID.
	 * @return bool True si la règle a été trouvée et supprimée.
	 */
	public function delete( string $id ): bool {
		$rules = $this->all();
		foreach ( $rules as $key => $rule ) {
			if ( ( $rule['id'] ?? null ) === $id ) {
				unset( $rules[ $key ] );
				update_option( self::OPT_RULES, array_values( $rules ), false );
				return true;
			}
		}
		return false;
	}

	/**
	 * Remplace toute la bibliothèque (mode replace import).
	 *
	 * @param list<array<string, mixed>> $rules Nouvelle bibliothèque.
	 * @return void
	 */
	public function replace_all( array $rules ): void {
		update_option( self::OPT_RULES, array_values( $rules ), false );
	}
}
