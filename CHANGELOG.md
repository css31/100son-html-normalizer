# Changelog

Toutes les modifications notables de cette extension sont consignées ici.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versionning [SemVer](https://semver.org/lang/fr/).

## [1.0.0-rc2] — 2026-05-12

Ajout de l'**onglet Notes** dans la SPA d'administration — éditeur Gutenberg restreint pour saisir des notes libres persistées côté serveur. Cohabite avec la zone de notes plain-text de la page Journal V0.1 (stockages séparés) jusqu'à la disparition de cette dernière en V1.1.

### Ajouts

#### Onglet Notes (SPA)

- **Nouvel onglet `[Notes]`** dans la barre primaire de la SPA, entre `[Historique]` et `[Réglages]`. Route hash `#/notes`.
- **Éditeur Gutenberg restreint** (`BlockEditorProvider` inline, pas d'iframe) avec une **whitelist de 9 blocs** : `core/paragraph`, `core/heading`, `core/list`, `core/list-item`, `core/quote`, `core/code`, `core/separator`, `core/image`, `core/table`. Inserter, Inspector et toolbar standards Gutenberg.
- **Persistance** : option dédiée `son100_htmln_notes_rich` (block grammar Gutenberg sérialisée). Indépendante de `son100_htmln_logs_notes` (V0.1 plain text, page Journal) — choix d'isolation pour ne pas exposer le contenu riche à un round-trip `sanitize_textarea_field` qui détruirait les commentaires `<!-- wp:* -->`.
- **Sanitization serveur** via `wp_kses_post()` — préserve la block grammar, strippe le code dangereux (script, event handlers).
- **Save explicite** (bouton « Enregistrer ») + bouton « Vider la note » avec `window.confirm`. Dirty-state local affiché en discrète mention « Modifications non enregistrées. ».

#### Repository + REST

- **`Notes\RichNotesRepository`** — repo dédié sur `son100_htmln_notes_rich`, API `get() / set(string) / clear()`. `set()` applique `wp_kses_post()` + `trim()`. `clear()` met à chaîne vide (pas `delete_option` — évite le yo-yo autoload).
- **`Rest\NotesController`** — endpoint singleton `/htmln/v1/notes` (GET / PUT / DELETE), capability `manage_options`, namespace `htmln/v1` :
  - `GET /notes` → `{ content: string }` (chaîne vide si jamais saisi)
  - `PUT /notes` → body `{ content: string }`, retourne le contenu post-sanitization (autorité serveur — la SPA resynchronise l'éditeur si modification)
  - `DELETE /notes` → vide, retourne `{ content: '' }`
- 7 tests `NotesControllerTest` + 8 tests `RichNotesRepositoryTest` (round-trip block grammar, strip de `<script>`, trim, idempotence clear).

#### Enqueue Gutenberg côté SPA

- **`Admin\Assets::on_enqueue`** étendu pour appeler `wp_enqueue_media()` + enqueue des styles `wp-edit-blocks`, `wp-format-library`, `wp-block-library`, `wp-block-library-theme` quand on est sur la page SPA. Scope-restreint inchangé (uniquement sur `?page=100son-html-normalizer-spa`).
- Le bundle reste mince côté JS (62.4 → 68.1 KiB, +5.7 KiB) car `@wordpress/scripts` externalise `wp-block-editor` / `wp-blocks` / `wp-block-library` vers les globals `wp.*`. CSS : 11.5 → 12.5 KiB.

#### Dépendances NPM

- `@wordpress/block-editor` ^14.0
- `@wordpress/block-library` ^9.0
- `@wordpress/blocks` ^13.0
- `@wordpress/format-library` ^5.0

#### Désinstallation

- `uninstall.php` purge également `son100_htmln_notes_rich`.

### Stats

- **PHPUnit** : 562 → 581 tests verts (+19), 1255 → 1299 assertions
- **Bundle SPA** : 62.4 KiB JS / 11.5 KiB CSS → 68.1 KiB JS / 12.5 KiB CSS (RTL auto)
- **Lint JS** : 0 erreur, 0 warning
- **PHPStan** : niveau 6, 0 erreur (inchangé)

---

## [1.0.0-rc1] — 2026-05-11

Première candidate à la version stable 1.0.0. Toutes les phases 1-6 du cahier v2.0 sont livrées : la base de la **normalisation par pas** avec **diagnostic** structurel, **détection de régression** sur 7 seuils γ, et **SPA d'administration** React. Le périmètre V0.1 (4 préréglages PHP, 4 pages admin, filtre `htmln/normalize`) reste intégralement compatible — les pages V0.1 cohabitent avec la SPA V1.0.

### Ajouts majeurs

#### Diagnostic structurel — F12 (Phase 3)

- **Table custom `son100_htmln_diagnostics`** (`UNIQUE post_id`, `KEY status`, `KEY is_stale`) créée via `dbDelta()` à l'activation. `DB_VERSION = '2.0.0'`.
- **`DiagnosticEngine::diagnose(WP_Post): DiagnosticRecord`** — itère les règles activées via `Rule::countMatches()`, agrège `total_violations`, status `to_improve` si > 0 sinon `normal`. `matching_rules` ne liste que les règles avec count > 0 (`{rule_id, occurrences}`). Snapshot `MetricsSnapshot` (7 métriques γ) joint. `post_modified_at_diagnosis` capturé à l'instant t pour détection `is_stale` fine.
- **`DiagnosticBatchRunner::start_batch(?int $chunk_size, ?array $post_types_override): array`** — énumère les posts publish + post_types F8 → `{batch_id (UUID v4), total_articles, post_ids, chunk_size}`. `process_chunk(list<int>)` boucle `get_post → engine->diagnose → repository->upsert`. `DEFAULT_CHUNK_SIZE = 20`.
- **`DiagnosticInvalidator`** — hook `save_post` priorité 999. Skip révisions, autosaves, non-publish, post_types hors F8. Marque `is_stale=1` dans try/catch (non-bloquant). **Premier branchement runtime des phases 2/3 dans `Plugin::boot()`**.

#### Métriques γ — F13 (Phase 2.3)

- **`MetricsSnapshot`** : DTO `readonly` 7 métriques (`chars`, `words`, `paragraphs`, `headings:{h1..h6}`, `images`, `links`, `lists`) + `toArray()` / `fromArray()` tolérants + `zero()` + `totalHeadings()`.
- **`MetricsCalculator::compute(string): MetricsSnapshot`** : 1 parse DOM, comptages O(n), unicode-aware via `preg_match_all('/[\p{L}\p{N}]+/u')`, NBSP normalisés, `<a href>` filtrés sur href non vide. Ne lève jamais.

#### Détection de régression — F15 (Phase 3.1)

- **`RegressionThresholds`** : DTO immuable des 7 seuils γ + constructeurs `from_array` / `from_settings(SettingsRepository)` / `defaults()`.
- **`RegressionFailure`** : DTO d'une métrique en dépassement (`metric_key`, `before`, `after`, `threshold`, `unit` `pct`/`absolute`, `loss`, `loss_pct`).
- **`RegressionReport`** : synthèse (liste non vide de failures), méthodes `has_failure(key)` / `failure_for(key)` / `failure_count()` / `to_array()` / `from_array()`.
- **`RegressionDetector::analyze(MetricsSnapshot $before, $after, RegressionThresholds): ?RegressionReport`** :
  - Pourcentages (chars/words/paragraphs) : `loss_pct = (before - after) / before × 100` arrondi 2 décimales, comparaison stricte `>`.
  - Absolus (images/links/lists) : `loss = before - after`, comparaison stricte `>`.
  - Headings : seuil indépendant par niveau h1..h6 → 6 checks, `metric_key = "headings.h{N}"`.
  - `before ≤ 0` → null. Perte == seuil → pas de déclenchement. Gains → null.

#### Application par pas — F14 (Phase 4)

- **Table custom `son100_htmln_steps`** (`UNIQUE step_uuid`, `KEY started_at`) avec colonnes JSON `applied_rules`, `affected_post_ids`, `per_article_results`.
- **`StepRunner`** — orchestrateur F14 avec 6 méthodes publiques :
  - `start_step($post_ids, $rule_ids, ?$user_id): string` — génère UUID v4 serveur (§13).
  - `process_article($uuid, $post_id, $dry_run = false): ArticleResult` — pipeline §4.4.2 complet : révision systématique → applySubset → metrics avant/après → analyze régression → branchement (success / dry_run / regression_pending).
  - `confirm_article($uuid, $post_id): ArticleResult` — admin accepte régression : nouvelle révision + écriture forcée + recalcul diagnostic.
  - `refuse_article($uuid, $post_id): ArticleResult` — admin refuse : pose `_son100_htmln_manual_check_required = 1`.
  - `resume_progress($uuid): ?array{uuid, total_articles, processed[], regression_pending[], pending[]}` — bandeau de reprise.
  - `finalize_step($uuid): ?StepRecord` — idempotent. Comptage success/refused/errored.
- **`ArticleResult`** : DTO 5 statuts (`success`, `dry_run`, `regression_pending`, `refused`, `error`). `to_persistence_array()` shape strict `{status, regression?, error?}`.
- **Garde-fous §13 respectés** : `wp_save_post_revision()` systématiquement avant écriture ; `RegressionDetector::analyze()` systématiquement appelé (jamais shortcircuité) ; `step_uuid` côté serveur (`wp_generate_uuid4()`) ; try/catch global autour de `applySubset`.

#### Surface REST — V1.0 (Phase 5)

19 routes sous le namespace `htmln/v1`, toutes en `manage_options` (cf. cahier §14 hyp. 14) :

```
Steps        (7) : GET  /steps, GET /steps/<uuid>, POST /steps/run,
                   POST /steps/<uuid>/{process, confirm-article, finalize},
                   GET  /steps/export
Diagnostics  (6) : GET  /diagnostics, POST /diagnostics/{run, run/chunk},
                   GET / DELETE /diagnostics/<post_id>, GET /diagnostics/stats
Posts        (5) : GET  /posts/{post-types, scan}, GET /posts/<id>/preview,
                   POST /posts/<id>/normalize, POST /posts/batch-normalize
Diff         (1) : POST /posts/<id>/diff
Settings     (1) : GET / POST /settings/regression-thresholds
```

- **Format d'erreur unifié** `{code, message, data: {status, ...extra}}` aligné sur la sérialisation native `WP_Error`.
- **`BaseController` abstract** : namespace constant, permission callback partagé, helpers `respond()` / `rest_error()` / `rest_error_from_wp()` / `sanitize_int_list()` / `sanitize_string_list()`.
- **`RestServiceProvider`** : idempotent, branche un seul `add_action('rest_api_init', register_all_routes)`.

#### Surface WP-CLI — V1.0 (Phase 5.5)

```
wp htmln steps list   [--from=<date>] [--to=<date>] [--limit=<n>]
wp htmln steps show   <uuid>
wp htmln steps export [--file=<path>] [--from=<date>] [--to=<date>]
wp htmln scan         [<id> | --all | --status=stale [--rebuild]]
```

Sortie JSON pretty-printée. Export CSV reporté V1.1.

#### SPA admin React — V1.0 (Phase 6)

Nouvelle sous-page **« Normaliser V1 »** sous le menu HTML Normalizer. SPA unique avec router hash interne sur 3 routes :

- **`#/normalize`** (F13/F14/F14.3/F15) : 3 onglets (`to_improve` / `normal` / `stale`) + tableau paginé avec checkboxes par ligne + bouton « Voir le diff » + sidebar 8 préréglages cochables + bandeaux `StepProgressBanner` / `StepResumeBanner` + hook `useStepRunner` (pause/resume sur régression) + `useBeforeunload`.
- **`#/history`** (F16) : listing paginé `/steps` + `StepDetailDrawer` Modal plein écran avec `per_article_results` complet (status, régression, erreur, lien vers édition article).
- **`#/settings`** : 7 inputs validés (3 % + 4 absolus), bouton « Restaurer les valeurs par défaut », Notice succès/erreur/warning.

Modales clés :
- **`DiffModal`** (F14.3) : Modal `isFullScreen`, toggle Code/Rendu, 2 `<pre>` ou 2 `<iframe sandbox="allow-same-origin">` **sans `allow-scripts`** (cf. §13). Sanitize JS maison (`utils/sanitizeForIframe.js`, DOMParser, suppression `<script>`, attrs `on*`, URLs `javascript:`).
- **`RegressionModal`** (F15) : 3 boutons (Voir diff / Refuser / Confirmer), `shouldCloseOnClickOutside={false}` `shouldCloseOnEsc={false}`, `MetricsDiffBar` 7 métriques avec delta coloré.

Stack : `@wordpress/scripts` 27 + `@wordpress/components` + `@wordpress/data` (store namespace `htmln/spa`) + `@wordpress/api-fetch` + `@wordpress/i18n`. Bundle final 53.4 KiB minifié.

#### Internationalisation (Phase 6.7)

- `wp_set_script_translations()` câblé sur le handle SPA dès Phase 6.1.
- `languages/100son-html-normalizer.pot` généré via `wp i18n make-pot` (329 chaînes msgid couvrant PHP + JS).
- `load_plugin_textdomain()` branché sur `init` (WP 6.7+).

### Modifications

- **`SettingsRepository::getRegressionThresholds(): array`** (Phase 1) — retourne les 7 seuils γ avec defaults du cahier §14 hyp. 24. Constante publique `REGRESSION_THRESHOLD_DEFAULTS`.
- **`SettingsRepository::setRegressionThresholds(array): array`** (Phase 6.7) — setter défensif (entiers ≥ 0, fallback defaults sur invalides, ignore clés inconnues, préserve les autres réglages). Helper privé `normalize_regression_thresholds()`.
- **`RuleInterface::countMatches(string $html, array $context = []): int`** (Phase 1) — ajout du contrat de comptage à l'interface. 8 préréglages P1-P8 implémentent la sémantique « ce que apply() supprimerait/transformerait ».
- **`Pipeline::applySubset(array $rules, array $rule_ids, string $html, …): string`** (Phase 1) — délègue à `run()` après filtrage en respectant l'ordre des règles fournies.
- **`PresetRegistry::get_rules_for_subset(array $rule_ids): array`** (Phase 1) — helper symétrique, respecte l'ordre canonique `PRESETS`, ignore les ids inconnus, filtre les presets désactivés.
- **README** réécrit pour V1.0 : sections « Workflow par pas » + « Seuils γ », surface REST + CLI documentée, architecture actualisée.

### Renommage de cahier

- `Cahier des charges — HTML Normalizer-v1.9.md` → `claude/CDC-html-normalizer-v1.9-archive.md`
- `Cahier des charges — HTML Normalizer-v2.0.md` → `claude/CDC-html-normalizer.md`
- `Cahier des charges — SO to Blocks-v0.3.md` → `claude/CDC-so-to-blocks.md`

### Sécurité

- **Sandbox iframes** (DiffModal) : `sandbox="allow-same-origin"` **jamais** `allow-scripts`. Sanitize JS local en défense en profondeur (DOMPurify écarté pour limiter le poids — `sandbox` est la 1ʳᵉ couche).
- **Nonce REST** propagé automatiquement par `@wordpress/api-fetch`.
- **UUID v4 toujours serveur** (`wp_generate_uuid4()`), jamais client.
- **Toutes les routes REST** : `permission_callback` `manage_options`.
- **`wp_kses_post`** appliqué au `srcdoc` des iframes de DiffModal.

### Tests et qualité

- **PHPUnit** : 548 verts, 1213 assertions (était 181 / 239 fin V0.1).
- **PHPStan niveau 6** : 22 erreurs baseline héritées de code historique (notamment `Diagnostics/DiagnosticEngine.php:77` sur `WP_Post::$post_modified`). **Zéro régression introduite par les phases 1-6**.
- **PHPCS** : WPCS + PSR-12.
- **lint-js** (`@wordpress/scripts lint-js`) : 0 erreur.
- **Bundle SPA** : 53.4 KiB minifié (594 octets en 6.1 → 53.4 KiB en 6.7).

### Compatibilité corpus MMM-2

- Extension activée sans erreur fatale sur `ma-maison-mag-2.local`.
- Cas-tests de non-régression à valider en recette manuelle : posts 11448 (chapô variant A), 374 (chapô variant B), 1392 (resync stale), 2500 (bullet conversion), 6690 (quote `<p>` 14pt), 6150 (art3f bullet list).

---

## [0.1.x] — Pré-V1 (jusqu'au 2026-05-09)

### Ajouts (V0.1, hors cahier des charges initial v1.7 → v1.9)

#### UI admin (PHP classique, pré-SPA §11 étape 15 du cahier)

- **Menu admin top-level** « HTML Normalizer » avec 4 sous-pages :
  - Préréglages
  - Tester un fragment
  - Normaliser
  - Journal
- **Page Préréglages** :
  - Description détaillée pour chacun des 8 préréglages (P1-P8) avec exemples HTML inline
  - Layout 2 colonnes : activation + paramètres à gauche, nom + description à droite (3× plus large)
  - Case maître « Tout activer / désactiver » avec état `indeterminate` automatique
  - Bouton **Enregistrer** dupliqué en haut et en bas du formulaire
- **Page Normaliser** (F8 du cahier, implémenté en UI PHP au lieu de SPA + REST) :
  - Liste paginée des articles, ouverte au-delà de `post` (CPT publics éligibles)
  - **Filtres persistés** : types de contenu (post / page / CPT), nombre d'articles par page (10/25/50/100/200)
  - **Filtres navigationnels** : recherche par titre uniquement (filtre `posts_search` custom), catégorie, année, mois, SiteOrigin (tous / SO / Non-SO via `meta_query` sur `panels_data`)
  - **Tri sortable** ID / Titre / Date au format WP natif (classes `sortable` / `sorted`, flèches `<span class="sorting-indicator">`)
  - **Pagination en haut ET en bas** du tableau, préserve filtres et tri
  - Colonnes : ID, Titre, Date, Type, Catégories (taxonomies hiérarchiques), Mots, badge SO, Actions
  - **Action groupée** « Normaliser la sélection » et « Normaliser (forcer SO) » avec `confirm()` JS bloquant
  - Vue Aperçu avant/après côte-à-côte avec confirmation forcée si article SiteOrigin
- **Page Tester un fragment** : textarea pleine largeur, normalisation à la volée, affichage source + rendu (`wp_kses_post`)
- **Page Journal** :
  - Tableau paginé des entrées de log (capacité 500 FIFO)
  - Colonnes : Date, Évènement (Normalisation / Aperçu / Configuration), Statut (badge couleur), ID, Article (lien édition + révision si dispo), Utilisateur
  - Bouton **Vider le journal** avec `confirm()` JS
  - **Zone Note libre** au-dessus du journal : textarea persistante (`son100_htmln_logs_notes`), boutons **Enregistrer** (gauche) et **Effacer** (droite, classe `button-link-delete`) sur une seule ligne, alerte bloquante sur Effacer
  - Suppression de la note **indépendante** du vidage du journal

#### Garde-fou perte de contenu

- **`HtmlMetrics`** : helper pur (sans WP) calculant 3 métriques sur un fragment HTML :
  - `word_count` (UTF-8 via `\p{L}\p{N}+`)
  - `char_count` (`mb_strlen` post strip_tags)
  - `image_count` (`<img ` insensible à la casse)
- **`compare()`** : deltas + pourcentages + niveau de sévérité (`ok` / `warning` / `critical`) avec seuils figés :
  - Warning : ≥ 10 % de mots perdus OU 1 image perdue
  - Critical : ≥ 30 % de mots perdus OU 2 images perdues
- **Métriques affichées dans la page Aperçu** : tableau Avant / Après / Δ / % avec badge sévérité (vert / orange / rouge)
- **Métriques stockées dans chaque entrée du journal** (avant + après + diff) — auditables historiquement
- **Colonne Mots** dans la liste de la page Normaliser (calcul à la volée)
- **Garde-fou non-bloquant** : seule alerte visible, l'admin reste maître de la décision

#### Logging structuré

- **`LogRepository`** : option WP `son100_htmln_logs`, FIFO 500 entrées, autoload=no
- **`Logger`** : 3 méthodes haut niveau (`log_normalize`, `log_preview`, `log_settings_change`) avec snapshot user + timestamp
- **`NotesRepository`** : option WP `son100_htmln_logs_notes` pour la zone Note libre
- **PostNormalizer** : log automatique à chaque preview / normalize_post avec métriques jointes
- **PresetsPage** : log automatique à chaque sauvegarde avec diff textuel des changements (ex : `P3 désactivé, P5 (paramètres modifiés)`)

#### Renommage terminologie

- **« Préset » → « Préréglage »** partout dans l'UI et le cahier des charges (43 occurrences, 0 restante).
  Code interne (noms de classe `PresetRegistry`, méthodes `is_preset_enabled`, clés option `son100_htmln_presets`, etc.) volontairement conservé en anglais : terminologie technique stable, i18n-ready.

### Couvert depuis le cahier v1.7

- §11 étape 1 : Bootstrap & infrastructure (constantes, autoloader, Plugin singleton, Activator/Deactivator, uninstall.php)
- §11 étape 2 : SettingsRepository + UserRulesRepository
- §11 étape 3 : RuleInterface + 8 préréglages P1-P8 + 12 fixtures + tests PHPUnit
- §11 étape 4 : PresetRegistry (ordre canonique du pipeline)
- §11 étape 6 : Pipeline + HtmlNormalizer + test full-pipeline (fixture validée par Cyrille)
- §11 étape 7 : PublicApi (filtre `htmln/normalize` exposé)
- §11 étape 13 : F8 Normaliser des articles (UI PHP, pas REST/SPA)

### Non couvert (à venir)

- §11 étape 5 : F5 HeadingStrategist (heuristique sauts h1→h3, h4 décoratifs)
- §11 étape 8 : REST controllers (`PresetsController`, `NormalizeController`, …)
- §11 étape 9 : RuleValidator + tests sécurité
- §11 étape 10 : F4 mode simple (CssSelectorRule) + mode avancé (RegexRule)
- §11 étape 11 : PreviewRunner + endpoints `/rules/preview`, `/rules/validate`
- §11 étape 12 : F7 Export/Import bibliothèque de règles
- §11 étape 14 : WP-CLI (`wp htmln normalize`, `preview`, `presets`, `rules`)
- §11 étape 15 : SPA admin React (remplace l'UI PHP V0.1)
- §11 étape 16 : i18n .pot
- §11 étape 17 : README.md complet

### Tests

- 181 tests PHPUnit verts, 239 assertions
- Couverture : 8 préréglages + Pipeline + HtmlNormalizer + PublicApi + LogRepository + Logger + NotesRepository + HtmlMetrics + PostNormalizer + SiteOriginDetector

### Compatibilité corpus MMM-2

- Plugin activé sans erreur fatale sur `ma-maison-mag-2.local`
- Filtre `htmln/normalize` testé : `<p style="color:red"></p><p>OK</p>[shareaholic id="123"]` → `<p>OK</p>`
- F8 testé sur post 374 (cas SiteOrigin) : 20 278 → 10 863 octets, métriques 1 059 → 1 052 mots (-0,66 %), 7 images préservées, sévérité OK

---

## [0.1.0] — 2026-05-08

### Ajouté

- Scaffolding initial du plugin (header WP, autoloader PSR-4, constantes obligatoires)
- 8 préréglages de normalisation : P1 EmptyParagraphs, P2 EmptyHeadings, P3 ShareaholicShortcode, P4 PinterestArtifacts (formes A + B), P5 ExcessiveBr, P6 RemoveInlineStyles (option `keep_text_align`), P7 AsciiList, P8 RecoverSemanticStyles
- Pipeline d'orchestration avec actions `htmln/before_normalize` et `htmln/after_normalize`
- API publique : filtre WordPress `htmln/normalize` consommable par d'autres plugins
- Helper `DomHtml` pour parsing/sérialisation de fragments HTML via DOMDocument
