<?php
/**
 * PresetRegistry — declare l'ordre du pipeline des presets et leur instanciation.
 *
 * Cf. cahier section 4.4 (ordre P3 -> P4 -> P8 -> P6 -> P7 -> P5 -> P1 -> P2)
 * et section 14 hyp. 10 (justifications).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Registry;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Rules\AsciiListRule;
use Cent_Son\Html_Normalizer\Core\Rules\EmptyHeadingsRule;
use Cent_Son\Html_Normalizer\Core\Rules\EmptyParagraphsRule;
use Cent_Son\Html_Normalizer\Core\Rules\ExcessiveBrRule;
use Cent_Son\Html_Normalizer\Core\Rules\PinterestArtifactsRule;
use Cent_Son\Html_Normalizer\Core\Rules\RecoverSemanticStylesRule;
use Cent_Son\Html_Normalizer\Core\Rules\RemoveInlineStylesRule;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Core\Rules\ShareaholicShortcodeRule;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;

/**
 * Registry des presets de normalisation.
 *
 * Volontairement non-final pour permettre l'extension/stub en tests
 * d'intégration (DiagnosticEngineTest, StepRunnerTest…). Même convention
 * que `SettingsRepository`. Ne pas la rendre `final` sans extraire au
 * préalable une interface dédiée.
 */
class PresetRegistry {

	/**
	 * Ordre canonique des presets dans le pipeline (cf. cahier 4.4).
	 *
	 * @var list<string>
	 */
	public const PRESETS = array( 'P3', 'P4', 'P8', 'P6', 'P7', 'P5', 'P1', 'P2' );

	/**
	 * Repository de configuration des presets.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Construit la liste des regles ACTIVEES, dans l'ordre du pipeline.
	 *
	 * @return list<RuleInterface>
	 */
	public function get_enabled_rules(): array {
		$rules = array();
		foreach ( self::PRESETS as $preset_id ) {
			if ( ! $this->settings->is_preset_enabled( $preset_id ) ) {
				continue;
			}
			$rule = $this->build_rule( $preset_id );
			if ( null !== $rule ) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * Construit la liste des regles ACTIVEES dont l'identifiant figure dans
	 * `$rule_ids`, en respectant l'ordre canonique `PRESETS`.
	 *
	 * Utilise par `Pipeline::applySubset()` (F14 — application par pas) et
	 * `DiagnosticEngine` (F12 — quel sous-ensemble qualifierait cet article ?).
	 *
	 * Contrat :
	 *  - `$rule_ids` peut etre dans n'importe quel ordre, le retour suit
	 *    toujours l'ordre `PRESETS` ;
	 *  - les `rule_ids` inconnus sont silencieusement ignores (pas d'erreur) ;
	 *  - les regles ACTIVEES par configuration utilisateur seulement sont
	 *    instanciees — un preset desactive globalement ne sort pas du subset
	 *    meme s'il est demande explicitement (alignement avec `get_enabled_rules`).
	 *
	 * @param list<string> $rule_ids Identifiants demandes.
	 * @return list<RuleInterface>
	 */
	public function get_rules_for_subset( array $rule_ids ): array {
		if ( array() === $rule_ids ) {
			return array();
		}
		$wanted = array_flip( array_map( 'strval', $rule_ids ) );
		$rules  = array();
		foreach ( self::PRESETS as $preset_id ) {
			if ( ! isset( $wanted[ $preset_id ] ) ) {
				continue;
			}
			if ( ! $this->settings->is_preset_enabled( $preset_id ) ) {
				continue;
			}
			$rule = $this->build_rule( $preset_id );
			if ( null !== $rule ) {
				$rules[] = $rule;
			}
		}
		return $rules;
	}

	/**
	 * Liste tous les presets connus (independamment de leur etat).
	 * Utilise pour l'UI Presets.
	 *
	 * @return array<string, array{label: string, description: string, has_options: bool}>
	 */
	public function get_all_presets_metadata(): array {
		return array(
			'P1' => array(
				'label'       => __( 'Paragraphes vides', '100son-html-normalizer' ),
				'description' => __( 'Supprime les <code>&lt;p&gt;&lt;/p&gt;</code>, <code>&lt;p&gt;&amp;nbsp;&lt;/p&gt;</code> et <code>&lt;p&gt; &lt;/p&gt;</code>. Les <code>&lt;p&gt;</code> contenant un élément structurel (image, vidéo, iframe…) sont préservés.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'P2' => array(
				'label'       => __( 'Titres vides', '100son-html-normalizer' ),
				'description' => __( 'Supprime les <code>&lt;h1&gt;</code> à <code>&lt;h6&gt;</code> vides ou ne contenant que du blanc / <code>&amp;nbsp;</code>.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'P3' => array(
				'label'       => __( 'Shortcodes Shareaholic', '100son-html-normalizer' ),
				'description' => __( 'Supprime tout shortcode <code>[shareaholic ...]</code> (forme self-closed). Les autres shortcodes WordPress sont préservés.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'P4' => array(
				'label'       => __( 'Artefacts Pinterest', '100son-html-normalizer' ),
				'description' => __( "Supprime les vestiges du bouton Pinterest « Save » : forme A (<code>&lt;span data-pin-do&gt;</code>, attributs <code>data-pin-*</code>) et forme B (signature <code>z-index: 8675309</code> dans l'attribut <code>style</code>). 0 faux positif vérifié sur le corpus MMM.", '100son-html-normalizer' ),
				'has_options' => false,
			),
			'P5' => array(
				'label'       => __( '<code>&lt;br&gt;</code> excessifs', '100son-html-normalizer' ),
				'description' => __( 'Réduit les <code>&lt;br&gt;</code> consécutifs (≥ seuil) en séparation <code>&lt;/p&gt;&lt;p&gt;</code>. Les <code>&lt;p&gt;</code> éventuellement vides produits sont ramassés par P1 en fin de pipeline.', '100son-html-normalizer' ),
				'has_options' => true,
			),
			'P6' => array(
				'label'       => __( 'Styles inline', '100son-html-normalizer' ),
				'description' => __( 'Supprime les attributs <code>style="..."</code> de tous les éléments. Avec l\'option « Conserver text-align » activée, seule la déclaration <code>text-align: …</code> est conservée, les autres (<code>color</code>, <code>font-size</code>, <code>margin</code>…) sont retirées.', '100son-html-normalizer' ),
				'has_options' => true,
			),
			'P7' => array(
				'label'       => __( 'Listes ASCII', '100son-html-normalizer' ),
				'description' => __( 'Détecte les listes ASCII (lignes commençant par <code>-</code>, <code>–</code>, <code>*</code>, <code>•</code> ou un numéro <code>N.</code>) et les convertit en <code>&lt;ul&gt;</code>/<code>&lt;ol&gt;</code>. Fonctionne intra-<code>&lt;p&gt;</code> (séparées par <code>&lt;br&gt;</code>) et hors-<code>&lt;p&gt;</code> (chaque item dans son propre <code>&lt;p&gt;</code>). Marqueurs activables individuellement, seuil configurable, marqueurs custom possibles.', '100son-html-normalizer' ),
				'has_options' => true,
			),
			'P8' => array(
				'label'       => __( 'Récupération sémantique des styles', '100son-html-normalizer' ),
				'description' => __( 'Convertit les déclarations de présentation en balises HTML sémantiques AVANT que P6 ne strippe le style : <code>font-weight: bold</code> (ou ≥ 700) → <code>&lt;strong&gt;</code>, <code>font-style: italic</code> → <code>&lt;em&gt;</code>. Comportement chirurgical : seules ces déclarations sont retirées du <code>style</code>, les autres (<code>text-align</code>, <code>color</code>…) restent intactes pour P6.', '100son-html-normalizer' ),
				'has_options' => true,
			),
		);
	}

	/**
	 * Instancie une regle preset selon sa configuration utilisateur.
	 *
	 * @param string $preset_id Identifiant.
	 * @return RuleInterface|null
	 */
	private function build_rule( string $preset_id ): ?RuleInterface {
		$config = $this->settings->get_preset_config( $preset_id );

		switch ( $preset_id ) {
			case 'P1':
				return new EmptyParagraphsRule();

			case 'P2':
				return new EmptyHeadingsRule();

			case 'P3':
				return new ShareaholicShortcodeRule();

			case 'P4':
				return new PinterestArtifactsRule();

			case 'P5':
				$threshold = isset( $config['threshold'] ) ? (int) $config['threshold'] : 2;
				return new ExcessiveBrRule( $threshold );

			case 'P6':
				$keep_align = ! isset( $config['keep_text_align'] ) || (bool) $config['keep_text_align'];
				return new RemoveInlineStylesRule( $keep_align );

			case 'P7':
				$threshold      = isset( $config['threshold'] ) ? (int) $config['threshold'] : 2;
				$markers        = isset( $config['markers'] ) && is_array( $config['markers'] ) ? $config['markers'] : array(
					'dash' => true,
					'emdash' => true,
					'asterix' => true,
					'bullet' => true,
					'numeric' => true,
				);
				$custom_markers = isset( $config['custom_markers'] ) && is_array( $config['custom_markers'] )
					? array_values( array_map( 'strval', $config['custom_markers'] ) )
					: array();
				return new AsciiListRule( $markers, $threshold, $custom_markers );

			case 'P8':
				$mappings = isset( $config['mappings'] ) && is_array( $config['mappings'] ) ? $config['mappings'] : array();
				$bold     = ! isset( $mappings['bold'] ) || (bool) $mappings['bold'];
				$italic   = ! isset( $mappings['italic'] ) || (bool) $mappings['italic'];
				return new RecoverSemanticStylesRule( $bold, $italic );

			default:
				return null;
		}
	}
}
