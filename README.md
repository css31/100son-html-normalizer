# 100son HTML Normalizer

> Moteur de **normalisation HTML configurable** pour WordPress — 8 règles prêts à l'emploi, application par **lot** avec garde-fou de régression, historique tracé, et API publique consommable par d'autres extensions.

[![Version](https://img.shields.io/badge/version-1.0.0--rc1-orange.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-8.3+-blue.svg)](#pile-technique)
[![WordPress](https://img.shields.io/badge/WordPress-6.8+-21759b.svg)](#pile-technique)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](#licence)

---

## Pourquoi cette extension ?

Au fil des années, un site WordPress accumule du **HTML sale** dans `post_content` :
paragraphes vides, titres sans contenu, balises `<br>` à la chaîne, styles en ligne laissés
par d'anciens éditeurs visuels, shortcodes orphelins d'extensions désinstallées, listes en `*`
saisies à la main, artefacts de copier-coller (Pinterest, MS Word…).

Ce **fatras n'est pas neutre** : il dégrade l'accessibilité, fausse les sommaires Gutenberg,
parasite les conversions vers les blocs FSE, et complique toute future migration de contenu.

**100son HTML Normalizer** centralise dans un seul moteur les règles de nettoyage
qu'on réécrit habituellement à coups de `preg_replace` éparpillés. Il a été conçu comme la
**brique amont** d'une chaîne de migration plus large (notamment de l'extension sœur
`100son-so-to-blocks`, dédiée à la conversion SiteOrigin → Gutenberg), mais il est
**utilisable seul** : un nettoyeur HTML générique, configurable, testable, auditable.

---

## Ce que fait l'extension

### Les 16 règles

| Code | Nom | Action |
|:---:|---|---|
| **R1** | `EmptyParagraphs` | Supprime les `<p>` vides (et `<p>&nbsp;</p>`, `<p><br></p>`…) |
| **R2** | `EmptyHeadings` | Supprime les `<hN>` vides ou ne contenant que des espaces |
| **R3** | `ShareaholicShortcode` | Supprime les shortcodes `[shareaholic …]` orphelins |
| **R4** | `PinterestArtifacts` | Nettoie les artefacts Pinterest (formes A + B) résiduels |
| **R5** | `ExcessiveBr` | Réduit les rafales `<br><br><br>…` à un seul `<br>` (paramétrable) |
| **R6** | `RemoveInlineStyles` | Supprime tout `style="…"` en ligne (option `keep_text_align` pour préserver les alignements) |
| **R7** | `AsciiList` | Convertit les listes ASCII (`* item`, `- item`) en vraies `<ul><li>` |
| **R8** | `RecoverSemanticStyles` | Récupère le sens : `<span style="font-weight:bold">` → `<strong>`, etc., **avant** que R6 ne tape |
| **R9** | `UnwrapHeadingImage` | Désencapsule les `<hN>` autour d'images (`<h2><img></h2>` → `<img>`). Préserve les wrappers internes (`<a>`, `<figure>`). Symétrique de R2. |
| **R10** | `UnwrapParagraphImage` | Désencapsule les `<p>` autour d'images (`<p><img></p>` → `<img>`). Cousine de R9 appliquée aux `<p>`. |
| **R11** | `HeadingCaptionToFigcaption` | Disposition contextuelle des `<h4>` (convention MMM : jamais un vrai sous-titre, toujours un détournement). 3 cas : (1) `<p><img></p><h4>légende</h4>` → `<figure>`+`<figcaption>` ; (2) h4 orphelin juste après chapô-lead seul → `<p class="chapo">` crédit (gras strippé) ; (3) h4 orphelin ailleurs → `<p><strong>` gras. |
| **R12** | `HeadingMixedToFigure` | Variante inline de R11 : `<h4>img + texte</h4>` (image et légende dans le **même** h4) → `<figure>img<figcaption>texte</figcaption></figure>`. Mode tolérant multi-images (forme HTML5 normative pour groupe d'images partageant une caption commune). |
| **R13** | `H2ChapoToParagraph` | Promotion : le **premier** `<h2>` phrase-chapô du fragment (≥ 5 mots + ponctuation) → `<p class="chapo">`. Seul le premier h2 du document est candidat ; les h2 ultérieurs (vrais sous-titres de section) restent intacts. |
| **R14** | `FirstParagraphChapo` | Complément de R13 : ajoute `class="chapo"` au **premier `<p>` phrase** du fragment, si c'est le premier élément significatif. Étend aux paragraphes de crédits adjacents (max 3) — « LA RÉDACTION », « PHOTOS Untel »… Descend dans les wrappers transparents (`<div>`, `<section>`). |
| **R15** | `MergeAdjacentInlineTags` | Fusionne deux éléments inline adjacents (même tag + mêmes attributs) : `<em>foo</em><em>bar</em>` → `<em>foobar</em>`, `<span style="X">A</span><span style="X">B</span>` → `<span style="X">AB</span>`. Whitelist de 29 inlines (em, strong, span, font…). Exclut `<p>`, `<div>`, headings, `<a>`, `<li>`, void. |
| **R16** | `StripHeadingPrefix` | Retire les préfixes typographiques en tête des `<h1>`-`<h6>` : numéros (« 1. », « 23) », « 5° »), puces (`•` `‣` `►` `▸` `*`), tirets (`-` `–` `—`). Walk DOM (le préfixe peut être emballé dans un `<strong>` ou `<span>`). Convention : un heading porte un titre, pas une marque de liste. |

**Pipeline canonique :** R3 → R4 → R8 → R13 → R14 → R6 → R7 → R5 → R15 → R16 → R9 → R12 → R11 → R10 → R1 → R2.
L'ordre est figé pour préserver le sens : on récupère la sémantique avant de purger les styles, on enlève le shortcode avant qu'il génère des paragraphes vides, etc. Chaque règle est **activable / désactivable indépendamment** depuis l'admin.

### Workflow par lots (V1.0)

À partir de la V1.0, la normalisation d'un corpus se fait par **lots** (« step » côté code anglais, « lot » côté UI française) plutôt qu'en bulk d'un coup. Un *lot* = un sous-ensemble de règles appliqué à une sélection d'articles, exécuté article par article avec révision et contrôle de régression.

Pipeline d'un lot (cf. cahier §4.4.2) :

```
pour chaque article sélectionné :
   1.  wp_save_post_revision()            ← garde-fou §13 — toujours, avant toute écriture
   2.  metrics(before)                    ← snapshot 7 métriques γ
   3.  Pipeline::applySubset(rules, html) ← applique le sous-ensemble de règles cochées
   4.  metrics(after)                     ← snapshot post-normalisation
   5.  RegressionDetector::analyze()      ← compare before / after vs seuils γ
       └─ si dépassement → status `regression_pending`, aucune écriture
       └─ sinon          → wp_update_post + recalcul du diagnostic
   6.  StepsRepository::update_per_article_result()
```

À tout moment, l'admin peut :

- **Voir le diff** d'un article avant d'agir (Modal plein écran, code et rendu côte à côte) ;
- **Refuser** une régression (pose `_son100_htmln_manual_check_required=1`, aucune écriture) ;
- **Confirmer** une régression (écriture forcée, la trace du rapport reste dans l'historique).

Tous les lots sont tracés dans la table `son100_htmln_steps` avec leurs `per_article_results`, consultables depuis l'onglet **Historique** de la SPA admin et en CLI via `wp htmln steps`.

### État des règles : « activée » vs « sélectionnée »

Dans l'onglet **Règles** de la SPA, chaque card expose **deux contrôles distincts** qui n'ont pas du tout le même rôle :

| Contrôle | Stockage | Effet |
|---|---|---|
| 🔘 Toggle **« Activée par défaut »** | Persistant en BDD (option `son100_htmln_presets`) | Pilote ce qui est **appliqué dans le pipeline complet** (filtre `htmln/normalize`, page Aperçu, F8) ET ce qui est **calculé pour le diagnostic** (colonne « Règles applicables ») |
| ☑️ Checkbox **« Sélectionnée pour le prochain lot »** | Éphémère en mémoire (`htmln/spa.selectedRules`, store SPA) | Pilote uniquement quelles règles seront appliquées au prochain **clic « Appliquer ce lot à K articles »** (workflow F14). Reset à « tout coché » au reload de la page |

#### Ce que montre la colonne « Règles applicables »

Elle affiche les règles qui ont **matché le contenu** d'un article **lors du dernier scan**, parmi les règles **activées globalement (toggle ON)** à ce moment-là. **Sans rapport avec la sélection éphémère du prochain lot.**

Plus précisément, au scan :

1. `DiagnosticEngine::diagnose()` itère `PresetRegistry::get_enabled_rules()` — uniquement les règles avec toggle ON, persistées en BDD.
2. Pour chacune, il appelle `countMatches($post_content)` (lecture pure, pas de modification).
3. Si `count > 0`, la règle est ajoutée à `matching_rules` du diagnostic, avec son compte d'occurrences.
4. La colonne affiche la liste `matching_rules` brute du diagnostic stocké dans `son100_htmln_diagnostics`.

#### Conséquences pratiques

| Action | Effet sur la colonne « Règles applicables » |
|---|---|
| Cocher/décocher **« Sélectionnée pour le prochain lot »** | **Aucun** — la colonne n'est pas recalculée. Tu changes uniquement ce qui s'appliquera au prochain lot F14 |
| Toggler **« Activée par défaut »** d'une règle OFF, **sans rescan** | La colonne continue d'afficher cette règle sur les articles concernés (le diagnostic stocké en base n'a pas été recalculé) |
| Toggler **« Activée par défaut »** d'une règle puis **relancer un scan** | La colonne reflète le nouvel état des règles activées sur tous les articles |
| Modifier un article via Gutenberg | Son diagnostic devient `is_stale=1` et l'article apparaît dans l'onglet `stale`. Au prochain scan, son diagnostic est recalculé avec l'état actuel des règles activées |

**En une phrase** : « Règles applicables » = règles avec toggle **ON** qui matchaient le contenu **au moment du dernier scan**. Si tu vois une incohérence entre l'état actuel des toggles et la colonne, c'est probablement que la colonne est issue d'un scan antérieur — bouton **« Scanner le corpus »** (ou « Scanner la sélection (N) ») pour rafraîchir.

### Onglet Notes (V1.0)

La SPA expose un onglet **Notes** qui embarque un éditeur Gutenberg restreint pour saisir des notes libres persistées côté serveur. Pratique pour garder un carnet de bord, des questions en suspens, des `TODO` de campagne, etc. — sans avoir à créer un brouillon de post dédié.

- **Blocs disponibles** (whitelist) : paragraphe, titre, liste, citation, code, séparateur, image, tableau. Embeds et patterns désactivés.
- **Persistance** : option dédiée `son100_htmln_notes_rich`, contenu sérialisé en *block grammar* Gutenberg (commentaires `<!-- wp:* -->` inclus). Sanitization via `wp_kses_post()` — strippe le code dangereux, préserve la grammaire.
- **Sauvegarde explicite** via bouton « Enregistrer » (pas d'autosave) — l'indicateur « Modifications non enregistrées. » s'affiche tant que tu n'as pas validé.
- **Désinstallation** : la note est **supprimée** lors de l'uninstall. Exporte le contenu si tu veux le conserver.

> **Note** : un carnet de notes en plain text reste accessible sur la page V0.1 « Journal » (option séparée `son100_htmln_logs_notes`). Les deux cohabitent jusqu'à la disparition des pages V0.1 en V1.1.

### Seuils γ — garde-fou anti-perte de contenu

La régression est définie comme une **perte au-delà d'un seuil γ** sur l'une des 7 métriques structurelles d'un article. Tous les seuils sont configurables dans l'onglet **Réglages** de la SPA admin.

| Métrique | Unité | Default |
|---|---|:---:|
| `text_loss_pct` | % de caractères perdus | 0 |
| `words_loss_pct` | % de mots perdus | 0 |
| `paragraphs_loss_pct` | % de paragraphes perdus | 5 |
| `headings_loss` | nombre absolu (par niveau h1..h6 indépendamment) | 0 |
| `images_loss` | nombre absolu | 0 |
| `links_loss` | nombre absolu | 0 |
| `lists_loss` | nombre absolu (`<ul>` / `<ol>`) | 0 |

Sémantique : la perte tolérée est **stricte au-delà du seuil** (`loss > γ` déclenche, `loss == γ` n'alerte pas). Les valeurs par défaut sont volontairement prudentes — on préfère une alerte de plus qu'une perte silencieuse. Un seuil à 0 signifie « toute perte alerte ».

Source de vérité côté code : `Cent_Son\Html_Normalizer\Settings\SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS` et `Cent_Son\Html_Normalizer\Regression\RegressionDetector::analyze()`.

### Interface d'administration

Menu top-level **HTML Normalizer**, qui héberge cinq sous-pages en cohabitation V0.1 + V1.0 :

| Page | Phase | Rôle |
|---|:---:|---|
| **Règles** | V0.1 | Activer/désactiver chaque règle et régler ses paramètres. |
| **Tester un fragment** | V0.1 | Coller un bout de HTML, voir le résultat normalisé en source + rendu. |
| **Normaliser** | V0.1 | Liste paginée des articles (V0.1, normalisation immédiate sans pas). |
| **Journal** | V0.1 | 500 dernières entrées (FIFO) : normalisations, aperçus, changements de configuration. |
| **Normaliser V1** | V1.0 | SPA React unique avec 3 onglets internes : `Normaliser` (F13/F14/F14.3/F15), `Historique` (F16), `Réglages` (seuils γ). |

La SPA V1.0 utilise un router hash interne (`#/normalize`, `#/history`, `#/settings`) sans dépendance externe. Les pages V0.1 et la SPA V1.0 coexistent — la migration complète des pages V0.1 vers la SPA est différée V1.1.

### Surface REST (V1.0)

Toutes les routes sont sous le namespace `htmln/v1`, capability `manage_options` (cf. cahier §14 hyp. 14).

```
Steps        (7) : GET  /steps, GET  /steps/<uuid>, POST /steps/run,
                   POST /steps/<uuid>/{process, confirm-article, finalize},
                   GET  /steps/export
Diagnostics  (6) : GET  /diagnostics, POST /diagnostics/{run, run/chunk},
                   GET / DELETE /diagnostics/<post_id>, GET /diagnostics/stats
Posts        (5) : GET  /posts/{post-types, scan}, GET /posts/<id>/preview,
                   POST /posts/<id>/normalize, POST /posts/batch-normalize
Diff         (1) : POST /posts/<id>/diff
Settings     (1) : GET / POST /settings/regression-thresholds
```

Format d'erreur unifié `{code, message, data: {status, ...}}` aligné sur la sérialisation native `WP_Error`.

### Surface CLI (V1.0)

```bash
wp htmln steps list   [--from=<date>] [--to=<date>] [--limit=<n>]
wp htmln steps show   <uuid>
wp htmln steps export [--file=<path>] [--from=<date>] [--to=<date>]
wp htmln scan         [<id> | --all | --status=stale [--rebuild]]
```

Sortie JSON pretty-printée. Export CSV différé V1.1.

### API publique — utilisation depuis une autre extension

L'extension expose **un seul filtre** WordPress, stable depuis V0.1 :

```php
$cleaned = apply_filters( 'htmln/normalize', $html, $context );
```

- **`$html`** *(string)* — HTML d'entrée
- **`$context`** *(array)* — métadonnées libres (ex. `[ 'source' => 'siteorigin', 'post_id' => 1234 ]`)
- **Retour** *(string)* — HTML normalisé. **Toujours une string**, jamais `null`/`false`/exception.

**Contrat de robustesse** : si l'extension est désactivée, le filtre n'existe pas et `apply_filters` renvoie `$html` inchangé. Le code consommateur n'a rien à protéger.

Exemple d'usage depuis une autre extension :

```php
add_action( 'save_post', function ( $post_id, $post ) {
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    $clean = apply_filters( 'htmln/normalize', $post->post_content, [
        'source'  => 'my-plugin',
        'post_id' => $post_id,
    ] );
    // …faire quelque chose avec $clean
}, 10, 2 );
```

---

## Installation

### Pré-requis

- **PHP 8.3+**
- **WordPress 6.8+** (FSE requis)
- **Composer** pour l'installation des dépendances PHP
- **Node 20+** + **npm** pour rebuild la SPA admin

### Depuis le dépôt

```bash
cd wp-content/plugins/
git clone git@github.com:css31/100son-html-normalizer.git
cd 100son-html-normalizer
composer install
npm install
npm run build
```

Puis activer l'extension depuis l'admin WordPress (**Extensions → 100son HTML Normalizer**).

L'activation crée automatiquement les 2 tables custom (`son100_htmln_diagnostics` et `son100_htmln_steps`) via `dbDelta()`.

### Pourquoi `composer install` et `npm run build` ?

Les dossiers `vendor/` et `assets/build/` ne sont pas versionnés (convention PHP/Composer et JS/webpack). Ils sont régénérés à l'identique à partir de `composer.lock` et `package-lock.json` qui, eux, sont suivis en gestion de version — garantissant les **versions exactes** des dépendances chez tout le monde.

---

## Architecture

```
100son-html-normalizer/
├── 100son-html-normalizer.php      En-tête WP + amorçage + garde-fous PHP/WP
├── uninstall.php                   Purge des options + DROP tables custom à la désinstallation
├── webpack.config.js               Surcharge @wordpress/scripts (entry + output)
├── assets/
│   ├── src/admin-spa/              Source SPA React V1.0 (router, hooks, store, views)
│   └── build/                      Bundle minifié (gitignoré, régénéré via npm run build)
├── languages/
│   └── 100son-html-normalizer.pot  Fichier de traduction source (329 chaînes)
├── includes/
│   ├── Plugin.php                  Singleton orchestrateur + composition root
│   ├── Activator.php               dbDelta des 2 tables custom
│   ├── Admin/                      Menu + 4 pages V0.1 + SpaPage V1.0 + Assets enqueue
│   ├── Api/                        PublicApi (filtre htmln/normalize)
│   ├── Cli/                        StepsCommand + DiagnoseCommand + CliServiceProvider
│   ├── Core/                       HtmlNormalizer, Pipeline, Rules R1..R8, Logs, Posts, Registry
│   ├── Diagnostics/                DiagnosticEngine, BatchRunner, Invalidator (hook save_post), Repository
│   ├── Metrics/                    MetricsCalculator + MetricsSnapshot (7 métriques γ)
│   ├── Regression/                 RegressionDetector + DTOs Failure/Report/Thresholds
│   ├── Rest/                       BaseController + 5 controllers + RestServiceProvider
│   ├── Settings/                   SettingsRepository
│   └── Steps/                      StepRunner + ArticleResult + StepRecord + StepsRepository
├── tests/                          548 tests PHPUnit verts
├── composer.json / composer.lock
└── package.json / package-lock.json
```

### Conventions internes

| Élément | Préfixe |
|---|---|
| Slug, text-domain | `100son-html-normalizer` |
| Namespace PHP | `Cent_Son\Html_Normalizer\` |
| Fonctions | `son100_htmln_*` |
| Constantes | `SON100_HTMLN_*` |
| Hooks | `htmln/*` (court — public) |
| Options / transients | `son100_htmln_*` |
| Post-meta | `_son100_htmln_*` |
| Tables custom | `{$wpdb->prefix}son100_htmln_*` |

---

## Pile technique

- **PHP 8.3+** — `declare( strict_types=1 )` partout, types stricts paramètres + retours, DTOs `readonly`
- **WordPress 6.8+** — multisite **non** supporté
- **Composer** — autoloader PSR-4
- **PHPUnit** — 548 tests verts (1213 assertions)
- **PHPStan** niveau 6 — baseline pré-Phase 4 (22 erreurs sur du code historique, 0 régression depuis)
- **PHPCS** — WordPress Coding Standards + PSR-12
- **`@wordpress/scripts`** + **`@wordpress/components`** + **`@wordpress/data`** — SPA admin V1.0
- **Node 20+ / npm** — build front
- **Conventional Commits**, branche `main`, SemVer (`0.x` pré-V1, `1.0.0` cible V1)

---

## Lancer les tests

```bash
cd wp-content/plugins/100son-html-normalizer/

# Tests PHP
vendor/bin/phpunit

# Analyse statique
vendor/bin/phpstan analyse

# Lint front
npm run lint:js

# Rebuild bundle
npm run build
```

Tester le filtre depuis WP-CLI :

```bash
wp eval 'echo apply_filters("htmln/normalize", "<p style=\"color:red\"></p><p>OK</p>", []);'
# → <p>OK</p>
```

Régénérer le `.pot` après ajout de nouvelles chaînes :

```bash
wp i18n make-pot . languages/100son-html-normalizer.pot \
   --domain=100son-html-normalizer \
   --exclude=vendor,node_modules,tests,assets/build
```

---

## Feuille de route

### ✅ Livré

**V0.1** — moteur PHP + UI admin V0.1 (4 pages : Règles, Tester, Normaliser, Journal).

**V1.0 (en cours d'intégration)** — couche diagnostic + lots + SPA :
- Diagnostic structurel (Phase 3) : `DiagnosticEngine`, `DiagnosticBatchRunner`, hook `save_post → is_stale`
- Détection de régression (Phase 3.1) : 7 seuils γ + `RegressionDetector`
- Application par lot (Phase 4) : `StepRunner` avec confirm/refuse/resume/finalize idempotent
- Surface REST 19 routes (Phase 5) + WP-CLI 4 commandes (Phase 5.5)
- SPA d'administration React (Phase 6) : 3 onglets `Normaliser` / `Historique` / `Réglages` avec modales `DiffModal` (F14.3) et `RegressionModal` (F15), bandeaux de lot en cours et de reprise (F14.4), `beforeunload` natif
- i18n : text-domain branché, `.pot` généré (329 chaînes)

### ⏭️ Différé V1.1

- HeadingStrategist (heuristique de saut h1→h3, h4 décoratifs — §11.5)
- Règles custom F4 : `CssSelectorRule` + `RegexRule` (§11.10)
- Export / Import de règles (§11.12)
- Migration complète des pages V0.1 vers la SPA (Dashboard, Presets SPA, CustomRules, Journal SPA)
- Export CSV de l'historique des lots (V1.0 ne fournit que JSON)
- Recette finale §8 du cahier

Voir le [CHANGELOG](CHANGELOG.md) pour le détail livré.

---

## Extension compagne

**`100son-so-to-blocks`** *(en cours de cadrage)* — migration des articles SiteOrigin Page Builder vers
les blocs Gutenberg/FSE. Consomme la sortie de HTML Normalizer via le filtre `htmln/normalize` entre l'extraction du HTML d'un widget Editor et la conversion en blocs.

HTML Normalizer est **optionnel** côté SO to Blocks : sans lui, l'extension a un chemin dégradé
(HTML brut → blocs, sans normalisation préalable).

---

## Licence

GPL-2.0-or-later — voir l'en-tête du fichier [`100son-html-normalizer.php`](100son-html-normalizer.php).

## Auteur

**Cyrille / 100son.net** — [https://100son.net](https://100son.net)
Dépôt : [github.com/css31/100son-html-normalizer](https://github.com/css31/100son-html-normalizer)
