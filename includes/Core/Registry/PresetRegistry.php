<?php
/**
 * PresetRegistry — declare l'ordre du pipeline des presets et leur instanciation.
 *
 * Cf. cahier section 4.4 (ordre R3 -> R4 -> R8 -> R6 -> R7 -> R5 -> R1 -> R2)
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
use Cent_Son\Html_Normalizer\Core\Rules\FirstParagraphChapoRule;
use Cent_Son\Html_Normalizer\Core\Rules\H2ChapoToParagraphRule;
use Cent_Son\Html_Normalizer\Core\Rules\HeadingCaptionToFigcaptionRule;
use Cent_Son\Html_Normalizer\Core\Rules\HeadingMixedToFigureRule;
use Cent_Son\Html_Normalizer\Core\Rules\HeadingPromotionRule;
use Cent_Son\Html_Normalizer\Core\Rules\MergeAdjacentInlineTagsRule;
use Cent_Son\Html_Normalizer\Core\Rules\PinterestArtifactsRule;
use Cent_Son\Html_Normalizer\Core\Rules\StripHeadingPrefixRule;
use Cent_Son\Html_Normalizer\Core\Rules\RecoverSemanticStylesRule;
use Cent_Son\Html_Normalizer\Core\Rules\RemoveInlineStylesRule;
use Cent_Son\Html_Normalizer\Core\Rules\RuleInterface;
use Cent_Son\Html_Normalizer\Core\Rules\ShareaholicShortcodeRule;
use Cent_Son\Html_Normalizer\Core\Rules\UnwrapHeadingImageRule;
use Cent_Son\Html_Normalizer\Core\Rules\UnwrapParagraphImageRule;
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
	 * R9 (ajouté post-rc2) est inséré entre R5 et R1 : il désencapsule les
	 * `<hN>` autour d'images, opération structurelle qu'on veut faire avant
	 * le cleanup final R1/R2 (paragraphes/titres vides).
	 *
	 * R10 (post-rc4, cousine de R9) traite la même chose pour `<p>` autour
	 * d'images. Placée immédiatement après R9 — même invariant (s'exécute
	 * avant R1/R2) + cohérence sémantique (les deux règles de désencapsulation
	 * d'images sont côte-à-côte).
	 *
	 * R11 (post-rc4, branche caption) convertit les `<h4>` détournés en
	 * légende d'image en `<figcaption>` attachée à un `<figure>`. Insérée
	 * entre R9 et R10 pour deux invariants : (1) après R9, tout `<h4>`
	 * restant a forcément du texte (pas une image-seule) ; (2) avant R10,
	 * le `<p>` autour de l'image est encore présent — signal d'adjacence
	 * nécessaire à la détection de la paire image/légende.
	 *
	 * R12 (post-rc4, variante inline de R11) traite le cas où l'image
	 * ET la légende sont mixées dans un même `<h4>` (au lieu d'être
	 * réparties sur `<p>` + `<h4>` adjacents comme R11). Placée entre
	 * R9 et R11 — R9 garantit que les h4 restants ont du texte, R11
	 * traite ensuite uniquement les paires adjacentes encore intactes.
	 *
	 * R13 (post-rc4, chapô) convertit le premier `<h2>` du fragment
	 * lorsqu'il porte une phrase-chapô en `<p class="chapo">`. Placée
	 * entre R8 et R6 : R8 a converti les inlines sémantiques avant la
	 * démotion, R6 strippe ensuite les styles résiduels.
	 *
	 * R14 (post-rc4, complément de R13) ajoute la classe `chapo` au
	 * premier `<p>` significatif du fragment si c'est une phrase
	 * (≥ 5 mots + ponctuation). Couvre les ~423 articles SO dont le
	 * chapô est déjà rédigé en `<p>` (pas en `<h2>`). Placée juste
	 * après R13 : si R13 a démoté un h2, le premier `<p>` porte déjà
	 * `class="chapo"` (idempotent skip) ; sinon R14 marque le p
	 * candidat.
	 *
	 * R15 (post-rc4) fusionne deux éléments inline adjacents (même
	 * balise + mêmes attributs) en un seul. Placée après R6 et R5 :
	 * R6 a déjà strippé les `style` divergents, ce qui maximise les
	 * paires fusionnables.
	 *
	 * R16 (post-rc4) retire les préfixes typographiques en tête de
	 * heading (« 1. », « 2) », « • », « – »). Placée entre R15 et R9
	 * pour que R11/R12/R9 voient des h4 propres.
	 *
	 * R17 (post-v1.0.0) promeut en cascade les `<h3>`–`<h6>` quand le
	 * fragment ne contient aucun `<h2>`. Cas typique : un article SO
	 * dont le chapô-h2 vient d'être démoté en `<p class="chapo">` par
	 * R13, laissant une hiérarchie qui commence à h3 — défaut sémantique
	 * (~22 articles MMM-2 dont 374). Placée entre R10 et R1 : toutes
	 * les règles qui inspectent un niveau précis (R9, R11, R12, R10)
	 * ont déjà tourné, R13/R14 ont libéré la condition « aucun h2 »,
	 * et le cleanup final R1/R2 reste en aval.
	 *
	 * @var list<string>
	 */
	public const PRESETS = array( 'R3', 'R4', 'R8', 'R13', 'R14', 'R6', 'R7', 'R5', 'R15', 'R16', 'R9', 'R12', 'R11', 'R10', 'R17', 'R1', 'R2' );

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
			'R1' => array(
				'label'       => __( 'Paragraphes vides', '100son-html-normalizer' ),
				'description' => __( 'Supprime les <code>&lt;p&gt;&lt;/p&gt;</code>, <code>&lt;p&gt;&amp;nbsp;&lt;/p&gt;</code> et <code>&lt;p&gt; &lt;/p&gt;</code>. Les <code>&lt;p&gt;</code> contenant un élément structurel (image, vidéo, iframe…) sont préservés.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R2' => array(
				'label'       => __( 'Titres vides', '100son-html-normalizer' ),
				'description' => __( 'Supprime les <code>&lt;h1&gt;</code> à <code>&lt;h6&gt;</code> vides ou ne contenant que du blanc / <code>&amp;nbsp;</code>.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R3' => array(
				'label'       => __( 'Shortcodes Shareaholic', '100son-html-normalizer' ),
				'description' => __( 'Supprime tout shortcode <code>[shareaholic ...]</code> (forme self-closed). Les autres shortcodes WordPress sont préservés.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R4' => array(
				'label'       => __( 'Artefacts Pinterest', '100son-html-normalizer' ),
				'description' => __( "Supprime les vestiges du bouton Pinterest « Save » : forme A (<code>&lt;span data-pin-do&gt;</code>, attributs <code>data-pin-*</code>) et forme B (signature <code>z-index: 8675309</code> dans l'attribut <code>style</code>). 0 faux positif vérifié sur le corpus MMM.", '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R5' => array(
				'label'       => __( '<code>&lt;br&gt;</code> excessifs', '100son-html-normalizer' ),
				'description' => __( 'Réduit les <code>&lt;br&gt;</code> consécutifs (≥ seuil) en séparation <code>&lt;/p&gt;&lt;p&gt;</code>. Les <code>&lt;p&gt;</code> éventuellement vides produits sont ramassés par R1 en fin de pipeline.', '100son-html-normalizer' ),
				'has_options' => true,
			),
			'R6' => array(
				'label'       => __( 'Styles inline', '100son-html-normalizer' ),
				'description' => __( 'Supprime les attributs <code>style="..."</code> de tous les éléments. Avec l\'option « Conserver text-align » activée, seule la déclaration <code>text-align: …</code> est conservée, les autres (<code>color</code>, <code>font-size</code>, <code>margin</code>…) sont retirées.', '100son-html-normalizer' ),
				'has_options' => true,
			),
			'R7' => array(
				'label'       => __( 'Listes ASCII', '100son-html-normalizer' ),
				'description' => __( 'Détecte les listes ASCII (lignes commençant par <code>-</code>, <code>–</code>, <code>*</code>, <code>•</code> ou un numéro <code>N.</code>) et les convertit en <code>&lt;ul&gt;</code>/<code>&lt;ol&gt;</code>. Fonctionne intra-<code>&lt;p&gt;</code> (séparées par <code>&lt;br&gt;</code>) et hors-<code>&lt;p&gt;</code> (chaque item dans son propre <code>&lt;p&gt;</code>). Marqueurs activables individuellement, seuil configurable, marqueurs custom possibles.', '100son-html-normalizer' ),
				'has_options' => true,
			),
			'R8' => array(
				'label'       => __( 'Récupération sémantique des styles', '100son-html-normalizer' ),
				'description' => __( 'Convertit les déclarations de présentation en balises HTML sémantiques AVANT que R6 ne strippe le style : <code>font-weight: bold</code> (ou ≥ 700) → <code>&lt;strong&gt;</code>, <code>font-style: italic</code> → <code>&lt;em&gt;</code>. Comportement chirurgical : seules ces déclarations sont retirées du <code>style</code>, les autres (<code>text-align</code>, <code>color</code>…) restent intactes pour R6.', '100son-html-normalizer' ),
				'has_options' => true,
			),
			'R9' => array(
				'label'       => __( 'Titres autour d\'images', '100son-html-normalizer' ),
				'description' => __( 'Désencapsule les <code>&lt;h1&gt;</code>-<code>&lt;h6&gt;</code> qui ne contiennent qu\'une image (sans texte). Le <code>&lt;img&gt;</code> et son éventuel wrapper (<code>&lt;a&gt;</code>, <code>&lt;figure&gt;</code>…) sont préservés intacts, seules les balises de titre sont retirées. Typique des contenus migrés où un éditeur visuel a wrappé une image dans un titre par erreur. Symétrique de R2 (qui préserve volontairement ces titres).', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R10' => array(
				'label'       => __( 'Paragraphes autour d\'images', '100son-html-normalizer' ),
				'description' => __( 'Désencapsule les <code>&lt;p&gt;</code> qui ne contiennent qu\'une image (sans texte). Le <code>&lt;img&gt;</code> et son éventuel wrapper (<code>&lt;a&gt;</code>, <code>&lt;figure&gt;</code>…) sont préservés intacts, seule la balise <code>&lt;p&gt;</code> est retirée. Typique des contenus migrés depuis Word, Classic Editor ou SiteOrigin où une image isolée a été automatiquement enveloppée dans un paragraphe. Symétrique de R1 (qui préserve volontairement ces paragraphes) et cousine de R9 (qui fait la même chose sur les titres).', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R11' => array(
				'label'       => __( 'Disposition des h4 (légende / crédit / gras)', '100son-html-normalizer' ),
				'description' => __( 'Convention éditoriale MMM : un <code>&lt;h4&gt;</code> n\'est <strong>jamais</strong> un vrai sous-titre de section — c\'est un détournement typographique du rédacteur (légende d\'image, signature, ou paragraphe en gras visuel). R11 démote chaque <code>&lt;h4&gt;</code> selon son contexte : <ol><li><strong>h4 après <code>&lt;p&gt;&lt;img&gt;&lt;/p&gt;</code></strong> (image seule, pas de texte autour) → fusion en <code>&lt;figure&gt;&lt;img&gt;&lt;figcaption&gt;texte du h4&lt;/figcaption&gt;&lt;/figure&gt;</code>. Préserve les wrappers internes (<code>&lt;a&gt;</code> lightbox, <code>&lt;figure&gt;</code>) et les inlines (<code>&lt;a&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;br&gt;</code>) du h4.</li><li><strong>h4 orphelin juste après le chapô-lead</strong> (un <code>&lt;p class="chapo"&gt;</code> seul devant, sans autre <code>&lt;p class="chapo"&gt;</code> en amont) → promotion en chapô-crédit <code>&lt;p class="chapo"&gt;texte&lt;/p&gt;</code>. <code>ChapoFormatter</code> strippe le gras éventuel — cas typique « signature de rédaction » MMM (Photos : Untel, LA RÉDACTION…).</li><li><strong>h4 orphelin ailleurs</strong> (corps d\'article, ou chapô ayant déjà des crédits) → démotion en paragraphe gras <code>&lt;p&gt;&lt;strong&gt;texte&lt;/strong&gt;&lt;/p&gt;</code>. Le h4 servait visuellement de texte fort, on rend la sémantique explicite via <code>&lt;strong&gt;</code>.</li></ol>Cas écartés (délégués) : h4 vide (R2), h4 image-seule (R9), h4 mixte image+texte (R12). Limite : niveau <code>&lt;h4&gt;</code> uniquement — les h2/h3/h5/h6 sont préservés intacts.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R12' => array(
				'label'       => __( 'Titres mixtes image + légende', '100son-html-normalizer' ),
				'description' => __( 'Variante inline de R11 : convertit un <code>&lt;h4&gt;</code> qui contient <strong>à la fois</strong> une (ou plusieurs) <code>&lt;img&gt;</code> et son texte de légende en une <code>&lt;figure&gt;</code> regroupant les images suivies d\'un <code>&lt;figcaption&gt;</code> unique. Mode tolérant multi-images : la <code>&lt;figure&gt;</code> peut contenir 2+ images partageant la même <code>&lt;figcaption&gt;</code> (forme HTML5 normative pour un groupe d\'images à légende commune). Préserve les wrappers d\'image (<code>&lt;a&gt;</code> lightbox, <code>&lt;picture&gt;</code>, <code>&lt;figure&gt;</code> imbriquée) et les inlines de la caption (<code>&lt;em&gt;</code>, <code>&lt;strong&gt;</code>, liens textuels). Nettoie les séparateurs visuels parasites (<code>&lt;br&gt;</code>, espaces, NBSP) en bordure de caption. Limites assumées : niveau <code>&lt;h4&gt;</code> uniquement.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R13' => array(
				'label'       => __( 'Promotion h2-chapô', '100son-html-normalizer' ),
				'description' => __( 'Convertit le <strong>premier</strong> <code>&lt;h2&gt;</code> du fragment en <code>&lt;p class="chapo"&gt;</code> lorsqu\'il porte une (ou plusieurs) phrase(s) — c\'est-à-dire un chapô d\'article au sens journalistique, et non un sous-titre de section. Sémantique HTML5 : un <code>&lt;h2&gt;</code> est une tête de section, pas un standfirst ; le <code>&lt;p class="chapo"&gt;</code> est le bon support (compatible Gutenberg <code>core/paragraph</code> via <code>className</code>). Critères : ≥ 5 mots et au moins une ponctuation <code>.</code> / <code>!</code> / <code>?</code>. Seul le premier <code>&lt;h2&gt;</code> du document est candidat (les h2 ultérieurs sont de vrais sous-titres de section et restent intacts). Préserve tous les inlines (<code>&lt;a&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;strong&gt;</code>, <code>&lt;br&gt;</code>). Audit corpus MMM-2 : 148 captures sur 758 articles SiteOrigin.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R14' => array(
				'label'       => __( 'Marquage chapô (1er p + crédits)', '100son-html-normalizer' ),
				'description' => __( 'Complément de R13 : ajoute la classe <code>chapo</code> au <strong>premier paragraphe significatif</strong> du fragment lorsqu\'il porte une (ou plusieurs) phrase(s), PUIS étend le marquage aux <strong>paragraphes de crédits adjacents</strong> (max 3) qui suivent — « LA RÉDACTION », « PHOTOS Cyrille Martin », « Photographe : … », ou simplement un nom isolé (paragraphe court ≤ 6 mots sans ponctuation finale). Couvre les articles dont le chapô est rédigé en <code>&lt;p&gt;</code> et non détourné en <code>&lt;h2&gt;</code>. Critères du chapô-lead : ≥ 5 mots + ponctuation <code>.</code> / <code>!</code> / <code>?</code>. Descend dans les wrappers transparents (<code>&lt;div&gt;</code>, <code>&lt;section&gt;</code>, <code>&lt;article&gt;</code>, <code>&lt;main&gt;</code>, <code>&lt;header&gt;</code>) — gère la structure panel-layout SiteOrigin imbriquée. Compose avec R13 : si R13 vient de démoter un h2-chapô en <code>&lt;p class="chapo"&gt;</code>, R14 ne re-marque pas le lead mais étend bien aux crédits suivants. Audit corpus MMM-2 : 456 chapôs + ~431 crédits soit ~887 marquages au total sur 758 articles SiteOrigin.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R15' => array(
				'label'       => __( 'Fusion balises inline en double', '100son-html-normalizer' ),
				'description' => __( 'Fusionne deux éléments inline <strong>adjacents</strong> (séparés au plus par des espaces) qui partagent <strong>exactement</strong> le même nom de balise ET les mêmes attributs. Élimine le doublement redondant produit par les éditeurs visuels (typique SiteOrigin/Word) : <code>&lt;em&gt;foo&lt;/em&gt;&lt;em&gt;bar&lt;/em&gt;</code> → <code>&lt;em&gt;foobar&lt;/em&gt;</code>. Cible une whitelist de balises inline : <code>em</code>, <code>strong</code>, <code>i</code>, <code>b</code>, <code>u</code>, <code>s</code>, <code>sub</code>, <code>sup</code>, <code>small</code>, <code>big</code>, <code>tt</code>, <code>mark</code>, <code>ins</code>, <code>del</code>, <code>code</code>, <code>kbd</code>, <code>samp</code>, <code>var</code>, <code>cite</code>, <code>q</code>, <code>abbr</code>, <code>dfn</code>, <code>time</code>, <code>data</code>, <code>output</code>, <code>bdi</code>, <code>bdo</code>, <code>font</code>, <code>span</code>. <strong>Exclus</strong> : <code>&lt;p&gt;</code>, <code>&lt;div&gt;</code>, headings, <code>&lt;a&gt;</code>, <code>&lt;li&gt;</code>, structures de tableaux, et éléments void. Comparaison d\'attributs stricte : si l\'un a <code>style="color:red"</code> et l\'autre <code>style="color:blue"</code>, pas de fusion. Multi-passes pour gérer les chaînages.', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R16' => array(
				'label'       => __( 'Préfixes de titre (numéros, puces)', '100son-html-normalizer' ),
				'description' => __( 'Retire les préfixes typographiques placés en tête d\'un <code>&lt;h1&gt;</code>–<code>&lt;h6&gt;</code> : <strong>numéros d\'ordre</strong> (« 1. », « 23) », « 5° » — 1 à 2 chiffres + ponctuation + espace) et <strong>puces / tirets</strong> (<code>•</code>, <code>‣</code>, <code>►</code>, <code>▸</code>, <code>*</code>, <code>-</code>, <code>–</code>, <code>—</code>). Convention sémantique : un heading porte un titre, pas une marque de liste — la numérotation appartient soit à une <code>&lt;ol&gt;</code>, soit au thème via <code>counter-reset</code> + <code>::before</code>. Walk DOM : trouve le préfixe même s\'il est emballé dans un inline (<code>&lt;h2&gt;&lt;strong&gt;1.&lt;/strong&gt; Texte&lt;/h2&gt;</code> → <code>&lt;h2&gt;&lt;strong&gt;&lt;/strong&gt; Texte&lt;/h2&gt;</code>, wrapper vide laissé pour cleanup ultérieur). Audit corpus MMM-2 : 5 articles concernés (4 numérotés : 1065, 2013, 3552, 3787 ; 1 avec puces : 892).', '100son-html-normalizer' ),
				'has_options' => false,
			),
			'R17' => array(
				'label'       => __( 'Promotion h3 → h2 (cascade sans h2)', '100son-html-normalizer' ),
				'description' => __( 'Promeut d\'un cran les titres <code>&lt;h3&gt;</code>–<code>&lt;h6&gt;</code> lorsque le fragment ne contient <strong>aucun</strong> <code>&lt;h2&gt;</code>. Cascade : <code>&lt;h6&gt;</code> → <code>&lt;h5&gt;</code>, <code>&lt;h5&gt;</code> → <code>&lt;h4&gt;</code>, <code>&lt;h4&gt;</code> → <code>&lt;h3&gt;</code>, <code>&lt;h3&gt;</code> → <code>&lt;h2&gt;</code>. Les attributs (<code>id</code>, <code>class</code>, <code>style</code>…) et le contenu interne sont préservés — seule la balise change. Cas typique : article SiteOrigin dont le chapô-<code>&lt;h2&gt;</code> vient d\'être démoté en <code>&lt;p class="chapo"&gt;</code> par R13, laissant une hiérarchie qui commence à <code>&lt;h3&gt;</code> — défaut sémantique HTML5. Skipped si au moins un <code>&lt;h2&gt;</code> est présent (les vrais sous-titres de section sont préservés). Audit corpus MMM-2 : 22 articles concernés (374, 491, 515, 677, 714, 802, 812, 823, 836, 847, 881, 948, 1238, 1291, 5474, 5795, 5837, 5851, 5854, 5866, 6110, 6150).', '100son-html-normalizer' ),
				'has_options' => false,
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
			case 'R1':
				return new EmptyParagraphsRule();

			case 'R2':
				return new EmptyHeadingsRule();

			case 'R3':
				return new ShareaholicShortcodeRule();

			case 'R4':
				return new PinterestArtifactsRule();

			case 'R5':
				$threshold = isset( $config['threshold'] ) ? (int) $config['threshold'] : 2;
				return new ExcessiveBrRule( $threshold );

			case 'R6':
				$keep_align = ! isset( $config['keep_text_align'] ) || (bool) $config['keep_text_align'];
				return new RemoveInlineStylesRule( $keep_align );

			case 'R7':
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

			case 'R8':
				$mappings = isset( $config['mappings'] ) && is_array( $config['mappings'] ) ? $config['mappings'] : array();
				$bold     = ! isset( $mappings['bold'] ) || (bool) $mappings['bold'];
				$italic   = ! isset( $mappings['italic'] ) || (bool) $mappings['italic'];
				return new RecoverSemanticStylesRule( $bold, $italic );

			case 'R9':
				return new UnwrapHeadingImageRule();

			case 'R10':
				return new UnwrapParagraphImageRule();

			case 'R11':
				return new HeadingCaptionToFigcaptionRule();

			case 'R12':
				return new HeadingMixedToFigureRule();

			case 'R13':
				return new H2ChapoToParagraphRule();

			case 'R14':
				return new FirstParagraphChapoRule();

			case 'R15':
				return new MergeAdjacentInlineTagsRule();

			case 'R16':
				return new StripHeadingPrefixRule();

			case 'R17':
				return new HeadingPromotionRule();

			default:
				return null;
		}
	}
}
