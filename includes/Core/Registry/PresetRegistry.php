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
 */
final class PresetRegistry {

	/**
	 * Ordre canonique des presets dans le pipeline (cf. cahier 4.4).
	 *
	 * @var list<string>
	 */
	public const PRESETS = [ 'P3', 'P4', 'P8', 'P6', 'P7', 'P5', 'P1', 'P2' ];

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
		$rules = [];
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
	 * Liste tous les presets connus (independamment de leur etat).
	 * Utilise pour l'UI Presets.
	 *
	 * @return array<string, array{label: string, has_options: bool}>
	 */
	public function get_all_presets_metadata(): array {
		return [
			'P1' => [ 'label' => __( 'Paragraphes vides', '100son-html-normalizer' ), 'has_options' => false ],
			'P2' => [ 'label' => __( 'Titres vides', '100son-html-normalizer' ), 'has_options' => false ],
			'P3' => [ 'label' => __( 'Shortcodes Shareaholic', '100son-html-normalizer' ), 'has_options' => false ],
			'P4' => [ 'label' => __( 'Artefacts Pinterest', '100son-html-normalizer' ), 'has_options' => false ],
			'P5' => [ 'label' => __( '<br> excessifs', '100son-html-normalizer' ), 'has_options' => true ],
			'P6' => [ 'label' => __( 'Styles inline', '100son-html-normalizer' ), 'has_options' => true ],
			'P7' => [ 'label' => __( 'Listes ASCII', '100son-html-normalizer' ), 'has_options' => true ],
			'P8' => [ 'label' => __( 'Recuperation semantique des styles', '100son-html-normalizer' ), 'has_options' => true ],
		];
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
				$markers        = isset( $config['markers'] ) && is_array( $config['markers'] ) ? $config['markers'] : [
					'dash' => true, 'emdash' => true, 'asterix' => true, 'bullet' => true, 'numeric' => true,
				];
				$custom_markers = isset( $config['custom_markers'] ) && is_array( $config['custom_markers'] )
					? array_values( array_map( 'strval', $config['custom_markers'] ) )
					: [];
				return new AsciiListRule( $markers, $threshold, $custom_markers );

			case 'P8':
				$mappings = isset( $config['mappings'] ) && is_array( $config['mappings'] ) ? $config['mappings'] : [];
				$bold     = ! isset( $mappings['bold'] ) || (bool) $mappings['bold'];
				$italic   = ! isset( $mappings['italic'] ) || (bool) $mappings['italic'];
				return new RecoverSemanticStylesRule( $bold, $italic );

			default:
				return null;
		}
	}
}
