# Changelog

Toutes les modifications notables de cette extension sont consignées ici.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), versionning [SemVer](https://semver.org/lang/fr/).

## [Unreleased]

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
