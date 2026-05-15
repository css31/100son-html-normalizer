<?php
/**
 * LossyRule — interface marqueur pour les règles délibérément destructives en texte.
 *
 * Une règle qui retire du contenu textuel (shortcode, snippet promotionnel,
 * artefact non-éditorial) déclenche mécaniquement une « perte » de
 * caractères/mots détectée par `RegressionDetector`. Avec les seuils par
 * défaut (`text_loss_pct = 0`, `words_loss_pct = 0`), chaque application
 * d'une telle règle finit en `regression_pending` et l'article n'est
 * jamais écrit — comportement non désiré pour une cleanup volontaire.
 *
 * Ce marqueur signale au `StepRunner` que la perte texte/mots est attendue
 * et qu'il faut relâcher les checks `chars` et `words` du
 * `RegressionDetector` (via `RegressionThresholds::relax_text_checks_for_lossy()`).
 * Les checks structurels (paragraphes, headings, images, links, lists)
 * restent appliqués — ils détectent les vraies régressions accidentelles.
 *
 * Aucune méthode requise — l'interface joue uniquement le rôle de tag.
 * Vérifié par `instanceof` dans `StepRunner::process_article`.
 *
 * Règles marquées actuellement :
 *  - `R3` (ShareaholicShortcodeRule) — retire `[shareaholic ...]`.
 *  - `R4` (PinterestArtifactsRule)   — retire `<span data-pin-*>` et le
 *                                       bouton « Save » Pinterest.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Marker interface — pas de méthode, vérifié via `instanceof LossyRule`.
 */
interface LossyRule {
}
