# Changelog

Toutes les modifications notables de ce plugin sont consignées ici.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versionning [SemVer](https://semver.org/lang/fr/).

## [Unreleased] — 0.1.x

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
