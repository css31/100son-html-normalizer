# 100son HTML Normalizer

> Moteur de **normalisation HTML configurable** pour WordPress — 8 préréglages prêts à l'emploi, garde-fou anti-perte de contenu, et API publique consommable par d'autres extensions.

[![Version](https://img.shields.io/badge/version-0.1.0-orange.svg)](CHANGELOG.md)
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

### Les 8 préréglages livrés (V0.1)

| Code | Nom | Action |
|:---:|---|---|
| **P1** | `EmptyParagraphs` | Supprime les `<p>` vides (et `<p>&nbsp;</p>`, `<p><br></p>`…) |
| **P2** | `EmptyHeadings` | Supprime les `<hN>` vides ou ne contenant que des espaces |
| **P3** | `ShareaholicShortcode` | Supprime les shortcodes `[shareaholic …]` orphelins |
| **P4** | `PinterestArtifacts` | Nettoie les artefacts Pinterest (formes A + B) résiduels |
| **P5** | `ExcessiveBr` | Réduit les rafales `<br><br><br>…` à un seul `<br>` (paramétrable) |
| **P6** | `RemoveInlineStyles` | Supprime tout `style="…"` en ligne (option `keep_text_align` pour préserver les alignements) |
| **P7** | `AsciiList` | Convertit les listes ASCII (`* item`, `- item`) en vraies `<ul><li>` |
| **P8** | `RecoverSemanticStyles` | Récupère le sens : `<span style="font-weight:bold">` → `<strong>`, etc., **avant** que P6 ne tape |

**Pipeline canonique :** P3 → P4 → P8 → P6 → P7 → P5 → P1 → P2
(L'ordre est figé pour préserver le sens : on récupère la sémantique avant de purger les styles, on enlève le shortcode avant qu'il génère des paragraphes vides, etc.)

Chaque préréglage est **activable / désactivable indépendamment** depuis l'admin, et la plupart exposent des paramètres fins (ex. P5 : seuil minimum de `<br>` consécutifs).

### Garde-fou perte de contenu

Toute normalisation calcule **avant / après** :
- nombre de mots (UTF-8)
- nombre de caractères
- nombre d'images

…et lève une **alerte de sévérité** :

| Niveau | Seuil |
|---|---|
| 🟢 OK | Pertes < 10 % de mots et 0 image perdue |
| 🟠 Attention | ≥ 10 % de mots perdus **ou** 1 image perdue |
| 🔴 Critique | ≥ 30 % de mots perdus **ou** ≥ 2 images perdues |

L'alerte est **non-bloquante** : c'est un signal pour l'admin, pas une censure.
Les métriques sont aussi **stockées dans chaque entrée du journal** → auditabilité.

### Interface d'administration (V0.1)

Menu top-level **HTML Normalizer**, 4 pages :

| Page | Rôle |
|---|---|
| **Préréglages** | Activer/désactiver chaque préréglage et régler ses paramètres. Case maître « Tout activer / désactiver », bouton Enregistrer en haut et en bas. |
| **Tester un fragment** | Coller un bout de HTML, voir le résultat normalisé en source + rendu. Utile pour le débogage et pour comprendre l'effet des préréglages. |
| **Normaliser** | Liste paginée des articles (post / page / CPT publics). Filtres : recherche par titre, catégorie, année, mois, SiteOrigin oui/non. Tri ID / Titre / Date. Aperçu avant/après côte-à-côte avec métriques. Action groupée pour normaliser une sélection. |
| **Journal** | 500 dernières entrées (FIFO) : normalisations, aperçus, changements de configuration. Badge sévérité couleur. Lien vers article + révision. Zone de note libre persistante. |

### API publique — utilisation depuis une autre extension

L'extension expose **un seul filtre** WordPress :

```php
$cleaned = apply_filters( 'htmln/normalize', $html, $context );
```

- **`$html`** *(string)* — HTML d'entrée
- **`$context`** *(array)* — métadonnées libres (ex. `[ 'source' => 'siteorigin', 'post_id' => 1234 ]`) — exploitées par les règles utilisateur en V1+
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
- **Composer** pour l'installation des dépendances (chaîne d'outils de développement uniquement)

### Depuis le dépôt

```bash
cd wp-content/plugins/
git clone git@github.com:css31/100son-html-normalizer.git
cd 100son-html-normalizer
composer install
```

Puis activer l'extension depuis l'admin WordPress (**Extensions → 100son HTML Normalizer**).

### Pourquoi `composer install` ?

Le dossier `vendor/` n'est pas versionné (convention PHP/Composer). Il est régénéré à
l'identique à partir de `composer.lock` qui, lui, est suivi en gestion de version —
garantissant les **versions exactes** des dépendances chez tout le monde.

---

## Architecture

```
100son-html-normalizer/
├── 100son-html-normalizer.php      En-tête WP + amorçage + garde-fous PHP/WP
├── uninstall.php                   Purge des options à la désinstallation
├── includes/
│   ├── Plugin.php                  Singleton orchestrateur
│   ├── Activator.php / Deactivator.php
│   ├── Admin/
│   │   ├── Menu.php                Définition des 4 sous-pages
│   │   └── Pages/                  PresetsPage, TesterPage, PostsPage, LogsPage
│   ├── Api/
│   │   └── PublicApi.php           Filtre htmln/normalize
│   ├── Core/
│   │   ├── HtmlNormalizer.php      Façade haut niveau
│   │   ├── Pipeline.php            Orchestration ordre canonique
│   │   ├── Dom/DomHtml.php         Enveloppe DOMDocument (analyse/sérialisation)
│   │   ├── Registry/PresetRegistry.php
│   │   ├── Rules/                  P1…P8 + RuleInterface
│   │   ├── Logs/                   LogRepository (FIFO 500), Logger, NotesRepository
│   │   ├── Metrics/HtmlMetrics.php Garde-fou perte de contenu
│   │   └── Posts/                  PostNormalizer, SiteOriginDetector
│   └── Settings/
│       └── SettingsRepository.php
├── tests/                          181 tests PHPUnit verts
└── composer.json / composer.lock
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

Pas de table custom — repose sur les options WP et les révisions natives.

---

## Pile technique

- **PHP 8.3+** — `declare( strict_types=1 )` partout, types stricts paramètres + retours
- **WordPress 6.8+** — multisite **non** supporté
- **Composer** — autoloader PSR-4
- **PHPUnit** — 181 tests verts, 239 assertions
- **PHPStan** — niveau 6
- **PHPCS** — WordPress Coding Standards + PSR-12
- **Conventional Commits**, branche `main`, SemVer (`0.x` pré-V1)
- *(à venir V1)* `@wordpress/scripts` + `@wordpress/components` pour la SPA admin React

---

## Lancer les tests

```bash
cd wp-content/plugins/100son-html-normalizer/
vendor/bin/phpunit
```

Tester le filtre depuis WP-CLI (bac à sable DevKinsta) :

```bash
wp eval 'echo apply_filters("htmln/normalize", "<p style=\"color:red\"></p><p>OK</p>", []);'
# → <p>OK</p>
```

---

## Feuille de route

### ✅ Livré en V0.1

- §11.1 Amorçage & infrastructure
- §11.2 SettingsRepository + UserRulesRepository
- §11.3 RuleInterface + 8 préréglages + fixtures + tests
- §11.4 PresetRegistry
- §11.6 Pipeline + HtmlNormalizer
- §11.7 PublicApi (filtre `htmln/normalize`)
- §11.13 F8 Normaliser des articles (UI PHP au lieu de REST/SPA)
- *Hors cahier* : UI admin V0.1, page Journal, garde-fou métriques, note libre

### ⏭️ À venir (V0.x → V1.0)

- §11.5 — F5 HeadingStrategist (heuristique de saut h1→h3, h4 décoratifs)
- §11.8 — REST controllers
- §11.9 — RuleValidator + tests sécurité
- §11.10 — F4 règles custom : `CssSelectorRule` (mode simple) + `RegexRule` (mode avancé)
- §11.11 — PreviewRunner + endpoints REST `/rules/preview`, `/rules/validate`
- §11.12 — F7 Export / Import de règles
- §11.14 — WP-CLI (`wp htmln normalize`, `preview`, `presets`, `rules`)
- §11.15 — SPA admin React (remplace l'UI PHP V0.1)
- §11.16 — i18n (.pot)
- §11.18 — Recette critères §8 du cahier

Voir le [CHANGELOG](CHANGELOG.md) pour le détail livré.

---

## Extension compagne

**`100son-so-to-blocks`** *(à venir)* — migration des articles SiteOrigin Page Builder vers
les blocs Gutenberg/FSE. Consomme la sortie de HTML Normalizer via le filtre
`htmln/normalize` entre l'extraction du HTML d'un widget Editor et la conversion en blocs.

HTML Normalizer est **optionnel** côté SO to Blocks : sans lui, l'extension a un chemin dégradé
(HTML brut → blocs, sans normalisation préalable).

---

## Licence

GPL-2.0-or-later — voir l'en-tête du fichier [`100son-html-normalizer.php`](100son-html-normalizer.php).

## Auteur

**Cyrille / 100son.net** — [https://100son.net](https://100son.net)
Dépôt : [github.com/css31/100son-html-normalizer](https://github.com/css31/100son-html-normalizer)
