# Changelog

Toutes les modifications notables de cette extension sont consignées ici.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versionning [SemVer](https://semver.org/lang/fr/).

## [Unreleased]

### Règles destructives (`LossyRule`) + bucket `pending_articles` séparé

**Problème observé** : R3 (Shareaholic) appliquée à tout le corpus laissait 100 articles avec encore des occurrences. Diagnostic : le `RegressionDetector` (seuils par défaut `text_loss_pct = 0` et `words_loss_pct = 0`) bloquait l'écriture sur 100 articles dont le retrait du shortcode `[shareaholic id="..."]` causait une perte mesurable de caractères/mots. Ces articles tombaient en `regression_pending`, mais **le compteur du step les classait erronément en `errored_articles`** — d'où la fausse impression de "scan terminé" alors qu'un arbitrage admin était en attente.

**Deux corrections orthogonales** :

**A) Marker `LossyRule` pour les règles délibérément destructives**

- Nouvelle interface marqueur `Cent_Son\Html_Normalizer\Core\Rules\LossyRule` (aucune méthode — uniquement un tag `instanceof`).
- Implémentée par **R3** (ShareaholicShortcodeRule) et **R4** (PinterestArtifactsRule) — les deux règles dont la mission est de retirer physiquement du contenu textuel non-éditorial (shortcodes, snippets `data-pin-*`, bouton « Save » Pinterest).
- Nouvelle méthode `RegressionThresholds::relax_text_checks_for_lossy()` qui retourne une copie avec `text_loss_pct = 100` et `words_loss_pct = 100` (désactive de fait les vérifications de perte texte/mots). Les seuils structurels (paragraphes, headings, images, links, lists) restent inchangés.
- `StepRunner::process_article()` détecte via `subset_contains_lossy_rule()` si le sous-ensemble appliqué contient au moins une `LossyRule`, et passe les seuils relâchés à `RegressionDetector::analyze()` dans ce cas. Les checks structurels continuent d'attraper les vraies régressions (image perdue, h2 disparu, etc.).

**B) Bucket `pending_articles` dans `son100_htmln_steps`**

- Schéma : `pending_articles INT UNSIGNED NOT NULL DEFAULT 0` (DB_VERSION bumpée 2.1.0 → **2.2.0**, migration automatique via `Plugin::maybe_run_db_upgrade()`).
- `StepRecord` gagne la propriété `pending_articles` ; `StepsRepository::finalize()` accepte un paramètre supplémentaire ; `StepRunner::count_terminal_statuses()` retourne désormais 4 buckets distincts (`success` / `refused` / `errored` / **`pending`**) au lieu d'absorber `regression_pending` dans `errored`.
- Endpoint REST `GET /steps` + détail expose le champ `pending_articles`.
- SPA :
  - `<StepsTable>` (onglet Historique) : nouveau compteur « ⏸ N » affiché quand `pending_articles > 0`.
  - `<StepDetailDrawer>` : ligne « N au total · ✓ … · ✗ … · ⚠ … · **⏸ N en attente** ».

**Impact sur le corpus existant** : un script ponctuel (cf. `wp eval` dans le commit) recompte `pending_articles` pour les steps existants à partir de `per_article_results`. Le step 1 du sandbox MMM-2 passe de `errored=100` (faux) à `pending=100` (correct).

**Tests** : 5 nouveaux cas — `RegressionThresholdsTest::test_relax_text_checks_*` (×2) + `StepRunnerTest::test_lossy_rule_in_subset_relaxes_text_checks` / `test_non_lossy_rule_keeps_strict_text_checks` / `test_lossy_rule_still_blocks_on_structural_regression`. Stats globales : **966 tests verts**.

**Suite à donner par l'admin** : les 100 articles `regression_pending` du step 1 peuvent désormais être ré-attaqués via un nouveau step R3 → la `LossyRule` lèvera le blocage texte/mots, et les structures (images, headings, liens) restent vérifiées.

### Auto-désactivation des règles épuisées après scan complet

Suite au verrou « règle appliquée à tout le corpus » (cf. section ci-dessous), une règle dans l'état `complete` (au moins une fois appliquée, plus aucune occurrence dans le corpus) gardait `enabled = true` et continuait à tourner inutilement dans le pipeline — sur 758 articles × N règles épuisées, plusieurs milliers de traversées DOM jetées.

À la fin de **chaque scan couvrant 100 % du corpus**, la SPA appelle désormais `POST /diagnostics/finalize-scan` qui :

1. **Vérifie la couverture** via `DiagnosticsRepository::is_corpus_fully_scanned()` : `count(diagnostics) >= count(wp_posts WHERE post_status='publish' AND post_type IN <F8>)`. Le `>=` (non `===`) tolère le drift d'une dépublication post-scan.
2. **Évalue chaque règle de `PresetRegistry::PRESETS`** via `Core/Lifecycle/RuleAutoDisabler`. Une règle est désactivable si :
   - état `complete` (applicable_count == 0 ET `last_applied_at !== null`),
   - jamais auto-désactivée auparavant (champ `auto_disabled_at` absent — garde-fou anti-récidive),
   - actuellement `enabled = true` (on ne s'attribue pas un état utilisateur).
3. **Persiste** `enabled = false` + `auto_disabled_at = gmdate('Y-m-d H:i:s')` dans l'option `son100_htmln_presets`.

**Scope volontairement limité aux règles `complete`** : les règles `unused` (jamais appliquées, 0 occurrences) ne sont pas auto-désactivées car un `countMatches()` bugué donnerait aussi 0 et masquerait une régression silencieusement. À reconsidérer en v1.1+ avec un mode debug.

**UX** :

- **Onglet Normaliser** : à la fin d'un scan complet, une `<Notice success>` succincte sous `<ScanBar>` liste les IDs désactivés (« 3 règle(s) sans occurrence détectée ont été désactivées : R3, R4, R8. ») et renvoie l'utilisateur vers l'onglet Règles pour réactiver si besoin.
- **Onglet Règles** : le bandeau vert `complete` enrichit son libellé en « Appliquée à tout le corpus le X. Désactivée automatiquement. » quand `auto_disabled_at` est posé. Le toggle « Activée » reste **directement modifiable** (pas besoin de passer par « Réactiver pour cette session ») — la décision auto est posée mais l'utilisateur garde le contrôle final.
- **Garde-fou** : si l'utilisateur réactive manuellement une règle auto-désactivée, le marqueur `auto_disabled_at` reste posé en BDD — on ne re-désactive jamais après ce point, même si l'état redevient `complete`. La réactivation manuelle = signal explicite « laisse-moi gérer ».

**Câblage** :

- **Backend** :
  - `Core/Lifecycle/RuleAutoDisabler` (nouveau) — orchestrateur ; reçoit `SettingsRepository`, `DiagnosticsRepository`, `StepsRepository`. Méthode unique `evaluate_and_disable(): {disabled: list<string>, fully_scanned: bool}`.
  - `DiagnosticsRepository::count_total()` (nouveau) — COUNT(*) trivial.
  - `DiagnosticsRepository::is_corpus_fully_scanned(SettingsRepository $settings)` (nouveau) — compare la couverture diagnostics vs corpus F8 publish.
  - `Rest/DiagnosticsController::finalize_scan()` (nouveau endpoint `POST /diagnostics/finalize-scan`) — délègue au RuleAutoDisabler injecté en constructor (param optionnel pour rétro-compat tests).
  - `Rest/PresetsController::preset_to_array()` — expose `auto_disabled_at` (nullable string) dans le payload de chaque preset.
  - `Plugin::build_rest_controllers()` — câblage DI complet.
- **SPA** :
  - `api/diagnostics.js` : `finalizeScan()` helper.
  - `hooks/useScanBatch.js` : appel finalizeScan à la fin de la boucle de chunks, expose `lastFinalize` dans le retour du hook. Failure non bloquante (le scan reste valide même si le finalize échoue).
  - `views/Normalize.jsx` : propage `lastFinalize` à `<ScanBar>`.
  - `views/Normalize/ScanBar.jsx` : nouvelle `<Notice success>` dismissible.
  - `views/Rules.jsx` : `<RuleCompletionBanner>` enrichi (libellé « Désactivée automatiquement »), `isLocked` désactivé quand `auto_disabled_at` est posé pour laisser le toggle directement modifiable.

**Tests** : 17 nouveaux cas (10 `RuleAutoDisablerTest` couvrant désactivation, idempotence, scopes pending/unused/déjà-auto-désactivée/déjà-off-manuel, mixed-state + `DiagnosticsRepositoryTest` : `count_total` + 6 cas `is_corpus_fully_scanned`). Stats globales : **961 tests verts**.

### Verrou « règle appliquée à tout le corpus »

Chaque carte de l'onglet **Règles** affiche désormais un **état de complétion** qui verrouille les contrôles (case « Sélectionnée », toggle « Activée par défaut », champs de paramètres) lorsque la règle n'a plus rien à faire sur le corpus.

**Trois états dérivés côté serveur** (`GET /presets`) :

- `pending` — `applicable_count > 0` : au moins un article comporte encore une occurrence de la règle. Aucune marque visuelle, carte normalement opérationnelle.
- `complete` — `applicable_count == 0` **et** la règle a déjà été appliquée au moins une fois (présente dans le `applied_rules` d'un pas fini). Bandeau vert « ✓ Appliquée à tout le corpus le *<date>* » + verrou actif. Le bouton **« Réactiver pour cette session »** lève le verrou localement (state React, jamais persisté) pour autoriser un re-test ponctuel après modification de la règle.
- `unused` — `applicable_count == 0` mais la règle n'a jamais été appliquée. Bandeau gris discret « ○ Aucun article ne nécessite cette règle. » Pas de verrou (la règle peut tout de même être lancée).

**Justification du verrou permanent** : sur ce corpus le pipeline est figé (758 articles publiés, pas d'import futur). Une règle qui ne capture plus aucune occurrence après application complète n'a plus de raison d'être rejouée — sauf si on modifie sa logique pour la rendre plus stricte/permissive, d'où l'override par session.

**Câblage** :

- **Backend** :
  - `StepsRepository::last_applied_for_rule(string $rule_id): ?string` — `MAX(finished_at)` filtré sur `JSON_SEARCH(applied_rules, 'one', %s)`. Sémantique stricte, pas de faux positif `R1`/`R10`.
  - `PresetsController` accepte désormais `?StepsRepository $steps` et `?DiagnosticsRepository $diagnostics` (paramètres optionnels — la réponse REST reste compatible si non câblés). Trois champs ajoutés au payload `preset` : `last_applied_at` (string MySQL ou null), `applicable_count` (int), `completion_state` (`pending` | `complete` | `unused`).
  - `Plugin.php` câble le repo des pas + le repo des diagnostics dans le contrôleur.
- **SPA** :
  - `Rules.jsx` : `useState( overrideLock )` par carte (éphémère). `isLocked = 'complete' === completionState && !overrideLock` désactive les contrôles. Nouveau composant `<RuleCompletionBanner>` rendu entre l'en-tête et la description.
  - SCSS : `.htmln-rule__completion` + modifiers `--complete` (vert) / `--unused` (gris), `.htmln-rule--locked` (carte assombrie + interactions bloquées).

**Tests** : 5 cas unitaires sur `StepsRepository::last_applied_for_rule` (null, empty string, datetime, vérification du SQL `MAX(finished_at) + finished_at IS NOT NULL + JSON_SEARCH('one')`, absence de `LIKE`). Stats globales : **944 tests verts**.

### Nouvelle règle R16 — strip des préfixes de titre (numéros, puces)

Retire les préfixes typographiques placés en tête d'un `<h1>`-`<h6>` :

```html
<h2>1. Pourquoi bioclimatique ?</h2>   →  <h2>Pourquoi bioclimatique ?</h2>
<h2>• Spécialiste de la terrasse</h2>  →  <h2>Spécialiste de la terrasse</h2>
<h3>— Sous-titre</h3>                  →  <h3>Sous-titre</h3>
```

**Convention sémantique** : un heading porte un titre, pas une marque de liste. La numérotation appartient soit à une vraie `<ol>` (si les sections sont courtes), soit au thème CSS via `counter-reset` + `::before`. De même les puces appartiennent à `<ul>`.

**Préfixes ciblés** :

- **Numéros** : 1-2 chiffres + `.` / `)` / `°` + espace (« 1. », « 23) », « 5° »). Refus : « 100. » (3 chiffres = peu probable pour un titre) et « 1.5 » (sans espace = décimal volontaire).
- **Puces** : `•` `‣` `►` `▸` `*` + espace.
- **Tirets** : `-` `–` `—` + espace.

**Walk DOM** : trouve le préfixe même s'il est emballé dans un inline (`<h2><strong>1.</strong> Texte</h2>` → strip fonctionne). Préfixes en milieu de chaîne préservés (« Section avec 1. dans le milieu »).

**Audit corpus MMM-2** : 7 articles concernés, 38 strippings au total. Cas notables :
- **1065** « Les pergolas bioclimatiques en 8 questions/réponses » : 8 h2 numérotés
- **2013** « Top 4 objets déco cultes Noël 2017 » : 4 h2 numérotés
- **3552** « 5 idées pour repenser son intérieur » : 4 h2 numérotés
- **3787** « Bien utiliser son poêle à bois » : 3 h2 numérotés
- **892** « Terrasse en bois » : 5 h2 commençant par `•`

**Position pipeline** : entre R15 (fusion d'inlines) et R9/R12/R11 (transformations h4). Place choisie pour que :
- R15 ait d'abord tassé les `<strong>` adjacents au préfixe ;
- R11/R12/R9 reçoivent un h4 sans préfixe (le `<figcaption>`/p.chapo qui en naîtra sera propre) ;
- R2 nettoie en fin de pipeline les headings devenus vides après strip (cas pathologique « `<h2>1. </h2>` » qui ne contient que le préfixe).

**Nouvel ordre canonique du pipeline** :

```
R3 → R4 → R8 → R13 → R14 → R6 → R7 → R5 → R15 → R16 → R9 → R12 → R11 → R10 → R1 → R2
```

**Câblage** :

- `includes/Core/Rules/StripHeadingPrefixRule.php` (nouveau, DOM-based, ~160 LOC).
- `includes/Core/Registry/PresetRegistry.php` — `PRESETS` const inclut `'R16'` entre `'R15'` et `'R9'`, factory + metadata.
- `includes/Activator.php` — defaults étendu.
- `includes/Rest/PresetsController.php` — `KNOWN_IDS` + `DEFAULTS` + regex `R(?:1[0-6]|[1-9])`.
- SPA : `ALL_RULE_IDS`, `RULE_TOOLTIPS`, `RULE_DISPLAY_ORDER`, `RULE_EXAMPLES`.

**Tests** : 33 cas unitaires (préfixes numériques 1./23)/5°, puces • ‣ ► * et tirets - – —, niveaux h1-h6, préfixes wrappés dans `<strong>`/`<span>`, leading whitespace, multiple headings, negatives : pas de préfixe / 3 chiffres / sans espace après / mid-string / `<p>` ignoré, heading entièrement préfixe → vide, idempotence, countMatches). Stats globales : **939 tests verts**.

### R11 étendue — disposition contextuelle des h4 orphelins

R11 traitait jusqu'ici uniquement le pattern `<p><img></p><h4>texte</h4>` (fusion en `<figure>` + `<figcaption>`). Les **autres** `<h4>` du fragment étaient laissés intacts. Or la convention éditoriale MMM est que **tout `<h4>` est un détournement typographique** — jamais un vrai sous-titre de section.

R11 démote désormais chaque `<h4>` selon **3 cas** :

1. **`<p><img></p><h4>texte</h4>` adjacent** → `<figure><img><figcaption>texte</figcaption></figure>` (comportement historique).
2. **h4 orphelin juste après un chapô-lead seul** (un `<p class="chapo">` immédiatement devant, sans autre `<p class="chapo">` en amont dans le parent) → promotion en chapô-crédit `<p class="chapo">texte</p>`. Le gras éventuel est strippé par `ChapoFormatter`. Cas typique : « Photos : BlueCut Production », « LA RÉDACTION » détournés en h4.
3. **h4 orphelin ailleurs** (corps d'article, ou chapô ayant déjà ≥ 2 `<p class="chapo">`) → démotion en `<p><strong>texte</strong></p>`. Le h4 servait visuellement de texte fort, la sémantique est rendue explicite via `<strong>`.

**Cas écartés** (toujours délégués aux règles dédiées) :
- h4 vide → R2
- h4 contenant uniquement une image → R9
- h4 mixte image+texte → R12

**Article 3584** (cas d'usage qui a motivé l'extension) : structure historique `<h2>chapô</h2>&nbsp;<h4>Photos : BlueCut…</h4>` + plusieurs `<h4>` dans le corps. Avant l'extension, R11 ne touchait aucun h4. Après : (a) R13 démote le h2 chapô en `<p class="chapo">`, (b) R11 promote le h4 « Photos : … » en crédit chapô, (c) R11 démote les h4 du corps en `<p><strong>`. Résultat : 2 `<p class="chapo">` (lead + crédit) et 6 `<p><strong>` (sous-titres corps).

**Tests** : R11 passe de 31 à 41 cas (+10 pour le orphan handling). Stats globales : **906 tests verts**.

### Nouvelle règle R14 — marquage `class="chapo"` sur le 1er `<p>` phrase

Complément de R13 : ajoute la classe `chapo` au **premier paragraphe significatif** du fragment lorsqu'il porte une (ou plusieurs) phrase(s). Couvre les articles dont le chapô est déjà rédigé en `<p>` (pas en `<h2>` détourné).

```html
<p>Basée sur la région toulousaine, Laetitia Moreau de l'Atelier
In Vitro décline le verre en aménagement intérieur.</p>
```

devient :

```html
<p class="chapo">Basée sur la région toulousaine, Laetitia Moreau de l'Atelier
In Vitro décline le verre en aménagement intérieur.</p>
```

**Audit corpus** : 423 captures sur 758 articles SiteOrigin (chapô en `<p>` au lieu de `<h2>`).

**Critères de match identiques à R13** (cumulatifs) :

- ≥ 5 mots ;
- contient au moins une ponctuation `.` / `!` / `?` ;
- **strictement conservateur** : le premier élément significatif du fragment doit être un `<p>` (les paragraphes vides en tête sont sautés). Si le premier élément non-vide est un `<h2>` court, une image, une liste… on renonce.
- Idempotent : si le `<p>` a déjà la classe `chapo` (typiquement parce que R13 vient de démoter un h2-chapô), R14 ne fait rien.

**Préservation** : la classe `chapo` est **ajoutée** à l'attribut `class` existant (séparée par un espace), pas substituée. Les autres attributs (`style`, `id`, `data-*`…) restent inchangés.

**Couverture combinée R13 + R14** : ensemble, les deux règles couvrent **571 articles** sur 758 SO (75 %) avec un marquage `class="chapo"` homogène, qu'ils soient initialement en h2 ou en p.

**Position pipeline** : juste après R13. Nouvel ordre canonique :

```
R3 → R4 → R8 → R13 → R14 → R6 → R7 → R5 → R9 → R12 → R11 → R10 → R1 → R2
```

Invariants :

1. **R13 avant R14** : le chapô-h2 est démoté en `p.chapo` d'abord. Si R13 a fait son job, le premier `<p>` porte déjà `class="chapo"` → R14 idempotent skip. Sinon (article SO avec chapô-p directement, 423 cas), R14 marque ce p.
2. **R14 avant R6** : neutre car R6 ne touche pas à `class` (seulement `style`).

**Câblage** :

- `includes/Core/Rules/FirstParagraphChapoRule.php` (nouveau, DOM-based, ~220 LOC).
- `includes/Core/Registry/PresetRegistry.php` — `PRESETS` const inclut `'R14'` entre `'R13'` et `'R6'` ; factory + metadata.
- `includes/Activator.php` — defaults étendu.
- `includes/Rest/PresetsController.php` — `KNOWN_IDS` + `DEFAULTS` + regex `R(?:1[0-4]|[1-9])`.
- SPA : `ALL_RULE_IDS`, `RULE_TOOLTIPS`, `RULE_DISPLAY_ORDER`, `RULE_EXAMPLES`.

**Tests** : 30 cas unitaires (positifs, attributs préservés, idempotence, négatifs h2/image/liste/short/no-punctuation, ≥5 mots seuil, countMatches, fixture intégrale) + 1 cas intégration `apply_filters`. Stats agrégées : **844 tests verts** (813 → +30 R14 + 1 intégration).

**Note opérationnelle** : `countMatches` sur le `post_content` brut SiteOrigin renvoie 1 capture (et non 423) car le premier élément du fragment est un `<div id="pl-…">` (panel-layout). R14 est conçue pour opérer sur le contenu **interne** du widget Editor, qui sera fourni par SO to Blocks après désencapsulation. Les 423 captures de l'audit se matérialiseront dans ce contexte.

### Nouvelle règle R13 — promotion `<h2>` chapô → `<p class="chapo">`

Convertit le **premier** `<h2>` du fragment en `<p class="chapo">` lorsqu'il porte une (ou plusieurs) phrase(s) — c'est-à-dire un **chapô** au sens journalistique (lead / standfirst) et non un sous-titre de section.

```html
<h2>Il est rare de rénover sa maison en une unique session de travaux.
La plupart du temps, ils s'échelonnent par tranches sur plusieurs années.</h2>
```

devient :

```html
<p class="chapo">Il est rare de rénover sa maison en une unique session de travaux.
La plupart du temps, ils s'échelonnent par tranches sur plusieurs années.</p>
```

**Audit corpus** : 148 captures sur 758 articles SiteOrigin (h2 ≥ 5 mots + ponctuation `.`/`!`/`?`).

**Pourquoi** : sémantiquement, un `<h2>` est une tête de section (en complément d'un `<h1>` titre d'article). L'usage du `<h2>` pour un chapô était une commodité typographique de l'éditeur SiteOrigin (Helvetica large) sans intention de hiérarchie de section. Le HTML5 ne fournit pas de balise dédiée au chapô ; la convention française `<p class="chapo">` est compatible Gutenberg `core/paragraph` via l'attribut `className`.

**Critères de match** (cumulatifs) :

- élément `<h2>` ;
- **uniquement le premier** h2 du fragment dans l'ordre du document (les h2 ultérieurs sont de vrais sous-titres de section et restent intacts) ;
- `textContent` (NBSP normalisé) compte ≥ 5 mots ;
- contient au moins une ponctuation `.` / `!` / `?` (signature d'une phrase entière).

**Préservés** dans le `<p class="chapo">` : tous les enfants inline du `<h2>` (`<a>`, `<em>`, `<strong>`, `<br>`, `<span>`…). Les attributs du `<h2>` (style, id, class) sont abandonnés — le `<p>` produit n'a que `class="chapo"` ; les éventuels styles inline auraient été stripés par R6 en aval de toute façon.

**Position pipeline** : entre R8 et R6. Nouvel ordre canonique :

```
R3 → R4 → R8 → R13 → R6 → R7 → R5 → R9 → R12 → R11 → R10 → R1 → R2
```

Invariants assurés :

1. **R8 avant R13** : récupération sémantique des styles dans les inlines du chapô (bold/italic) faite avant qu'on perde les attributs du `<h2>`.
2. **R13 avant R6** : la transformation produit un nouveau `<p>` sans `style`, R6 trouve juste à dépouiller les inlines descendants restants.

**Câblage** :

- `includes/Core/Rules/H2ChapoToParagraphRule.php` (nouveau, DOM-based, ~190 LOC).
- `includes/Core/Registry/PresetRegistry.php` — `PRESETS` const inclut `'R13'` entre `'R8'` et `'R6'` ; factory + metadata complétés.
- `includes/Activator.php` — `seed_presets()` ajoute `'R13' => array( 'enabled' => true )`. Propagé sur sites déjà activés via le merge `$existing + $defaults`.
- `includes/Rest/PresetsController.php` — `KNOWN_IDS` étendu à `R13`, regex de la route REST passe de `R(?:1[012]|[1-9])` à `R(?:1[0-3]|[1-9])`, `DEFAULTS` ajoute `'R13' => array()`.
- `assets/src/admin-spa/utils/ruleLabels.js` — `RULE_TOOLTIPS` ajoute `R13: 'Promotion h2-chapô'`, `RULE_DISPLAY_ORDER` ajoute `R13: 13`.
- `assets/src/admin-spa/views/Rules.jsx` — `RULE_EXAMPLES.R13`.
- `assets/src/admin-spa/store/index.js` — `ALL_RULE_IDS` étendu à `R13`.

**Tests** :

- `tests/Unit/Rules/H2ChapoToParagraphRuleTest.php` — 26 cas (positifs : phrase basique, multi-phrases, point/exclam/interrog, inlines em/strong/a, lien, `<br>`, ≥ 5 mots ; abandon attributs du h2 ; seuls le premier h2 ciblé ; négatifs : h2 court < 5 mots, sans ponctuation, vide, whitespace, h1/h3/h4/h5/h6 jamais touchés, `<p>` phrase en tête jamais touché, exactement 4 mots sous seuil ; seuil 5 mots inclusif ; countMatches + idempotence ; fixture intégrale).
- `tests/fixtures/html/h2-chapo-input.html` + `h2-chapo-expected.html` — fixture issue du corpus (post 491 anonymisé).
- `tests/fixtures/html/full-pipeline-*.html` — section N (placée en tête pour être le 1er h2 vu par R13).
- `tests/Integration/PublicApiTest.php` — `test_filter_promotes_h2_chapo_to_paragraph` via `apply_filters('htmln/normalize', …)`.
- `tests/Unit/Rest/PresetsControllerTest.php` — `test_list_returns_thirteen_presets_in_canonical_order` (count 12 → 13, regex maj).
- `tests/Unit/ActivatorTest.php` — boucle `seed_presets` étendue à R13.
- `tests/Unit/Rest/DiagnosticsControllerTest.php` — facets `applicable_rules` étendus à R13.

Stats agrégées : **813 tests verts** (786 avant + 26 R13 + 1 intégration), PHPStan baseline inchangée.

### Nouvelle règle R12 — `<h4>` mixtes image + légende → `<figure>` multi-img

Variante **inline** de R11 (qui traite l'adjacence `<p><img></p><h4>texte</h4>`). R12 cible le pattern où **image et légende sont mixées dans le même `<h4>`** — courant dans le corpus MMM-2 où le rédacteur emboîtait directement :

```html
<h4>
  <a href="big.jpg"><img src="thumb.jpg" alt="..."></a>
  Texte de légende qui décrit l'image.
</h4>
```

devient :

```html
<figure>
  <a href="big.jpg"><img src="thumb.jpg" alt="..."></a>
  <figcaption>Texte de légende qui décrit l'image.</figcaption>
</figure>
```

**Audit corpus** : 136 captures sur ~110 articles (3ᵉ famille de h4-img après les 199 unwrappés par R9 et les 477 adjacences couvertes par R11).

**Mode tolérant multi-images** : sur les 6 articles du corpus dont un `<h4>` contient 2 images partageant une légende commune (IDs 756, 1238, 1291, 1602, 3534, 6654), R12 produit un `<figure>` à **deux `<img>` consécutifs suivis d'un `<figcaption>` unique** — forme HTML5 normative pour un groupe d'images partageant une caption (cf. exemple de la spec). SO to Blocks consommera ensuite la `<figure>` multi-img pour produire un bloc `core/gallery`.

**Critères de match** (cumulatifs) — implémenté DOM-based dans `HeadingMixedToFigureRule` :

- `<h4>` uniquement (convention corpus, aligné sur R11) ;
- contient ≥ 1 `<img>` descendant ;
- `textContent` (NBSP normalisé, trim) non vide après retrait des wrappers d'image — il faut une caption réelle.

**Nettoyage bordures de caption** : les `<br>`, commentaires et text nodes blancs/NBSP en début/fin de caption sont retirés (séparateurs visuels parasites entre image et texte). Le premier text node restant est lui-même trimé en tête.

**Wrappers préservés** dans la `<figure>` : `<a>` (lightbox), `<picture>`, `<figure>` interne. Inlines préservés dans la `<figcaption>` : `<a>` textuel, `<em>`, `<strong>`.

**Position pipeline** : entre R9 et R11. Nouvel ordre canonique :

```
R3 → R4 → R8 → R6 → R7 → R5 → R9 → R12 → R11 → R10 → R1 → R2
```

Invariants assurés :

1. **R9 avant R12** : les `<h4>` image-seule sont déjà désencapsulés ; R12 n'agit que sur les h4 mixtes restants.
2. **R12 avant R11** : R12 traite le pattern *inline* (image+texte dans le même h4), R11 le pattern *adjacent* (`<p><img></p><h4>texte</h4>`). Pas de chevauchement, mais l'ordre garantit que les `<figure>` produites par R12 ne perturbent pas la détection d'adjacence de R11 en aval.

**Câblage** :

- `includes/Core/Rules/HeadingMixedToFigureRule.php` (nouveau, DOM-based, ~290 LOC).
- `includes/Core/Registry/PresetRegistry.php` — `PRESETS` const inclut `'R12'` entre `'R9'` et `'R11'` ; factory + metadata complétés.
- `includes/Activator.php` — `seed_presets()` ajoute `'R12' => array( 'enabled' => true )`. Propagé sur sites déjà activés via le merge `$existing + $defaults`.
- `includes/Rest/PresetsController.php` — `KNOWN_IDS` étendu à `R12`, regex de la route REST passe de `R(?:1[01]|[1-9])` à `R(?:1[012]|[1-9])`, `DEFAULTS` ajoute `'R12' => array()`.
- `assets/src/admin-spa/utils/ruleLabels.js` — `RULE_TOOLTIPS` ajoute `R12: 'Titres mixtes image + légende'`, `RULE_DISPLAY_ORDER` ajoute `R12: 12`.
- `assets/src/admin-spa/views/Rules.jsx` — `RULE_EXAMPLES.R12` (+ exemples manquants R10/R11 ajoutés au passage).

**Tests** :

- `tests/Unit/Rules/HeadingMixedToFigureRuleTest.php` — 28 cas (positifs : single img + text, anchor wrap, br/nbsp dropped, inlines em/strong/a préservés, text avant image, 2 imgs avec caption commune, 3 imgs ; négatifs : h4 sans image, h4 image-seule (R9), h2/h3/h5/h6 mixtes, paragraph mixte ; multiple in sequence ; mixed match/non-match ; countMatches + idempotence ; fixture intégrale).
- `tests/fixtures/html/heading-mixed-input.html` + `heading-mixed-expected.html` — fixture issue du corpus (posts 756 et 892 anonymisés).
- `tests/fixtures/html/full-pipeline-*.html` — section M ajoutée pour valider R12 dans la pipeline complète.
- `tests/Integration/PublicApiTest.php` — `test_filter_converts_h4_mixed_image_caption_to_figure` + `test_filter_converts_h4_multi_image_to_figure` via `apply_filters('htmln/normalize', …)`.
- `tests/Unit/Rest/PresetsControllerTest.php` — `test_list_returns_twelve_presets_in_canonical_order` (count 11 → 12, regex maj).
- `tests/Unit/ActivatorTest.php` — boucle `seed_presets` étendue à R12.
- `tests/Unit/Rest/DiagnosticsControllerTest.php` — facets `applicable_rules` étendus à R12.

Stats agrégées : **786 tests verts** (756 avant + 28 R12 + 2 intégration), PHPStan baseline inchangée.

### Renommage global Pn → Rn + « Préréglage » → « Règle »

Renommage **systémique** de tous les identifiants de règles internes (`P1..P11` → `R1..R11`) et du terme « préréglage » → « règle » dans toute l'interface, la documentation et le code. Objectif : alignement complet de l'interface, du nommage interne et de la documentation utilisateur — la cohabitation `P9` interne vs « préréglage » côté UI vs « règle » côté CDC créait une dissonance qu'on supprime ici.

**Périmètre code** (rename atomique via `sed -E 's/\bP(1[01]|[1-9])\b/R\1/g'` sur les chaînes littérales et les commentaires) :

- 12 classes de règles : `id()` retournent désormais `R1`..`R11` (au lieu de `P1`..`P11`).
- `Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry::PRESETS` const + factory `build_rule()` (case statements) + metadata.
- `Activator::seed_presets()` defaults (clés `R1..R11`).
- `Cent_Son\Html_Normalizer\Rest\PresetsController::KNOWN_IDS` + `DEFAULTS` + route REST `/presets/(?P<id>R(?:1[01]|[1-9]))` (auparavant `P(?:…)`).
- SPA : `RULE_TOOLTIPS`, `RULE_DISPLAY_ORDER` (`assets/src/admin-spa/utils/ruleLabels.js`), `RULE_EXAMPLES` (`Rules.jsx`), composants `R5Params`/`R6Params`/`R7Params`/`R8Params` (auparavant `P5Params`…).
- Tests : ~280 references mises à jour (PHPUnit assertions, fixtures `tests/fixtures/html/*`, `ActivatorTest`, `PresetsControllerTest`, `DiagnosticsControllerTest`, `PipelineTest`, `PresetRegistryTest`).
- Docs : README, CHANGELOG (cette entrée), descriptions des règles dans `PresetRegistry::get_all_presets_metadata()`.

**Migration BDD (sandbox `ma-maison-mag-2`)** appliquée via `wp eval` one-shot :

1. Option `son100_htmln_presets` : 11 clés `P1..P11` renommées en `R1..R11` (avec leur config `enabled`/`params` intacte).
2. Tables `wp_son100_htmln_diagnostics.matching_rules` (JSON `rule_id`) et `wp_son100_htmln_steps.applied_rules` (JSON list) : 0 ligne avait des Pn (sandbox fraîche post-rc4, pas de scan complet récent). Le pattern de migration reste à appliquer sur tout autre site déjà activé (cf. instructions plan).

**Périmètre conservé volontairement** (architecture interne, hors UI) :

- Classes `PresetRegistry`, `PresetsController`, `PresetsPage`, `UserRulesRepository` : les noms internes restent. Renommer les classes implique de toucher namespaces, autoload, ServiceProvider, factories de tests — bénéfice incrémental zéro pour l'utilisateur, coût significatif. Le commit reste focalisé sur ce qui change l'expérience.
- Méthodes `is_preset_enabled`, `set_preset_config`, `get_preset_config`, `get_all_presets_metadata` : idem (API interne stable).
- Clé d'option `son100_htmln_presets` : conserve le préfixe historique ; aucun bénéfice à renommer en `son100_htmln_rules` (migration BDD additionnelle inutile, casserait toute compatibilité descendante).

**Terme « préréglage » → « règle »** : remplacé partout sauf dans les identifiants de code conservés ci-dessus. Inclut UI strings (`__()`), descriptions HTML rendues côté SPA, libellés admin V0.1, commentaires de code. Corrections grammaticales accompagnantes (`un règle` → `une règle`, `du règle` → `de la règle`, etc.). Mises à jour de comptes : `Les 9 préréglages` / `R1..R8` etc. → `Les 11 règles` / `R1..R11`.

**Stats agrégées post-rename** : **756 tests verts** inchangés, PHPStan baseline inchangée, lint-js 0, build SPA OK, filtre `htmln/normalize` validé via `wp eval` après migration BDD.

### Onglet Règles — layout 3 colonnes

Refonte du layout de l'onglet *Règles* en **CSS Grid 3 colonnes** (au lieu d'une pile verticale `flex column` mono-colonne) pour réduire le scroll vertical et permettre une comparaison visuelle plus rapide entre règles.

- `.htmln-rules__list` : `grid-template-columns: repeat(3, minmax(0, 1fr))` avec breakpoints `2` cols à ≤ 1400 px et `1` col à ≤ 900 px.
- Cards compactées : `padding 20px 24px` → `16px 18px`, `min-width: 0` pour autoriser la troncature.
- `.htmln-rule__header` passe en **flex column** (titre puis actions empilés) plutôt qu'en flex row qui devenait illisible à 340 px de large.
- `.htmln-rule__actions` empile les 2 contrôles (« Sélectionnée pour le prochain lot » + « Activée par défaut ») verticalement.
- `.htmln-rule__example` (Avant/Après) toujours empilé verticalement dans la card — l'ancienne grille `1fr 1fr` devenait illisible à 235 px par colonne.

Aucun changement comportemental — tous les contrôles existants restent fonctionnels, seule la disposition change.

### Nouvelle règle R11 — `<h4>` détournés en légende d'image → `<figcaption>`

Convertit le pattern récurrent du corpus MMM-2 où un `<h4>` placé immédiatement après un `<p>` contenant uniquement une image jouait le rôle de légende (faute de `<figcaption>` côté SiteOrigin Editor) :

```html
<p><a href="big.jpg"><img src="thumb.jpg" alt="…"></a></p>
<h4>Texte de légende</h4>
```

devient :

```html
<figure>
  <a href="big.jpg"><img src="thumb.jpg" alt="…"></a>
  <figcaption>Texte de légende</figcaption>
</figure>
```

**Audit corpus** : 482 captures single-image sur ~180 articles (484 totales avec multi-images non traités).

**Critères de match** (cumulatifs) — implémenté DOM-based dans `HeadingCaptionToFigcaptionRule` (`includes/Core/Rules/`) :

- niveau de titre **`<h4>` uniquement** — les `<h2>`/`<h3>`/`<h5>`/`<h6>` restent intacts (vrais sous-titres du corpus) ;
- le `<h4>` contient du texte (sinon P2/P9 s'en chargent) ;
- son frère élément précédent est un `<p>` (whitespace text-nodes entre les deux tolérés) ;
- ce `<p>` contient **exactement une** `<img>` descendante (mapping légende→image non trivial sinon — 2 cas corpus multi-image non traités, limite assumée) ;
- son `textContent` (NBSP normalisé, trim) est vide (pas de texte autour de l'image).

**Inlines préservés** dans la `<figcaption>` : `<a>`, `<em>`, `<strong>`, `<br>`. Wrapper d'image préservé dans la `<figure>` (`<a>`, `<figure>`, `<picture>` interne).

**Position pipeline** : entre P9 et P10. Nouvel ordre canonique :

```
P3 → P4 → P8 → P6 → P7 → P5 → P9 → P11 → P10 → P1 → P2
```

Invariants assurés :

1. **P9 avant P11** : tout `<h4>` restant après P9 a forcément du texte (les h4-image-seule sont désencapsulés en amont).
2. **P11 avant P10** : le `<p>` autour de l'image est encore présent — signal d'adjacence nécessaire à la détection de la paire image/légende. Après P10 le `<p>` aurait été retiré, le signal serait perdu.

**Niveau HTML uniquement** : aucune classe Gutenberg n'est injectée (`wp-block-image`, `wp-element-caption`, …) — c'est le rôle de SO to Blocks de produire la block grammar à partir du `<figure>` propre.

**Câblage** :

- `includes/Core/Registry/PresetRegistry.php` — `PRESETS` const inclut `'P11'` entre `'P9'` et `'P10'` ; factory `build_rule()` instancie `HeadingCaptionToFigcaptionRule` pour `case 'P11'` ; metadata `get_all_presets_metadata()` documente la règle avec ses limites.
- `includes/Activator.php` — `seed_presets()` ajoute `'P11' => array( 'enabled' => true )` dans les defaults. Propagé sur sites déjà activés via le merge `$existing + $defaults` (réactivation du plugin).
- `includes/Rest/PresetsController.php` — `KNOWN_IDS` étendu à `P11`, regex de la route REST passe de `P(?:10|[1-9])` à `P(?:1[01]|[1-9])`, `DEFAULTS` ajoute `'P11' => array()`.
- `assets/src/admin-spa/utils/ruleLabels.js` — `RULE_TOOLTIPS` ajoute `P11: "Titres légendes d'images"`, `RULE_DISPLAY_ORDER` ajoute `P11: 11`.

**Tests** :

- `tests/Unit/Rules/HeadingCaptionToFigcaptionRuleTest.php` — 31 cas (positifs : canonique, anchor wrap, figure interne, inlines em/strong/a, br, NBSP, br autour, whitespace, paires multiples, mixé ; négatifs : texte autour image, multi-image, h2/h3/h5/h6, sans `<p><img>` avant, vide, whitespace, commentaire intercalaire, non-`<p>` ; countMatches + idempotence ; fixture intégrale).
- `tests/fixtures/html/heading-caption-input.html` + `heading-caption-expected.html` — fixture d'article 491 anonymisée.
- `tests/fixtures/html/full-pipeline-*.html` — section L ajoutée pour valider P11 dans la pipeline complète.
- `tests/Integration/PublicApiTest.php` — `test_filter_converts_h4_caption_to_figcaption` via `apply_filters('htmln/normalize', …)`.
- `tests/Unit/Rest/PresetsControllerTest.php` — `test_list_returns_eleven_presets_in_canonical_order` (count 10 → 11, regex maj).
- `tests/Unit/ActivatorTest.php` — boucle `seed_presets` étendue à P10/P11.
- `tests/Unit/Rest/DiagnosticsControllerTest.php` — facets `applicable_rules` étendus à P10/P11.

Stats agrégés : **756 tests verts** (724 avant + 31 P11 + 1 intégration), PHPStan baseline inchangée.

### Naming cleanup — retour aux IDs internes en affichage SPA

Suppression du remapping famille `P1.1 / P1.2 / P2.1 / P2.2` introduit aux rc2/rc3 via `RULE_DISPLAY_LABELS` dans `assets/src/admin-spa/utils/ruleLabels.js`. La cohabitation `P1.1` côté SPA vs `P1` partout ailleurs (`README.md`, `CHANGELOG.md`, tests PHP, `PresetRegistry::PRESETS`, BDD, REST) créait une charge cognitive sans bénéfice tangible.

**Modifications** :

- `RULE_DISPLAY_LABELS` vidé (`{}`) — `getRuleLabel()` retombe sur l'ID lui-même via le fallback existant.
- `RULE_DISPLAY_ORDER` réordonné en **ordre naturel P1..P11** (au lieu de la séquence famille `P1, P10, P2, P9, P3, …`).
- `RULE_TOOLTIPS` conservé intégralement + ajout de P11.
- JSDoc d'en-tête réécrit : retire les choix éditoriaux famille, explicite le nouveau régime (affichage = ID interne, ordre naturel).
- Commentaires stale mis à jour dans `Rules.jsx`, `main.scss`, `DiffModal.jsx`, `ArticlesTable.jsx`, `FiltersBar.jsx`.

**Pas de migration BDD** — les IDs internes (`P1..P11`) étaient déjà la source de vérité côté PHP, BDD et REST. Le changement est purement JS/SPA. Les sites déjà activés conservent leurs choix `enabled` / paramètres ; seul l'affichage change.

**Effet utilisateur** : onglet *Règles* affiche désormais les cards avec libellés `P1, P2, P3, …, P11` (ordre naturel) au lieu de `P1.1, P1.2, P2.1, P2.2, P3, P4, P5, P6, P7, P8`. Tableau Normaliser, modale Diff, drawer d'historique : IDs bruts partout.

L'API publique (`getRuleLabel`) est conservée pour permettre un remapping futur ponctuel sans toucher les composants consommateurs.

### Modale Diff — boutons « Ouvrir sur Site 1 / Site 2 » + libellé « Surligner » sur le bouton pinceau

#### Boutons « Ouvrir sur… » sous le résumé des pertes

La modale Diff expose désormais, dans la colonne aside (sous le `MetricsDiffSummary` qui affiche les pertes éventuelles), la même paire de boutons compacts `[Old]` `[Prod]` qui existe dans le tableau Normaliser — permettant d'ouvrir l'article courant sur les domaines externes configurés en Réglages (Site 1 dev / Site 2 prod) directement depuis la modale, sans avoir à fermer pour aller cliquer dans la table.

- **Backend** : ajout de `permalink` au payload `POST /htmln/v1/posts/{id}/diff` via `(string) get_permalink( $post_id )`. Doc PHPDoc mise à jour.
- **Frontend** : import de `useExternalSites` dans `DiffModal`. Nouveau `useMemo` `externalUrls` qui calcule les hrefs Site 1 et Site 2 à partir de `payload.permalink` + config externe. Rendu de `<div class="htmln-diff-modal__open-on">` placé dans `__metrics-aside` juste après `MetricsDiffSummary`.
- **Factorisation** : la fonction `buildExternalUrl(permalink, baseUrl)` était auparavant locale à `ArticlesTable.jsx`. Extraite dans un nouvel utilitaire partagé `assets/src/admin-spa/utils/buildExternalUrl.js`. `ArticlesTable.jsx` l'importe désormais — la modale Diff aussi. Pas de duplication.
- **Conditionnel** : un bouton n'apparaît que si son toggle `enabled` est `true` en Réglages ET que l'URL composable n'est pas null (`buildExternalUrl` retourne null si l'un des ingrédients manque). Si les deux sites sont désactivés, toute la rangée disparaît.

Classes CSS dédiées modale (`.htmln-diff-modal__open-on`, `.htmln-diff-modal__open-on-btn`) — mêmes choix visuels que les classes de la table (`.htmln-articles-table__open-on-cell`, `__site-btn`) mais découplées pour ne pas coupler la modale au styling de la table.

#### Libellé « Surligner » sur le bouton pinceau

Le bouton pinceau de la rangée du haut de la toolbar (modale Diff) affichait jusqu'ici uniquement son icône. Il porte désormais le libellé `Surligner` à droite de l'icône — l'utilisateur identifie immédiatement la fonction sans passer par le tooltip. Tooltip et aria-label inchangés (le libellé textuel suffit, le tooltip riche reste utile pour expliquer l'effet exact).

Implémentation : ajout des children `{ __('Surligner', '100son-html-normalizer') }` sur le `<Button icon={brush}>`. Le composant WP gère l'alignement icône+texte nativement.

### Modale Diff — désactivation locale par règle (checkboxes inline)

L'utilisateur peut désormais cocher/décocher chaque règle individuellement depuis la table « Règles appliquées » de la modale Diff pour voir l'effet isolé d'une règle sur le diff de l'article courant — sans toucher à la sélection globale du SPA (qui s'applique à tous les articles).

#### Comportement

- **Nouvelle colonne checkbox** en 1ʳᵉ position de la table « Règles appliquées ». Cochée par défaut = règle active. Décocher relance immédiatement `POST /posts/{id}/diff` avec le sous-ensemble réduit → le diff (`html_before` vs `html_after`), les métriques et l'estimation de durée se mettent à jour.
- **Re-Worker automatique** si le pinceau est actif : le hook `useDiffHighlighting` détecte le changement de `html_before`/`html_after` et relance le Worker. Sur les articles lourds (par ex. 6690), chaque toggle déclenche un nouveau calcul de ~60 s — l'utilisateur peut suivre via le chrono `N / 391 s` habituel.
- **Garde-fou « au moins une règle »** : si l'utilisateur tente de décocher la dernière règle active, la checkbox correspondante est `disabled` avec tooltip natif. Empêche un payload `rule_ids: []` qui produirait un 400 backend.
- **Ligne désactivée reste visible** : l'union `payload.applied_rules ∪ localDisabledRules` garantit qu'une règle qu'on vient de décocher reste affichée (même si ses occurrences passent à 0 dans la nouvelle cascade) — sinon l'utilisateur ne pourrait plus la recocher. Opacité 0.55 + transition douce comme indication visuelle.
- **État éphémère** : `localDisabledRules` est un `useState(new Set())` jeté au unmount. Fermer/rouvrir la modale repart d'un état propre — pas de persistance, pas de bouton « Réinitialiser » (KISS).

#### Architecture

- **State** : `localDisabledRules: Set<string>` dans `DiffModal`.
- **Dérivations** : `effectiveRuleIds` (useMemo, alimentant `fetchDiff`), `visibleRules` (useMemo, alimentant la table), `isLastActiveRule` (helper pour le garde-fou).
- **Re-fetch** : `fetchDiff` est déjà un `useCallback` qui prend `[postId, effectiveRuleIds]` en deps ; le `useEffect` mount appelle `fetchDiff()` et se redéclenche naturellement quand `effectiveRuleIds` change. Pas de logique additionnelle.
- **UI** : `CheckboxControl` de `@wordpress/components` (pattern verbatim de `RulesFilterDropdown`).
- **Aucun changement backend** — `DiffController::compute_diff()` accepte déjà n'importe quel sous-ensemble de `rule_ids` (pas de cache, traitement indépendant).

#### Cas limites traités

- **Race condition Worker** : l'`useEffect` du hook `useDiffHighlighting` fait `terminate()` du Worker précédent quand `before`/`after` changent (pattern `requestIdRef` déjà en place). Pas de fuite.
- **Disparition d'une règle de la cascade** : si P6 désactivé fait passer P9 à 0 occurrences, P9 disparaît de `payload.applied_rules`. Mais comme `P9 ∉ localDisabledRules`, il disparaît aussi de `visibleRules` — comportement attendu (P9 « n'a plus rien à faire » dans ce sous-ensemble).
- **Modale ouverte depuis `RegressionModal`** : l'`initialPayload` court-circuite le fetch initial, donc le toggle ne déclenche actuellement pas de re-fetch dans ce contexte. Cas marginal — à adresser dans une itération ultérieure si besoin.

#### Stats

- 724 PHPUnit verts inchangés (aucun test PHP impacté).
- Bundle `admin-spa.js` : 148 → ~149 KiB (≈ 50 lignes JS supplémentaires).
- 0 lint:js.

### Fix — cascade du comptage `applied_rules` dans `DiffController`

Le payload REST `/posts/{id}/diff` retourne un tableau `applied_rules` qui liste, par règle activée, son nombre d'occurrences applicables. Avant ce fix, chaque règle voyait son `countMatches()` appelé sur **le même `$html_before` brut** (le post_content original), parallèlement au pipeline d'`apply()` qui produisait `$html_after` en cascade. Conséquence : P6, P9, P10 comptaient des occurrences dans des structures que P4 (Pinterest, qui tourne plus tôt dans l'ordre canonique) allait supprimer en amont.

Exemple sur l'article #6690 : P6 affichait `100 occurrences` parce qu'il voyait les 86 `<span style="z-index:8675309…">` Pinterest (signature forme B) que P4 retire. Sur le HTML réellement transformé par P6 (sortie post-P4), il n'y a en fait que 14 styles inline à nettoyer. Pareil sur 16020 (P6 : 46 → 13) et 374 (P6 : 24 → 0).

#### Fix

Fusion des deux passes dans `DiffController::compute_diff()` : au lieu d'appeler `Pipeline::applySubset()` puis une boucle `countMatches()` indépendante, on traverse maintenant les règles dans l'ordre canonique et, **à chaque étape**, on compte les occurrences sur l'état HTML courant **avant** d'appliquer la règle. Le `$current` final = `$html_after`. Le pattern `try/catch + check is_string + push warnings` est repris à l'identique de `Pipeline::run()` (qui reste utilisé tel quel par les autres consommateurs — pages V0.1).

Cleanup : la propriété `$pipeline` du constructeur de `DiffController` n'est plus utilisée → retirée (et son import + son passage dans `Plugin.php` et `DiffControllerTest.php`).

#### Effets observés

Les **totaux par article** restent identiques (74→76, 92, 233 pour 374/16020/6690), mais la **répartition par règle** change drastiquement parce que les paragraphes vides libérés par P4 sont maintenant attribués à P1 plutôt que comptés sur le HTML brut. Pour 6690 :

| Règle | Avant | Après |
|---|---:|---:|
| P4 (Pinterest) | 86 | 86 (inchangé) |
| P6 (styles inline) | 100 | **14** |
| P1 (paragraphes vides) | 32 | **118** |
| P7 (listes ASCII) | 3 | 3 |
| P10 (paragraphes-images) | 12 | 12 |

La constante `D_PER_OCCURRENCE = 60` dans `utils/estimateDiffSeconds.js` reste valide — la formule de prédiction du temps n'est pas affectée puisque les totaux ne bougent pas.

#### Sécurité

`html_after` reste bit-identique avant/après le fix : on reproduit exactement la cascade de `Pipeline::run()`, on ajoute juste le `countMatches()` à chaque étape. Aucun risque de régression sur le rendu du diff. 724 PHPUnit verts inchangés.

### Modale Diff — refonte du surlignage (Web Worker + Prism+marks fusionnés + chronomètre)

Refonte complète de la vue « Code source » de la modale Diff pour résoudre un freeze observé sur l'article #16020 (~28k chars de SiteOrigin avec `data-style='{"…":…}'` partout) où `diffWordsWithSpace` bloquait le main thread environ une minute — Firefox affichait alors « Stop script » et la modale ne s'affichait jamais.

#### 1. Calcul du surlignage déporté dans un Web Worker dédié

Nouveau fichier `assets/src/admin-spa/workers/diffWorker.js` + hook `hooks/useDiffHighlighting.js`. Le worker reçoit `{ id, before, after }`, exécute `diffWordsWithSpace` + Prism (cf. point 2), et retourne `{ removedHtml, addedHtml }`. Le hook gère cycle de vie (terminate à l'unmount, race-condition via `requestIdRef` quand l'utilisateur change rapidement d'article). Le main thread reste 100 % réactif pendant tout le calcul — modale scrollable, fermable, chrono qui tick.

L'event natif `error` du Worker est **logué** mais **n'updates plus l'état UI** : observé que Prism émet des warnings non fatals à l'init en contexte worker, qui s'affichaient à tort comme « Le calcul a échoué » avant que le résultat n'arrive. Seul un message `{ ok: false, error }` émis explicitement par notre propre `try/catch` worker signale désormais une vraie erreur.

#### 2. Coloration syntaxique Prism + surlignage diff **simultanés**

Auparavant le toggle pinceau était binaire : Prism OU marks, jamais les deux. Le commentaire historique dans `highlightHtmlWithDiff.js` qualifiait la fusion de « gros refactor » à cause du nesting HTML (un `<mark>` ne doit pas enjamber un `<span>` Prism, sinon HTML invalide).

Nouveau module `workers/mergePrismAndDiff.js` qui implémente la fusion proprement :
1. Prism est appliqué une seule fois sur la chaîne complète → produit le HTML coloré classique.
2. La sortie Prism est parsée token-par-token (balise vs run de texte) avec la regex `/(<[^>]+>)|([^<]+)/g`.
3. Les `<mark>` sont insérés aux frontières des fragments diffés. À chaque traversée de balise Prism, le `<mark>` ouvert est fermé avant et rouvert après — garantit du HTML valide.
4. Les entités HTML (`&lt;`, `&gt;`, `&amp;`, `&quot;`) sont reconnues comme **1 caractère source** chacune mais émises telles quelles, pour conserver octet-pour-octet la sortie Prism.

Le CSS était déjà prêt (commentaire prophétique `color: inherit` sur les `<mark>` « pour que les tokens Prism imbriqués conservent leur teinte »). Les couleurs Prism (vert pour `attr-value`, violet pour `attr-name`, bleu pour `tag`) restent visibles sous le fond jaune/vert clair des marks.

Le worker embarque Prism et ses langages (`prism-markup`) via `import` ; `self.Prism.disableWorkerMessageHandler = true` est posé **avant** l'import pour empêcher Prism d'attacher son propre handler `message` qui entrerait en conflit avec le nôtre. Bundle worker passe de 16 KiB à ~40 KiB (le main bundle reste à 145 KiB — Prism déjà présent, dédupliqué par webpack).

Fallback si la fusion plante : `try/catch` côté worker qui retombe sur l'ancien comportement (texte échappé + marks sans Prism). Garantit que la modale reste utilisable même sur un cas exotique non encore observé.

#### 3. Toggle pinceau **désactivé par défaut**, opt-in explicite

Sur les articles SiteOrigin lourds, même avec le Web Worker, le calcul peut prendre 60 s — pas la peine d'imposer l'attente sans le consentement de l'utilisateur. La modale ouvre maintenant en mode « Prism seul » (instantané). Un avertissement orange `⚠ Surlignage estimé : ~N s sur cet article.` s'affiche à côté du bouton pinceau pour les articles où l'estimation dépasse 5 s — l'utilisateur clique en connaissance de cause.

#### 4. Chronomètre + estimation token-based

Nouveau hook `hooks/useElapsedTime.js` qui chronomètre une opération asynchrone arbitraire (1 Hz). Pendant le calcul du worker, la toolbar affiche `⏳ Calcul du surlignage en cours… 12 / 391 s` (elapsed/estimated). À la fin : `✓ Surlignage calculé en 47 s` (persistant jusqu'au prochain calcul, utile pour comparer les articles).

L'estimation vient de `utils/estimateDiffSeconds.js`, calibré empiriquement sur 3 articles du corpus MMM-2 :

| Article | (N+M) | occ | D ≈ 60×occ | t prédit | t mesuré |
|---|---:|---:|---:|---:|---:|
| 374 | 12 326 | 76 | 4 560 | 8 s | 7 s |
| 16020 | 16 959 | 92 | 5 520 | 75 s | 65 s |
| 6690 | 23 287 | 233 | 13 980 | 391 s | >360 s |

Le coût per-op `c` varie d'un facteur ~16 entre petit et gros article (cause probable : GC pressure + JIT au-delà de seuils d'array dans jsdiff), modélisé par une fonction par paliers : 1,5×10⁻⁷ / 8×10⁻⁷ / 1,2×10⁻⁶.

Tokens estimés via `utils/countDiffTokens.js` qui mime exactement le tokenizer de `diffWordsWithSpace` (regex `(\s+|[()[\]{}'"]|\b)` + filtre des vides). Coût O(N), ~5 ms sur 28k chars — négligeable.

#### 5. Garde-fous taille pour `highlightHtml` (Prism direct, hors worker)

Quand le toggle pinceau est OFF, Prism colore directement le code dans `HighlightedCode`. Pour les articles très volumineux (> 80 000 chars), même Prism peut prendre quelques secondes — nouveau seuil `PRISM_MAX_CHARS = 80000` exporté par `utils/highlightHtml.js`. Au-delà, fallback `escapeHtml` (texte brut échappé, instantané) + Notice qui prévient l'utilisateur.

Mutualisation de l'échappement HTML dans un nouvel utilitaire `utils/escapeHtml.js` (réutilisé par les voies sync et worker).

#### 6. Layout toolbar et tableau Règles réorganisés

- **Toolbar 2 rangées** : la rangée du haut groupe pinceau + avertissement + chrono ; la rangée du bas regroupe Code source / Rendu HTML + verrou de défilement. Séparation sémantique qui aère l'UI quand le statut du calcul est visible.
- **Tableau « Règles appliquées »** sorti des 3 colonnes flexes et placé dans une grille 1fr/1fr (identique à `__pane-cols`) — son bord gauche s'aligne désormais sur le bord gauche du pane « Après » en dessous. `align-items: start` le colle en haut.
- **Tableau métriques enrichi** : nouvelle ligne `HTML brut (caractères)` (= `html_before.length + html_after.length`, utile pour le diagnostic des temps de calcul). Le label « Listes » devient « Listes (ul/ol/li) » pour clarifier que c'est un agrégat de containers + items (perte « Listes : +4 » sur 1 `<ul>` à 3 items = 1 + 3 = 4, prêtait à confusion).
- **Tableau Règles appliquées** : nouvelle colonne « Occurrences » (alignée à droite, monospace `tabular-nums`, séparateur vertical) — affiche le nombre exact de matches de chaque règle (P4 : 86, P6 : 14, etc.).

#### Stats build

- 715 → 724 PHPUnit verts (1577 assertions, suite inchangée — aucun test JS à toucher, le SPA n'en a pas).
- Bundle `admin-spa.js` : 142 → 148 KiB. Bundle worker : 16 KiB → 41 KiB (3.8 + 37 — chunks séparés chargés via `importScripts`). Total compressé négligeable.
- 0 lint:js, 22 PHPStan baseline (1 de moins après cleanup d'une dépendance morte dans `DiffController`).

### Normaliser — retrait de la colonne « Mots » du tableau

La métrique `metrics.words` (calculée par `MetricsCalculator::compute()` sur le `textContent` du `post_content`) n'est plus affichée dans le tableau Normaliser. La colonne « Mots » est retirée du `<thead>` et du `<tbody>` de `ArticlesTable`. Le commentaire JSDoc en tête du composant est mis à jour pour préciser que `words` reste exposée dans la modale Diff (`MetricsDiffBar`) où la comparaison avant/après a du sens — pas la peine de la dupliquer dans une vue de liste où elle n'apportait pas de signal d'action.

Aucun impact sur le backend : `metrics.words` continue d'être calculée et renvoyée dans le payload REST des diagnostics — uniquement le rendu du tableau change.

### Réglages — section Domaines externes : titres génériques, description, simplifications

- Renommage des fieldsets : **« Ancien site (« Old ») » → « Site 1 (dev) »** et **« Site de production (« Prod ») » → « Site 2 (prod) »** — titres génériques qui collent mieux à l'usage (les deux boutons sont configurables librement pour pointer vers n'importe quel environnement). Les clés internes côté backend (`old_url`, `prod_url`, etc.) restent inchangées pour ne pas migrer l'option BDD.
- **Nouvelle description** sous le titre « Domaines externes » : « Cette section vous permet d'afficher un ou deux boutons dans la liste des articles afin d'ouvrir la version de l'article en production « prod », sur un autre site de « dev » ou encore sur un site « old ». »
- **Suppression** des helpText « Default : … » sur les inputs Libellé et URL (les valeurs par défaut sont déjà affichées dans les champs eux-mêmes au premier chargement).
- **Suppression** du bouton « Restaurer les valeurs par défaut » et du callback `handleRestore` correspondant. Le `defaults` retourné par `useExternalSites` n'est plus consommé côté UI (le hook continue de l'exposer pour rester compatible avec une éventuelle utilisation future).

### Réglages — refonte du design de la page

Trois ajustements visuels sur l'onglet Réglages (zéro impact fonctionnel) :

- **Seuils en 2 colonnes** : les fieldsets « Seuils en pourcentage » et « Seuils en nombre absolu » sont désormais côte à côte sur la même rangée (flex 2 colonnes), avec `flex-wrap` qui rebascule en empilement vertical sur écran étroit. Largeur du formulaire passe de 720 px à 1100 px.
- **Largeur des inputs numériques contrainte à 80 px** via la classe `htmln-settings__field--narrow` (cf. SCSS) appliquée sur `FieldRow`. Un seuil γ est un nombre à 1-2 chiffres ; un input plein-largeur (~600 px par défaut WP) était inutile et visuellement gênant.
- **Domaines externes sur une ligne** : pour chaque site (Old / Prod), les 3 contrôles `[Toggle Afficher] [Libellé] [URL]` sont désormais sur une seule ligne horizontale (flex row, `align-items: flex-end`). Le libellé est contraint à 100 px max (5 chars + padding), l'URL prend le reste via `flex: 1 1 320px`.

Aucun changement de comportement, juste de la cosmétique CSS + restructuration JSX dans `ExternalSiteFieldset`. 715 PHPUnit verts inchangés.

### Réglages — libellé personnalisable et toggle d'affichage pour chaque bouton Old / Prod

L'option `external_sites` passe de 2 à 6 clés. Chaque site (Old / Prod) expose désormais 3 champs configurables dans l'onglet Réglages :

| Champ | Type | Validation |
|---|---|---|
| `<site>_url` | URL absolue | regex `^https?://host`, slash final retiré, fallback default si invalide (inchangé) |
| `<site>_label` | Libellé du bouton (texte) | trim, max **5 caractères Unicode** via `mb_substr`, fallback default si chaîne vide |
| `<site>_enabled` | Booléen (toggle d'affichage) | `filter_var FILTER_VALIDATE_BOOLEAN` (accepte aussi `'true'` / `'false'` strings) |

Defaults : `old_label='Old'`, `prod_label='Prod'`, les deux `_enabled = true`.

**Effet côté tableau Normaliser** : pour chaque ligne d'article, le bouton (Old/Prod) ne s'affiche que si son toggle `_enabled` est `true` ET si l'URL est valide ET si le permalien permet de composer une URL cible. Le libellé du bouton vient désormais de `<site>_label` (avec fallback `Old`/`Prod` si l'option n'est pas encore chargée).

#### Backend

- `SettingsRepository::EXTERNAL_SITES_DEFAULTS` étendu à 6 clés.
- `SettingsRepository::normalize_external_sites()` refactoré pour dispatcher par suffixe de clé (`_url` / `_label` / `_enabled`) avec une normalisation typée. Nouveau helper privé `ends_with()` (polyfill PHP 8.0 pour `str_ends_with`).
- Constante `EXTERNAL_SITE_LABEL_MAX_LENGTH = 5`.

#### Frontend

- `views/Settings.jsx` : extraction d'un sous-composant `<ExternalSiteFieldset prefix="old|prod" />` qui rend les 3 contrôles (`ToggleControl` Afficher le bouton + `TextControl` libellé `maxLength={5}` + `TextControl` URL). Le state `formValues` du composant parent porte les 6 clés ; helper `initFromSites` factorise l'init depuis sites / defaults / normalized.
- `views/Normalize/ArticlesTable.jsx` : chaque bouton (Old/Prod) testé sur `externalSites.<prefix>_enabled` ET buildExternalUrl ; libellé = `externalSites.<prefix>_label.trim() || fallback __('Old'/'Prod')`. JSDoc de la prop `externalSites` mis à jour.

#### Tests

- +9 PHPUnit `SettingsRepositoryTest` couvrant : labels custom acceptés, troncature à 5 chars (ASCII), troncature en codepoints Unicode (`ééééé` ≠ 5 bytes), fallback sur label vide / non-string, toggle accepté en booléen, toggle accepté en string `'true'`/`'false'` (JSON), default `true`, payload partiel (seules les clés envoyées sont modifiées, les autres restent au default).
- 2 tests existants ajustés (`test_external_sites_returns_defaults` : 6 clés attendues ; `test_external_sites_ignores_unknown_keys` : assertCount 2 → 6).
- **715 PHPUnit verts** (vs 706, +9, 1554 assertions), PHPStan baseline inchangée, lint propre.

### Modale Diff — ajustements UI post-3e colonne

Trois micro-ajustements visuels sur le bandeau métriques :

- **Verrou de défilement activé par défaut** (`scrollSync: true`). C'est l'usage dominant de la modale Diff (comparer deux passages alignés). Le bouton cadenas permet de désactiver pour scroller un panneau indépendamment.
- **Tableau « Règles appliquées » aligné à gauche** dans sa colonne (`align-items: flex-start` sur `.htmln-diff-modal__metrics-rules` — sans ça le `<h3>` et le `<table>` étaient stretchés sur toute la largeur de la 3e colonne, donnant un alignement visuel sur le bord droit).
- **Tableau « Règles appliquées » accolé aux boutons** : `.htmln-diff-modal__metrics-aside` passe de `flex: 1 1 280px` à `flex: 0 1 auto` pour que la colonne du milieu ne s'étire plus afin de remplir tout l'espace dispo entre la table métriques et la table règles. La 3e colonne suit désormais immédiatement les boutons avec le seul `gap: 20px` du flex parent.

### Modale Diff — 3e colonne « Règles appliquées »

Le bandeau métriques de la modale Diff gagne une **3e colonne** à côté du tableau métriques et de la colonne summary/toggles : un petit tableau qui liste les règles ayant effectivement matché sur `html_before` (countMatches > 0). Donne d'un coup d'œil « quelles règles ont fait quoi sur cet article », sans devoir basculer en vue Diff pour deviner.

Format : 2 colonnes — N° de règle (label SPA : `P1.1`, `P2.2`, etc., monospace, couleur bleue) + titre humain (`Paragraphes vides`, `Titres autour d'images`…). Triée par ordre d'affichage UI (`compareRuleIdsByDisplayOrder`).

#### Backend

- `DiffController::compute_diff()` calcule `applied_rules: list<{rule_id, occurrences}>` avant de renvoyer le payload : pour chaque règle de `get_rules_for_subset($rule_ids)` (qui respecte déjà l'ordre canonique du pipeline et filtre les règles désactivées par config), on appelle `countMatches($html_before)`, on garde celles avec count > 0. Coût : ~10 appels `countMatches` (HTML reparsé via DOMDocument à chaque fois, mais acceptable — déjà fait par le pipeline juste après).

#### Frontend

- `DiffModal.jsx` — 3e colonne ajoutée dans `.htmln-diff-modal__metrics-row`, conditionnée à `payload.applied_rules.length > 0`. Tri par display order, label via `getRuleLabel`, titre via `getRuleTooltip` (helpers existants de `utils/ruleLabels.js`).
- `styles/main.scss` — `.htmln-diff-modal__metrics-rules` (flex item de la rangée, max-width 320px) + `.htmln-diff-modal__metrics-rules-table` (double-classe pour battre les styles WP-admin sur `<table>`).

#### Tests

- +3 PHPUnit `DiffControllerTest` (rules matchant listées avec occurrences correctes, liste vide quand aucun match, rules hors subset exclues même si activées dans le registre).
- `fake_rule()` helper du test étendu pour accepter un `match_count` optionnel.
- `registry_with()` helper override aussi `get_rules_for_subset()` (sinon `is_preset_enabled` retombait sur la BDD options vide → toujours false → applied_rules toujours vide dans les tests).
- **706 PHPUnit verts** (vs 703, +3), PHPStan baseline inchangée, lint propre.

### Nouvelle règle P10 — désencapsulation des `<p>` autour d'images

Symétrique de P9 (qui désencapsule les `<h*><img></h*>`), P10 traite le même pattern sur les `<p>` :

```
Avant : <p><img class="aligncenter wp-image-19036" src="…" alt="…" width="700" height="485"></p>
Après : <img class="aligncenter wp-image-19036" src="…" alt="…" width="700" height="485">
```

Typique des contenus migrés depuis Word, Classic Editor ou SiteOrigin où une image isolée a été automatiquement enveloppée dans un paragraphe. Signalé sur l'article 19087 du corpus MMM.

Critères de match (alignés sur P9) :
1. Le `<p>` contient au moins un `<img>` (à n'importe quelle profondeur, pour gérer `<p><a><img></a></p>` et `<p><figure><img></figure></p>`).
2. Son `textContent` après normalisation NBSP→espace et trim est vide.

Le `<img>` (et son éventuel wrapper `<a>`, `<figure>`, `<picture>`) est préservé intact à l'unwrap.

**Symétrie de famille** (cf. mapping `RULE_DISPLAY_LABELS` côté SPA) :
- `P1` (paragraphes vides) → affiché **P1.1**
- `P10` (paragraphes autour d'images) → affiché **P1.2**
- `P2` (titres vides) → affiché **P2.1**
- `P9` (titres autour d'images) → affiché **P2.2**

L'ordre d'affichage UI regroupe les paires P1.x et P2.x contiguës ; l'ordre d'exécution du pipeline (`PresetRegistry::PRESETS`) reste `P3 → P4 → P8 → P6 → P7 → P5 → P9 → P10 → P1 → P2`.

#### Backend

- `includes/Core/Rules/UnwrapParagraphImageRule.php` (nouveau) — structure clonée de P9 avec `<p>` comme tag racine au lieu de `<h1>..<h6>`.
- `includes/Core/Registry/PresetRegistry.php` — ajout de `'P10'` à `PRESETS`, du case dans `build_rule()`, et de l'entrée metadata avec label/description.
- `includes/Rest/PresetsController.php` — **3 fixes adjacents** qui empêchaient P10 d'apparaître dans l'onglet Règles côté SPA :
  1. `KNOWN_IDS` étendu de 9 à 10 ids — l'endpoint `GET /presets` itère dessus pour produire la réponse, P10 était donc invisible.
  2. Regex de route `P[1-9]` → `P(?:10|[1-9])` — la regex précédente ne matchait qu'un seul chiffre, `POST /presets/P10` aurait répondu 404.
  3. `DEFAULTS` étendu avec `'P10' => array()` pour la cohérence du payload.
- `includes/Activator.php` — `seed_presets` refactoré : au lieu d'un seed strictement initial (early-return si l'option existe), fait désormais un **merge** à chaque activation. Les préréglages existants sont préservés à l'identique, les nouveaux préréglages absents sont ajoutés. Permet la propagation des futures règles (P10, futures Pxx) à un site déjà activé sans écraser les choix de l'utilisateur — il suffit d'une réactivation du plugin.

#### Frontend

- `store/index.js` — `ALL_RULE_IDS` étendu de 9 à 10 ids.
- `utils/ruleLabels.js` — `RULE_DISPLAY_LABELS` / `RULE_TOOLTIPS` / `RULE_DISPLAY_ORDER` étendus pour P10. P1 reçoit le label `P1.1` (par symétrie avec P10 → P1.2), P10 reçoit `P1.2` et le rang 2 (juste après P1).

#### Tests

- +21 PHPUnit `UnwrapParagraphImageRuleTest` couvrant : cas canonique (article 19087), wrappers `<a>`/`<figure>`/`<picture>`, NBSP autour, négatives (texte autour, `<p>` vide sans img, `<p>` texte uniquement), cas multiples (séries de blocs image, mix avec texte), edge cases (heading préservé par P10, div préservé), countMatches/idempotence.
- 2 tests `PresetsControllerTest` ajustés pour refléter la nouvelle regex de route et le compteur passant de 9 à 10 presets.
- **703 PHPUnit verts** (vs 682, +21, 1527 assertions), PHPStan baseline inchangée, lint propre.

#### Pour propager P10 à une install existante

P10 est listé dans l'onglet Règles mais désactivé par défaut tant que la BDD ne contient pas son entrée dans `son100_htmln_presets`. Deux options pour l'activer :
- **Réactiver le plugin** (déclenche `Activator::activate` → `seed_presets` qui merge P10 avec `enabled: true`).
- L'**activer manuellement** dans l'onglet Règles. Dans les deux cas, **un rescan diagnostic est ensuite nécessaire** (bouton « Scanner… » dans le ScanBar de l'onglet Normaliser) pour que les compteurs de la facette `applicable_rules` reflètent les vraies occurrences de P10 dans le corpus.

### Fix P6 — unwrap des `<span>` orphelins après strip du style

Quand P6 retire l'attribut `style` d'un `<span>` qui n'avait que cet attribut, le span devient `<span>` (sans aucun attribut) — un container sémantique-neutre qui ne fait plus rien. P6 le retire désormais (unwrap) en préservant son contenu :

```
Avant : <p><span style="font-size: 14pt;">Texte chapô...</span></p>
Après : <p>Texte chapô...</p>
```

Cas typique des résidus laissés par Word / Classic Editor / SiteOrigin Editor. Signalé sur l'article 18804 du corpus MMM.

L'exception est restrictive : seul `<span>` est concerné. `<div>`, `<font>`, `<strong>`, `<em>`, `<b>`, `<i>` portent une sémantique ou un layout qu'on conserve indépendamment de l'état des attributs.

Garde-fous (validés par les tests) :
- `<span class="…" style="…">` → conserve `class`, span gardé.
- `<span id="…" style="…">` → conserve `id`, span gardé.
- `<span style="text-align: center">` en mode `keep_text_align` → style préservé, span gardé.
- Enfants préservés en ordre (texte + tags inline) à la place du span unwrap.
- Spans imbriqués → unwrap récursif (P6 traite tous les éléments stylés dans l'ordre du document).

Fichiers : `includes/Core/Rules/RemoveInlineStylesRule.php` (helper privé `unwrap_element` + condition post-strip ciblée `<span>` sans attribut).

Tests : +10 PHPUnit `RemoveInlineStylesRuleTest` ; 1 test pré-existant (`test_descendant_styles_also_processed`) ajusté pour refléter le nouveau comportement. Total **682 PHPUnit verts** (vs 672), PHPStan propre sur la règle éditée.

### Modale Diff — surlignage stabylo des suppressions/ajouts + normalisation HTML pour l'affichage

Deux évolutions intriquées sur le panneau « Code source » de la modale Diff.

#### Surlignage style « stabylo »

Bouton pinceau ajouté dans la barre `view-toggle` (après le verrou scroll). Activé par défaut. Quand actif :

- Panneau **Avant** : les fragments qui n'existent plus dans Après sont surlignés en **jaune** (`#fff59d`).
- Panneau **Après** : ce qui est nouveau est surligné en **vert tendre** (`#c8e6c9`).
- Granularité **mot** via `diffWordsWithSpace` de `diff` v4.0.4 (déjà présent en transitif, listé explicitement).

**Compromis perf** : quand le surlignage est actif, **Prism (coloration syntaxique) est désactivé** pour le panneau concerné. Raison : `Prism.highlight()` est synchrone et appliqué par fragment cumulait > 1 s de freeze côté navigateur (modale « Page qui ne répond pas, Déboguer »). L'utilisateur bascule selon son besoin via le pinceau — surlignage actif → marks jaunes/verts + code texte brut ; surlignage inactif → Prism actif → code coloré sans marks.

#### Normalisation HTML pour l'affichage

`DiffController::compute_diff()` passe désormais `html_before` ET `html_after` par un round-trip `DomHtml::parse_fragment` + `serialize_fragment` avant de les envoyer dans le payload. Effet :

- Double-espaces inter-attributs (`id="x"  class="y"`) → simple espace.
- Espace avant `>` retiré en fin de tag (`"y" >` → `"y">`).
- Auto-fermeture XHTML `<img …/>` reserialisée en HTML5 `<img …>`.

Sans cette normalisation, le surlignage stabylo faisait apparaître ces différences de whitespace HTML comme de « vrais » diffs alors qu'aucun caractère sémantique ne change. Le flag `unchanged` du payload est aussi recalculé sur les versions normalisées.

#### Fichiers livrés

- `includes/Rest/DiffController.php` — helper privé `normalize_for_display()` + double normalisation + `unchanged` recalculé.
- `assets/src/admin-spa/utils/highlightHtmlWithDiff.js` (nouveau) — helper diff + escape HTML + injection de `<mark>` (pas d'appel Prism).
- `assets/src/admin-spa/views/Normalize/HighlightedCode.jsx` — nouvelles props `diffAgainst` / `diffMode`.
- `assets/src/admin-spa/views/Normalize/DiffModal.jsx` — state `showDiffMarks` + bouton pinceau (icône `brush` de `@wordpress/icons`) + câblage des 2 `<HighlightedCode>`.
- `assets/src/admin-spa/styles/main.scss` — règles `mark.htmln-diff-removed` (`#fff59d`) / `mark.htmln-diff-added` (`#c8e6c9`).
- `package.json` — `diff ^4.0.4` listé explicitement dans `dependencies`.

#### Tests

- +4 PHPUnit `DiffControllerTest` (normalisation double-espaces, espace avant `>`, `unchanged` recalculé sur whitespace seul, idempotence sur HTML déjà propre).
- 672 PHPUnit verts (vs 668), lint propre.

### Fix P1 — retire aussi le wrapper de bloc Gutenberg `<!-- wp:paragraph -->`

Avant le fix, sur un bloc Gutenberg `wp:paragraph` vide :

```
<!-- wp:paragraph -->
<p></p>
<!-- /wp:paragraph -->
```

P1 supprimait le `<p></p>` interne mais laissait les deux commentaires Gutenberg en place. Résultat : un bloc Gutenberg « squelette » inerte qui ressort comme une ligne vide invalide à l'édition. Cause : `DOMDocument` parse les commentaires Gutenberg comme des `DOMComment` siblings du `<p>` — P1 itérait sur `getElementsByTagName('p')` qui ne les voit pas.

Fix : avant de supprimer un `<p>` vide, P1 cherche ses siblings immédiats (en sautant les `DOMText` de whitespace pur) :

- `previousSibling` est-il un `DOMComment` qui matche `^\s*wp:paragraph(\s|$)` (l'attrs JSON optionnel est toléré) ?
- `nextSibling` est-il un `DOMComment` qui matche `^\s*/wp:paragraph\s*$` ?

Si oui pour les deux → P1 supprime tout le range inclusif (commentaire ouvrant + whitespace + `<p>` + whitespace + commentaire fermant). Sinon → comportement historique (suppression isolée du `<p>`).

L'exception est restrictive : un `<p>` vide imbriqué dans un autre bloc Gutenberg (`wp:column`, `wp:group`…) ne déclenche **pas** la suppression des wrappers du bloc englobant — seuls les commentaires `wp:paragraph` adjacents directs comptent.

**+11 tests `EmptyParagraphsRuleTest`** :
- bloc Gutenberg vide complet supprimé (avec `<p></p>`, avec `&nbsp;`, avec attrs JSON `{"align":"center"}`) ;
- bloc Gutenberg avec `<p>` non-vide intégralement préservé ;
- deux blocs Gutenberg vides adjacents (les deux supprimés) ;
- mix de blocs vides et pleins (seul le vide retiré) ;
- `<p></p>` nu sans wrapper (comportement historique inchangé) ;
- `<p></p>` dans un bloc `wp:column` (seul le `<p>` retiré, wrapper externe préservé) ;
- commentaire orphelin (ouvrant sans fermant ou inversement) → pas de tentative de devinage ;
- `countMatches()` toujours = 1 par paragraphe vide (unité métier inchangée).

**668 PHPUnit verts** (vs 657, +11, 1486 assertions), PHPStan propre sur la règle éditée, 9 intégration verts.

### Label `Caractères` clarifié → `Caractères (espaces inclus)`

Le bandeau métriques affichait « Caractères » sans préciser que les espaces sont comptés — source de confusion avec des outils externes (gedit, Word…) qui distinguent en général « caractères avec espaces » vs « sans espaces ». Comme la métrique du plugin est calculée via `mb_strlen()` du `textContent` (espaces inclus, mais NBSP normalisés en espaces simples), on clarifie le libellé partout où il apparaît côté utilisateur :

- `MetricsDiffBar.jsx` (table métriques de la modale Diff).
- `Settings.jsx` (seuil γ « Caractères — perte tolérée » → « Caractères (espaces inclus) — perte tolérée »).
- `Admin/Pages/PostsPage.php` (page V0.1 — pour cohérence, même si la page est masquée du menu).

Note : la métrique du plugin ne compte pas les attributs `alt` des images, alors que le presse-papier d'un navigateur les inclut en collant vers une cible texte simple — ce qui peut expliquer un écart de quelques dizaines de mots et plusieurs centaines de caractères vs un copier-coller dans gedit. Comportement délibéré (le `alt` n'est pas du « contenu de lecture » au sens régression structurelle).

### Fix P6 — préserve le `style` du `<img>` dans les blocs Gutenberg `core/image`

La règle **P6 (Styles inline)** strippait l'attribut `style="aspect-ratio:…;width:…;height:…;object-fit:…"` du `<img>` à l'intérieur d'un bloc `core/image` Gutenberg. Or ce `style` est **synchronisé** avec :

- Le JSON `<!-- wp:image {"width":"…","height":"…","aspectRatio":"…","scale":"…"} -->` en amont.
- La classe `is-resized` sur le `<figure>` parent.

Retirer le `style` isolément cassait l'invariant attrs/HTML décrit dans `CLAUDE.md` §6 → le bloc s'affichait comme « contenu invalide » à la réouverture dans l'éditeur Gutenberg.

Fix : P6 ignore désormais les `<img>` enfants directs d'un `<figure class*="wp-block-image">` (match avec frontière de mot pour éviter les faux positifs sur `wp-block-image-foo`). L'exception est étendue à `countMatches()` pour parité (un article 100 % Gutenberg dont seuls les `<img>` `core/image` ont des `style` ne déclenche plus P6 à tort).

Scope du bug : limité aux 65 articles Gutenberg du corpus MMM-2 (sur 799). Les `<img>` hors `figure.wp-block-image` (cas classique des articles SiteOrigin et du HTML « libre ») restent traités normalement par P6.

**+9 tests** `RemoveInlineStylesRuleTest` couvrant :
- bloc `core/image` complet préservé (modes `keep_text_align=true` ET `keep_text_align=false`) ;
- `countMatches()` retourne 0 sur ces blocs ;
- frontière de mot respectée (`wp-block-image-foo` ≠ match) ;
- `figcaption` ou autres enfants du même `<figure>` continuent d'être nettoyés normalement (l'exception est restrictive au seul `<img>` enfant direct) ;
- multi-classes sur le `<figure>` (`foo wp-block-image bar`) ;
- `<img>` dans une `<figure class="something-else">` ou directement dans `<p>` toujours nettoyé.

**Total 657 PHPUnit verts** (+9 vs 648, 1474 assertions), PHPStan baseline 22 inchangée.

### Modale Diff — refonte UX complète post-rc4

Cinq évolutions livrées en une seule passe sur la modale Diff (F14.3) pour rendre la lecture du diff radicalement plus rapide. Toutes intriquées sur `DiffModal.jsx` + `styles/main.scss`, donc regroupées ici.

#### 1. Métadonnées injectées à la suite du titre

Le header de la modale regroupe sur **une seule ligne** : titre (« Diff de l'article #ID — Titre »), tiret cadratin, `Cat. : <catégorie(s)>`, date de publication en français (`12 mars 2024`), pastille constructeur — réutilisant le composant `<BuilderBadge>` du tableau Normaliser avec sa couleur (vert Gut / rouge SO / orange « Gut + fossile » / etc.). Pas de label « Constructeur : » — la pastille parle d'elle-même. Données récupérées en un seul fetch via le payload `/posts/<id>/diff` enrichi côté serveur.

- `DiffController::compute_diff()` retourne 4 clés additionnelles : `post_date` (`Y-m-d H:i:s`), `categories` (`list<string>` via `wp_get_post_categories` mode `'names'`), `builder_type` (`BuilderClassifier::classify`), `has_fossil_panels_data` (booléen scope Gutenberg uniquement, parité avec `DiagnosticsController::diagnostic_to_array()`).
- `BuilderClassifier` injecté dans le constructeur de `DiffController` (mis à jour côté `Plugin::build_rest_controllers()`).
- `DiffModal.jsx` :
  - Métadonnées passées via le slot officiel `headerActions` de `<Modal>` de `@wordpress/components` — rendu nativement à la suite du `<h1>` du header, à gauche du bouton fermer.
  - Helper `formatPostDate` (split SQL `' '` → ISO `'T'` pour la compat Safari, fallback gracieux).
  - `<BuilderBadge type={builder_type} hasFossilPanelsData={...} />` consommé tel quel — couleur et tooltip identiques à la liste Normaliser.
- `styles/main.scss` :
  - `.htmln-diff-modal .components-modal__header-heading-container { flex-grow: 0 }` — **fix décisif** : WP applique `flex-grow: 1` sur le wrapper du titre qui faisait que le `<h1>` s'étirait sur toute la largeur dispo, refoulant les métadonnées contre le bouton fermer. Override à 0 → le titre reste à sa largeur intrinsèque, les métadonnées s'alignent juste à sa suite.
  - Header repassé en `justify-content: flex-start` + gap 12px ; bouton fermer poussé à droite via `> :nth-last-child(2) { margin-left: auto }` ciblant le `Spacer` WP.
  - `.htmln-diff-modal__header-meta` (inline-flex, gap 10, font 13px, couleur muted) + tiret cadratin en `::before` du container + séparateur `·` discret entre items.

#### 2. Fix critique — variables CSS hissées au `:root`

Bug identifié pendant la livraison de la pastille constructeur : les modales `@wordpress/components` rendent via un **portail React** au niveau de `document.body`, **hors** de l'arbre `.htmln-spa-root`. Les variables CSS (`--htmln-color-success`, `--htmln-color-danger`, etc.) étaient scopées à ce root → dans la modale elles tombaient en `<invalid>`, et la pastille avait `background: <invalide>` (= transparent) avec `color: #fff` → **blanc sur blanc, donc invisible**.

Fix : variables hissées de `.htmln-spa-root` vers `:root`. Préfixe `--htmln-*` suffit à garantir zéro collision globale. La pastille retrouve sa couleur dans la modale (et n'importe quel autre portail futur).

#### 3. Layout 2 colonnes du bandeau métriques

Ancien empilement vertical `[summary → table → view-toggle]` (~9 lignes de hauteur) → nouveau layout 2 colonnes `[table | summary + view-toggle empilés]` (~7 lignes). Gain de ~2 lignes pour l'affichage du code en dessous. `flex-wrap` rebascule en empilement vertical sur écran étroit.

- `MetricsDiffBar.jsx` refactoré : extraction d'une fonction pure `computeRows()`, puis exports nommés `MetricsDiffSummary` et `MetricsDiffTable`. Le default export inchangé compose les deux verticalement — `RegressionModal` n'est pas impacté.
- Largeur du summary limitée à son contenu via `align-items: flex-start` sur la colonne aside (au lieu du `stretch` par défaut qui faisait filer la phrase jusqu'au bord droit).
- Boutons « Code source / Rendu HTML » alignés sur le bas du tableau via `margin-top: auto` sur le `view-toggle` (`align-items` du parent revenu à `stretch` pour étirer la hauteur de l'aside).

#### 4. Verrou de synchronisation du défilement vertical

Bouton avec icône cadenas (`lock` / `unlock` de `@wordpress/icons`) dans la barre `view-toggle`, qui active/désactive la synchronisation du `scrollTop` entre les panneaux Avant et Après — sur les deux vues (code source ET rendu HTML). Défaut désactivé.

- State local `scrollSync` + refs partagées `beforeScrollerRef` / `afterScrollerRef` qui pointent soit vers le `<pre>` (mode CODE) soit vers l'`<iframe>` (mode RENDER).
- Drapeau anti-boucle `syncingRef` levé avant chaque écriture programmée de `scrollTop`, abaissé au prochain `requestAnimationFrame`.
- Mode CODE : `onScroll` direct sur les `<pre>`.
- Mode RENDER : `useEffect` qui attache `addEventListener('scroll', ...)` sur le `contentDocument` de chaque iframe, avec re-attachement au `load` pour couvrir les re-renders. Cleanup propre au démontage.
- `@wordpress/icons ^13.0.0` ajouté à `package.json` (déjà installé en transitif, simplement listé explicitement).

#### 5. Coloration syntaxique HTML (Prism.js)

Les panneaux `<pre>` du mode « Code source » sont désormais coloriés via Prism.js — tags en bleu, attributs en violet, valeurs en vert, commentaires (incluant les blocs Gutenberg `<!-- wp:* -->`) en gris italique, ponctuation en gris foncé. Palette alignée WP-Admin via les CSS vars du plugin, pas de thème Prism par défaut importé (qui aurait écrasé les backgrounds `#f6f7f7` Avant / `#f0f6fc` Après).

- `prismjs ^1.29.0` ajouté à `package.json` (v1.30 installée).
- Nouveau helper `utils/highlightHtml.js` — appelle `Prism.highlight(raw, Prism.languages.markup, 'markup')`. Le core + le composant `markup` uniquement (pas de thème CSS, pas d'autres langues).
- Nouveau composant `views/Normalize/HighlightedCode.jsx` — `<pre><code>` avec `forwardRef` (pour le scroll sync), `useMemo` (pour ne pas re-tokeniser à chaque render), injection via `dangerouslySetInnerHTML` (Prism échappe les caractères spéciaux lui-même).
- `DiffModal.jsx` : les 2 `<pre>` du mode CODE remplacés par 2 `<HighlightedCode>`, le scroll-sync continue de fonctionner via la ref forwardée.
- `styles/main.scss` : ~70 lignes de styles `.token.*` ajoutés à `.htmln-diff-modal__code`.

#### Tests

- +7 tests `DiffControllerTest` (post_date exposée, catégories liste + cas vide, builder_type calculé, has_fossil_panels_data sur 3 scénarios).
- `make_controller()` factory mis à jour pour injecter `BuilderClassifier`.
- `seed_post()` factory étendue avec `post_date` et `categories` optionnels.
- Stub `wp_get_post_categories` ajouté à `tests/bootstrap.php` (mode `fields => 'names'`).
- Propriété `post_date` ajoutée au stub `WP_Post` — **fix au passage** la dépréciation PHP 8.3 préexistante (« Creation of dynamic property WP_Post::$post_date is deprecated ») qui apparaissait sur `DiagnosticsControllerTest`.

#### Métriques

- **648 PHPUnit verts** (vs 641 — +7 nouveaux, 1465 assertions), 9 intégration verts, **plus aucune dépréciation**.
- PHPStan 22 baseline inchangée (fichiers édités propres).
- Bundle JS 121 KiB minified / 31.9 KiB gzip (vs 88 / 24 en rc4 — +33 KiB minified, dont ~20 KiB pour Prism). CSS 24.5 KiB (vs 22.3).

### Masquage des pages admin V0.1 — la SPA devient le point d'entrée unique

La SPA `1.0.0-rc4` couvre désormais l'essentiel des besoins fonctionnels V0.1 (Normalisation par lots, Règles, Historique, Notes, Réglages). Pour éviter à l'utilisateur de devoir choisir entre deux UI et clarifier le cap vers `v1.0.0`, les pages V0.1 sortent du menu admin — **sans être supprimées** : leurs URLs `?page=…-<sub>` restent fonctionnelles pour du rollback / non-régression, mais aucune entrée n'apparaît plus dans le sidebar.

- `Admin\Menu::on_admin_menu()` :
  - Le top-level « HTML Normalizer » bascule son callback de `PresetsPage::render` vers `SpaPage::render`. L'icône, le menu_title et le slug (`100son-html-normalizer`) restent inchangés pour ne pas dérouter l'utilisateur.
  - Les 4 pages V0.1 (Préréglages / Tester / Normaliser / Journal) restent **enregistrées** (URLs `?page=100son-html-normalizer-{presets,tester,posts,logs}` fonctionnelles) puis retirées du menu via `remove_submenu_page()`. Préréglages prend un slug dédié `…-presets` au lieu de l'ancien alias top-level (qui aurait collisionné avec la nouvelle route SPA).
  - L'alias rétro-compat `?page=100son-html-normalizer-spa` est conservé puis également masqué — les favoris/bookmarks existants continuent de marcher.
- `Admin\Assets::is_spa_page()` accepte désormais les **deux slugs** SPA (`Menu::SLUG` *et* `Menu::SPA_PAGE_SLUG`) pour que le bundle React s'enqueue aussi bien sur le top-level que sur l'ancien chemin.
- Aucune classe V0.1 n'est instanciée différemment — les hooks `admin_post_*` de `PostsPage` restent branchés (`Menu::register()` les conserve).

#### Rollback

Réafficher une page V0.1 = retirer la ligne `remove_submenu_page` correspondante dans `Menu::on_admin_menu()`. Pour revenir entièrement à la configuration rc4, restaurer le bloc V0.1 d'origine depuis l'historique git.

### Fix — état de l'onglet Normaliser perdu au switch d'onglets primaires

Bug : un aller-retour entre l'onglet « Normaliser » et un autre onglet primaire de l'App (`Notes`, `Réglages`, `Historique`, `Règles`) faisait perdre la configuration en cours — tab interne (To improve / Normal / Stale), pagination courante, per-page, filtres (constructeur, catégorie, année, recherche…) et sélection d'articles cochés étaient remis aux defaults. Cause : `App.jsx` route via `renderRoute()` qui monte/démonte les vues, et toute la config Normalize vivait en `useState` local — donc perdue à chaque démontage. Seul `selectedRules` survivait car déjà dans le store.

Correctif : déplacement des 5 états locaux vers le store `htmln/spa` dans un slice dédié `normalizeView` (tab / page / perPage / filters / selectedPostIds). Persistant au switch d'onglets primaires, perdu au reload (cohérent avec la sémantique éphémère de `selectedRules`).

- `store/index.js` : nouveau slice `normalizeView` avec defaults centralisés (`DEFAULT_NORMALIZE_VIEW`), 4 actions (`setNormalizeView` partiel-merge, `toggleNormalizeSelectedPost`, `toggleNormalizeSelectedPostsOnPage`, `clearNormalizeSelectedPosts`), 1 selector (`getNormalizeView`). Le reducer privilégie un dispatch unique pour les transitions composées (`{ tab, page: 1 }`, `{ filters, page: 1 }`, etc.) — un seul re-render au lieu de deux.
- `views/Normalize.jsx` : remplacement de 5 `useState` par `useSelect` + `useDispatch`. `selectedPostIds` côté store reste un array (sérialisable Redux) ; le composant le matérialise en `Set` via `useMemo` pour préserver l'API `.has()` / `.size` attendue par `ArticlesTable`.
- `useState( diffPostId )` conservé en local — la modale Diff doit se refermer au switch d'onglet, c'est même l'effet attendu.

#### Métriques

- Bundle JS 95.1 KiB (vs 93.6 — +1.5 KiB pour le slice + reducer cases).
- Lint JS propre, PHPUnit 641 verts + 9 intégration verts (aucun test PHP impacté), PHPStan baseline inchangée.

### Onglet Normaliser — accès rapide aux sites externes Old / Prod

Pendant la migration MMM, on a régulièrement besoin de comparer un article entre l'ancien site (`old.ma-maison-mag.fr`) et la prod. Trois petites évolutions, sans incidence fonctionnelle sur le pipeline de normalisation :

- **Réagencement de la cellule Titre** dans `ArticlesTable` : le bouton « Éditer » passe **avant** le titre (alignement gauche), suivi du titre cliquable, puis de deux nouveaux boutons « Old » et « Prod » qui ouvrent l'article sur les domaines configurés (path du permalien local préservé via `new URL(...).pathname`).
- **Nouvelle option `external_sites`** dans `son100_htmln_settings` — deux clés `old_url` / `prod_url`, defaults `https://old.ma-maison-mag.fr` et `https://ma-maison-mag.fr`. Normalisation défensive (regex `^https?://host`, slash final retiré, fallback default sur valeur invalide) — même contrat que les seuils γ.
- **Section « Domaines externes »** dans l'onglet Réglages — 2 inputs URL, bouton « Restaurer les valeurs par défaut », notice succès/erreur, cycle isDirty propre.

#### Backend

- `SettingsRepository::EXTERNAL_SITES_DEFAULTS` (constante publique).
- `SettingsRepository::getExternalSites()` / `setExternalSites()` / `normalize_external_sites()` (le délimiteur du regex est `~` et non `#`, vu que `#` apparaît dans la classe de caractères et terminerait prématurément le motif sinon).
- `SettingsController` : 2 nouvelles routes `GET`/`POST /settings/external-sites` (permission `manage_options`, contrat `{ sites, defaults }` / `{ sites }`).
- 24 routes REST au total (vs 23 en rc4).

#### Frontend

- `api/settings.js` : `getExternalSites()` / `saveExternalSites()`.
- `hooks/useExternalSites.js` : nouveau hook (cycle fetch/save indépendant de `useSettings`).
- `views/Settings.jsx` : ajout du composant `<ExternalSitesSection />` sous le formulaire des seuils.
- `views/Normalize/ArticlesTable.jsx` : utilitaire `buildExternalUrl(permalink, baseUrl)` (path préservé, fallback null si URL parse fail), prop `externalSites`.
- `styles/main.scss` : `.htmln-articles-table__site-btn`, `.htmln-settings__section`.

#### Tests

- +10 tests `SettingsRepositoryTest`, +5 tests `SettingsControllerTest` (defaults, override, slash final, URLs invalides, valeurs non-string, ignore clés inconnues, préservation autres settings, http/https, normalisation silencieuse).
- `test_register_routes_registers_single_endpoint_pair` renommé en `…_registers_both_endpoint_pairs` pour couvrir les 2 routes.

#### Métriques

- 641 PHPUnit verts (vs 635 en rc4 — net +6 après refactor `…_registers_both_endpoint_pairs`), 1456 assertions, 9 tests d'intégration verts.
- PHPStan inchangé (22 erreurs baseline) — mes fichiers édités sont propres.
- Bundle JS 93.6 KiB (vs 88.2), CSS 22.4 KiB (vs 22.3).

---

## [1.0.0-rc4] — 2026-05-12

**Portage dans la SPA des fonctionnalités V0.1 perdues** + **refontes UX finales rc4** (filtre Règles, pagination numérotée, refonte Diff & Métriques, badge orange « Gut + fossile »). Cible : parité fonctionnelle SPA ↔ V0.1 + qualité visuelle prête pour `1.0.0` final.

---

### Refontes UX finalisation rc4 (session du 12 mai après-midi)

#### Filtre « Règles » multi-sélection dans FiltersBar

- Nouveau sous-composant `RulesFilterDropdown` (`@wordpress/components` `Dropdown` + 9 `CheckboxControl` triés par `RULE_DISPLAY_ORDER`).
- Sémantique **OR** : un article match si **au moins une** des règles cochées s'applique.
- Compteur par règle « P2.1 (183) » à droite de chaque case, alimenté par la nouvelle facette `applicable_rules`.
- Bouton « Tout désélectionner » conditionnel (visible dès 1 case cochée).
- Toggle button labels adaptatifs : « Toutes » / « P2.1 » (1 sélectionnée) / « 3 sélectionnées ».
- Tooltip au survol de chaque case (libellé humain de la règle).

##### Backend

- `DiagnosticsRepository::count_by_applicable_rule()` — nouvelle méthode, scan unique + agrégation PHP (≤ ~1000 rows, négligeable).
- `DiagnosticsRepository::build_filter_clauses()` — filtre OR via `JSON_SEARCH(matching_rules, 'one', %s, NULL, '$[*].rule_id')`. Précis (P1 ≠ P10), MySQL 5.7+.
- `DiagnosticsController::parse_filters()` — whitelist `PresetRegistry::PRESETS`, dédoublonnage, rejet silencieux des IDs inconnus.
- `DiagnosticsController::get_facets()` — expose `applicable_rules` (map rule_id → count, 9 clés toujours présentes même à 0).

#### Pagination numérotée cliquable

- Remplace « page X sur Y » par « 1 2 3 … 14 15 16 » avec boutons WP `variant="primary"` (page courante) / `tertiary` (autres).
- Algorithme adaptatif `buildPageRange(currentPage, totalPages)` :
  - `totalPages ≤ 9` → toutes pages.
  - Sinon : `[1, 2, 3, …, c-1, c, c+1, …, n-2, n-1, n]` avec ellipsis automatique aux gaps > 1.
- ARIA `aria-current="page"`, `aria-label="Page N"`.
- Page courante désactivée (clic redondant bloqué + `pointer-events: none`).

#### Refonte `DiffModal` style V0.1

- Titre enrichi : « Diff de l'article #ID — Titre de l'article » (prop `postTitle` remontée depuis les `items` paginés de `Normalize.jsx` — pas de fetch supplémentaire).
- Colonne **Avant** : fond gris `#f6f7f7`, bordure neutre.
- Colonne **Après** : fond bleu pâle `#f0f6fc`, bordure bleue `#72aee6`, titre coloré `#135e96`.
- Suppression de `height: calc(100vh - 220px)` sur le pane Code (max-height: 500px sur les `<pre>`, scroll naturel).
- Bloc « Avertissements » encadré (fond gris subtil + bordure).
- Classes BEM `--before` / `--after` pour la sémantique.

#### Refonte `MetricsDiffBar`

- **Phrase « garde-fou »** en tête, verdict immédiat :
  - vert « ✓ Aucune perte de contenu détectée. »
  - orange « ⚠ Perte de X % de paragraphes (-N sur M). »
  - Sélection auto de la pire perte en relatif (|%| max).
- **Une ligne « Titres »** unique (somme h1+h2+h3+h4+h5+h6) au lieu des 6 lignes individuelles bruyantes.
- **Masquage** des lignes neutres (`before=0 && after=0` → cachées). Réduit typique de 12 à 6-7 lignes affichées.
- **Delta enrichi** : `+5 (+12 %)` au lieu de `+5` seul. Précision 1 décimale si |%| < 10, entier sinon. Cas spécial `+N` seul si `before=0` (% sans sens).
- **Formatage local** des entiers : `4 532` (`Intl.NumberFormat('fr-FR')`).
- **Tableau encadré** : bordure `--htmln-border-strong`, radius, quadrillage interne (séparateurs verticaux), zébrage `tbody:nth-child(even)` sur `#f0f0f1`, header `#dcdcde` nettement différencié, `width: auto` pour resserrer.
- **Spécificité bumpée** `.htmln-metrics-diff.htmln-metrics-diff` (0,2,0) — bat les styles WP-components dans les modales.

#### Section actions unifiée — Scanner + Appliquer côte-à-côte

- Wrapper `.htmln-normalize__actions` au-dessus des filtres : panneau gris unique avec deux colonnes (gauche `ScanBar`, droite `ApplyStepBar`).
- `ApplyStepFooter` renommé `ApplyStepBar` (plus un footer, déplacé en haut).
- Layout interne : bouton primary + description en colonne verticale dans chaque côté.
- Responsive : `flex-wrap: wrap` fait basculer la colonne droite sous la gauche sur petit écran.

### Fix critique — BuilderClassifier (`panels_data` fossile)

Bug rapporté sur l'article #19785 du corpus MMM-2 : tagué SiteOrigin alors qu'il est rédigé en Gutenberg pur.

**Diagnostic** : article migré SO → Gut ayant gardé son ancien `panels_data` en post-meta. L'ancien ordre testait `panels_data non-vide → siteorigin` **avant** toute lecture du contenu effectif.

**Impact** : 22 articles du corpus dans le même cas (IDs > 19000, tous post-migration).

**Fix** : `has_blocks( $content )` prime maintenant sur `panels_data` fossile. Nouvel ordre :

1. Override `out` → priorité absolue.
2. `<!-- wp:siteorigin-panels` dans content → `siteorigin` (SO 2.10+).
3. Classes `panel-layout` / `so-panel` :
   - avec `panels_data` → `siteorigin` (SO actif natif) ;
   - sans → `siteorigin_flat` (migration partielle).
4. `has_blocks(content)` → `gutenberg`. **Prime sur `panels_data` fossile**.
5. `panels_data` seul sans marqueur → `siteorigin` (cas dégénéré).
6. → `other`.

Tests : +4 cas dans `BuilderClassifierTest` pour les scénarios litigieux (fossile, SO + classes, panels_data seul, bloc SO + meta vide).

### Pastille « Gut » orange — signal fossile

- Nouvelle variante visuelle `htmln-builder-badge--gutenberg-fossil` (fond `--htmln-color-warning`).
- Affichée pour les articles `builder_type = gutenberg` qui conservent un `panels_data` en post-meta.
- Tooltip explicatif + commande WP-CLI de nettoyage : « `wp post meta delete <ID> panels_data` ».
- Nouveau champ `has_fossil_panels_data` dans le payload REST `/diagnostics` (computed uniquement pour les rows Gut, ~5 lookups post-meta par page).
- Tests : +4 `DiagnosticsControllerTest` (Gut+meta, Gut sans meta, SO+meta non flagué, Gut+`panels_data=[]`).

### Renommage UI « pas » → « lot »

Aligne le vocabulaire utilisateur sur le terme « lot » (préféré), tout en gardant « step » côté code (routes REST, table BDD, classes PHP/JS, hooks). Pattern « UI traduite, code stable ».

Périmètre :
- `README.md` (~9 occurrences : workflow, pipeline, checkbox, historique).
- JSX strings via `__()` : `History.jsx`, `History/StepsTable.jsx`, `History/StepDetailDrawer.jsx`, `Normalize.jsx` (button label), `Normalize/StepProgressBanner.jsx`, `Normalize/StepResumeBanner.jsx`, `Rules.jsx`.
- Titre H1 admin : `SpaPage.php` (« Normalisation par lots (V1.0) »).

Inchangés : noms de classes, routes REST `/steps`, table `son100_htmln_steps`, hooks `htmln/step_*`, commandes WP-CLI `wp htmln steps …`, PHPDoc / JSDoc.

### Fix cache navigateur — `filemtime()` sur le `?ver=` du CSS

- L'enqueue CSS utilisait le hash de `admin-spa.asset.php` généré pour le **JS** uniquement. Sur une édition SCSS-only, ce hash ne bougeait pas → cache navigateur servi malgré rebuild.
- Fix : `filemtime( $css_path )` comme `?ver=`. Timestamp change à chaque rebuild, cache invalidé sûrement. Fallback sur `SON100_HTMLN_VERSION` si filemtime indisponible.

### Statistiques rc4 final

- **635 tests PHPUnit verts** (+21 depuis rc3), **1 433 assertions**.
- **PHPStan niveau 6** : 22 erreurs baseline préexistantes, aucune nouvelle.
- **ESLint** : 0 erreur, 0 warning.
- **Bundle SPA** : 88.2 KiB JS · 22.3 KiB CSS (compilé, hors RTL).

---

### Portage initial rc4 (matin du 12 mai)

**Portage dans la SPA des fonctionnalités V0.1 perdues** — recherche (titre/ID), filtres catégorie / année / mois / constructeur, pastille constructeur dans le tableau. Cible : parité fonctionnelle entre la SPA et la page V0.1 « Normaliser » avant de retirer cette dernière en V1.1.

### Ajouts backend

#### Service `Core\Posts\BuilderClassifier`

- Classification 5 types : `siteorigin`, `siteorigin_flat`, `gutenberg`, `other`, `out`.
- Réplique fidèle de la logique V0.1 `PostsPage::classify_builder`, déplacée dans un service stateless pour devenir la source unique de vérité, partagée par la SPA REST et la page V0.1 (déjà en place côté V0.1 — non refactoré).
- Ordre de précédence : override manuel → `panels_data` meta → bloc `<!-- wp:siteorigin-panels` → classes `panel-layout` / `so-panel` → `<!-- wp:` → fallback `other`.
- Respect du toggle V0.1 `_son100_htmln_builder_override = 'out'` (priorité absolue).

#### Schema 2.0.0 → 2.1.0

- Ajout colonne `builder_type VARCHAR(20) NULL` + KEY sur `son100_htmln_diagnostics`. `dbDelta` idempotent — colonne ajoutée à NULL sur instances existantes, remplie au prochain scan.
- `DiagnosticRecord` enrichi d'un champ `builder_type` nullable (rétro-compat avec rows pré-2.1.0).
- `DiagnosticEngine` accepte un `BuilderClassifier` (3e arg constructeur, nullable pour backward-compat) et injecte `builder_type` dans le record lors de `diagnose()`.

#### REST extensions sur `GET /diagnostics`

Nouveaux paramètres query (post-rc3) :
- `search` : numérique → `post_id` exact ; sinon JOIN `wp_posts` + `post_title LIKE` (alignement V0.1 : titre uniquement, pas content/excerpt).
- `cat` : ID de catégorie WP (JOIN `wp_term_relationships` + `wp_term_taxonomy` sur `taxonomy = 'category'`).
- `year` / `month` : `YEAR(post_date)` / `MONTH(post_date)`.
- `builder` : `siteorigin` (couvre flat) / `gutenberg` / `other` / `out`.

Payload enrichi : `post_title`, `post_date`, `builder_type` ajoutés à chaque item. Cache pré-chargé via `_prime_post_caches()` pour éviter N+1.

#### Nouveau `GET /diagnostics/facets`

Retourne les données des dropdowns SPA :
- `years` : list<int> DESC.
- `categories` : list<{id, name, count}>.
- `builders` : map<string, int> (count par type, regroupés 4 valeurs UI).

#### Implémentation `DiagnosticsRepository`

- `list_paginated()` et `count_paginated()` étendus avec un 4e param `$filters` (signature backward-compat).
- Nouveau helper privé `build_filter_clauses()` partagé entre list et count — évite drift de filtre (jamais de count qui diffère de la list).
- SQL préfixé `d.` (alias systématique de la table diagnostics) pour cohabiter proprement avec les JOINs.
- Nouvelles méthodes `list_distinct_years()` et `count_by_builder()` pour les facets.

### Ajouts frontend

#### Nouveau composant `FiltersBar`

- 5 contrôles : `TextControl` recherche + 4 `SelectControl` (catégorie / année / mois / constructeur).
- Search **debouncé à 250 ms** — sans debounce, taper « Hello » lancerait 5 requêtes REST.
- Bouton « Effacer » qui reset tous les filtres en un clic.
- Disabled state sur le dropdown Mois tant qu'aucune année n'est sélectionnée (le filtre month sans year n'a pas de sens).
- Compteurs intégrés dans les labels : `Catégorie (12)`, `SiteOrigin (450)`, etc.
- Positionnée entre `ScanBar` (action ponctuelle) et `TabsHeader` (onglets internes) — arbitrage UX du 2026-05-12.

#### Nouveau composant `BuilderBadge`

- Pastille colorée 5 variantes alignée sur la V0.1 :
  - `SO` rouge — SiteOrigin natif (panels_data ou bloc)
  - `SO~` orange — SiteOrigin aplati (HTML figé, normalisation à risque)
  - `Gut` vert — Gutenberg
  - `?` jaune — Constructeur inconnu (HTML libre)
  - `Out` gris — Hors périmètre (override manuel)
- Fallback `—` discret pour rows pré-2.1.0 sans `builder_type` calculé (rescan pour catégoriser).
- Tooltip explicite sur chaque pastille.

#### Tableau enrichi

- Nouvelle colonne **Titre** entre ID et Statut (max-width avec ellipsis pour éviter le débordement sur titres longs).
- Nouvelle colonne **Constr.** entre Statut et Règles applicables, avec `<BuilderBadge>`.

#### Hooks

- `useDiagnosticsFacets()` : fetch unique au mount + `refetch()` exposée (déclenchée par `handleScanComplete`).
- `useDiagnosticsList()` accepte désormais un 4e param `filters` ; sérialisation stable via `JSON.stringify` pour éviter la boucle infinie de re-fetch sur recréation d'objet parent.

### Tests + lint

- 13 nouveaux tests `BuilderClassifierTest` (5 types, override out prioritaire, mixte SO+Gutenberg → SO, ALL_TYPES consistency, etc.).
- Stubs bootstrap : `get_post_field` + `has_blocks` ajoutés.
- Tests existants mis à jour pour signature filtres et alias SQL `d.` :
  - `ActivatorTest` : DB_VERSION 2.0.0 → 2.1.0
  - `DiagnosticsRepositoryTest` : assertions SQL avec `d.` préfix
  - `DiagnosticsControllerTest` : signature stubs list/count_paginated, endpoint count 6 → 7, nouveaux stubs years/builders

### Stats

- **PHPUnit** : 602 → 614 tests verts (+12), 1354 → 1370 assertions
- **Bundle SPA** : 71.0 → 78.7 KiB JS (+7.7 KiB pour FiltersBar + BuilderBadge + hook), CSS 14.7 → 15.5 KiB
- **Lint JS** : 0 erreur, 0 warning
- **PHPStan** : niveau 6, 22 erreurs (inchangé, aucune nouvelle)

---

## [1.0.0-rc3] — 2026-05-12

Ajout du **préréglage P9 — `UnwrapHeadingImage`** + bouton **« Scanner le corpus »** dans l'onglet Normaliser de la SPA.

### Ajouts

#### P9 — UnwrapHeadingImage (nouveau préréglage)

- **`Core\Rules\UnwrapHeadingImageRule`** — désencapsule les `<h1>` à `<h6>` qui ne contiennent qu'une image (sans texte). Cas typique des contenus WP migrés où un éditeur visuel a wrappé une image dans un titre par erreur :

  ```html
  <h2><img src="..." class="aligncenter wp-image-14157" ...></h2>
  ```

  devient :

  ```html
  <img src="..." class="aligncenter wp-image-14157" ...>
  ```

- **Wrappers internes préservés** : `<h2><a href><img></a></h2>` → `<a href><img></a>`, idem pour `<figure>`. Seules les balises de titre sont retirées.
- **Symétrique de P2** : P2 préserve volontairement les titres contenant un `<img>` (élément structurel), P9 les nettoie.
- **NBSP** (`&nbsp;`, U+00A0) traité comme blanc — `<h2>&nbsp;<img>&nbsp;</h2>` matché.
- **Attribut `alt` non bloquant** : `<h2><img alt="Description"></h2>` matché car `alt` est un attribut, pas dans `textContent`.

#### Pipeline + Registry

- **`PresetRegistry::PRESETS`** : ordre canonique mis à jour `P3 → P4 → P8 → P6 → P7 → P5 → P9 → P1 → P2` (P9 inséré entre P5 et P1 : opération structurelle avant cleanup final).
- **`Activator::seed_presets()`** : P9 ajouté avec `enabled: true` par défaut (consistance avec les 8 autres).
- **`Rest\PresetsController`** : `KNOWN_IDS` et regex route `/presets/(?P<id>P[1-9])` étendus à P9.

#### SPA — bouton « Scanner le corpus »

- **Nouveau composant** `views/Normalize/ScanBar.jsx` au-dessus de `TabsHeader` dans l'onglet Normaliser. Bouton qui déclenche le scan diagnostic complet — équivalent UI du `wp htmln scan` CLI, utile après modification des règles activées pour rafraîchir la colonne « Règles applicables ».
- **Nouveau hook** `hooks/useScanBatch.js` : pilote `POST /diagnostics/run` puis boucle séquentielle des chunks via `POST /diagnostics/run/chunk`. Cumul `progress.processed` maintenu côté client (le serveur retourne `processed` = count du chunk courant).
- **États visuels** : idle (bouton + hint) / scanning (barre de progression avec compteur `X / N articles`). Bouton désactivé pendant un pas (`isRunning`) pour éviter scan concurrent qui fausserait les métriques pre/post.
- **À la fin du scan** : refetch automatique de `stats` + `list` (mêmes données que post-pas finalisé).

#### Tests

- **17 tests** `UnwrapHeadingImageRuleTest` : id, label, désencapsulation h1-h6, préservation wrappers `<a>`/`<figure>`, négatives (texte présent, vide sans img, vide tout court), NBSP, alt-only, `countMatches` cohérent avec `apply`, idempotence, malformé.
- **Fixtures** : `unwrap-heading-image-input.html` / `-expected.html`.
- **Tests existants** mis à jour pour passer de 8 à 9 préréglages : `ActivatorTest`, `PresetsControllerTest` (assertCount + regex route).

#### Désinstallation

- `uninstall.php` : pas de purge supplémentaire requise (P9 partage l'option `son100_htmln_presets` déjà nettoyée).

### Stats

- **PHPUnit** : 581 → 601 tests verts (+20), 1299 → 1349 assertions
- **Bundle SPA** : 68.5 → 71.0 KiB JS (scan button), CSS 14.2 → 14.7 KiB
- **Lint JS** : 0 erreur, 0 warning
- **PHPStan** : niveau 6, 22 erreurs (inchangé)

---

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
