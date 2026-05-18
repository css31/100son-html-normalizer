<?php
/**
 * BuilderScopedRule — interface optionnelle pour les règles dont le scope
 * doit être restreint selon le constructeur d'origine de l'article.
 *
 * Certaines règles sont sûres sur du HTML libre ou du SiteOrigin aplati mais
 * dangereuses sur du Gutenberg natif : leurs transformations attaqueraient
 * directement la sérialisation des blocs (attributs JSON dans les commentaires
 * `<!-- wp:* ... -->` strictement cohérents avec le HTML stocké), produisant
 * un « contenu invalide » côté éditeur. Plutôt que de tenter une transformation
 * bloc-aware, on choisit de NE PAS appliquer ces règles sur les articles
 * classifiés `gutenberg` par `BuilderClassifier::classify()`.
 *
 * Contrat :
 *  - Toute règle implémentant cette interface déclare les `builder_type`
 *    (cf. constantes `BuilderClassifier::TYPE_*`) où elle ne doit ni compter
 *    de correspondances (`countMatches`) ni s'appliquer (`apply`).
 *  - Le filtrage est piloté par `$context['builder_type']` : si la clé est
 *    absente ou nulle, la règle s'applique partout (comportement par défaut,
 *    rétro-compat avec les anciens appels Pipeline sans contexte enrichi).
 *  - Les règles qui n'implémentent pas l'interface s'appliquent à tous les
 *    types — comportement historique préservé.
 *
 * Vérifié par `instanceof` dans `Pipeline::run()` et `DiagnosticEngine::diagnose()`.
 *
 * Règles marquées actuellement :
 *  - `R6`  (RemoveInlineStylesRule)   — exclue de `gutenberg` (les attrs JSON
 *                                        encodent style+HTML strictement
 *                                        cohérents).
 *  - `R14` (FirstParagraphChapoRule)  — exclue de `gutenberg` (l'ajout d'une
 *                                        classe sur un `<p>` désynchronise les
 *                                        attrs du bloc `core/paragraph`).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Règle dont le scope dépend du `builder_type` de l'article cible.
 */
interface BuilderScopedRule {

	/**
	 * Liste des `builder_type` (constantes `BuilderClassifier::TYPE_*`) où la
	 * règle ne doit NI compter de matches NI s'appliquer.
	 *
	 * @return list<string>
	 */
	public function excluded_builder_types(): array;
}
