# Protocole de recette — HTML Normalizer

> **Version visée** : `1.0.0-rc2` (HEAD `8423b5e`) → `1.0.0` final
> **Date de rédaction** : 2026-05-12
> **Environnement cible** : sandbox DevKinsta `ma-maison-mag-2` (corpus pristine SiteOrigin, 728 posts)

## Mode d'emploi

Protocole structuré en **phases incrémentales** — chaque phase ajoute un niveau de garantie. Tu peux t'arrêter après une phase intermédiaire si le périmètre visé est limité.

- **Phases A → C** = smoke test + nouvelle feature Notes (≈ 20 min)
- **Phases A → I** = recette complète, condition pour tagger `v1.0.0` (≈ 1 h 30)
- **Phase L** = désinstallation, à ne faire qu'avant un release tag final, **destructif**

Coche les cases au fur et à mesure (`- [ ]` → `- [x]`). Note tes observations dans la section **Journal de recette** en fin de fichier.

---

## Sommaire

- [Phase A — Préparatifs](#-phase-a--préparatifs-5-min)
- [Phase B — Smoke test SPA](#-phase-b--smoke-test-spa-5-min)
- [Phase C — Onglet Notes](#-phase-c--onglet-notes-10-min)
- [Phase D — Onglet Règles](#-phase-d--onglet-règles-5-min)
- [Phase E — Diagnostic global](#-phase-e--diagnostic-global-5-min)
- [Phase F — Recette sur 6 IDs](#-phase-f--recette-sur-6-ids-45-min---critique)
- [Phase G — WP-CLI](#-phase-g--wp-cli-5-min)
- [Phase H — Filtre public `htmln/normalize`](#-phase-h--filtre-public-htmlnnormalize-3-min)
- [Phase I — Réglages γ](#-phase-i--réglages-γ-3-min)
- [Phase J — Désactivation/réactivation](#-phase-j--désactivationréactivation-3-min)
- [Phase K — Cohabitation V0.1](#-phase-k--cohabitation-v01-5-min--point-darbitrage)
- [Phase L — Désinstallation complète](#-phase-l--désinstallation-complète-optionnelle-destructif)
- [Critères de passage pour tag `v1.0.0`](#critères-de-passage-pour-tag-v100)
- [Journal de recette](#journal-de-recette)

---

## 🔧 Phase A — Préparatifs (5 min)

```bash
# Vérifier les prérequis runtime
docker ps --filter "name=devkinsta" --format "{{.Names}}\t{{.Status}}"
docker exec -u www-data devkinsta_fpm php8.3 /usr/local/bin/wp \
  --path=/www/kinsta/public/ma-maison-mag-2 plugin status 100son-html-normalizer

# Backup BDD sandbox AVANT toute écriture (Phase F écrira en base).
#
# Stockage dans `sauvegardes/` à la racine de l'extension — dossier
# tracké par git mais dont le contenu est ignoré (cf. sauvegardes/.gitignore).
# Profite du volume monté DevKinsta : pas besoin de pipe stdout, on
# peut passer le chemin tel quel au container (il voit le même fichier
# que l'hôte via le mount `/www/kinsta/public/ma-maison-mag-2/`).
#
# Le chemin est fourni à `wp` au format container (préfixé
# `/www/kinsta/public/ma-maison-mag-2/`) car wp-cli tourne *dans*
# devkinsta_fpm — il ne voit pas le chemin hôte `/home/cyrille/…`.
cd /home/cyrille/DevKinsta/public/ma-maison-mag-2/wp-content/plugins/100son-html-normalizer

wp-sandbox db export /www/kinsta/public/ma-maison-mag-2/wp-content/plugins/100son-html-normalizer/sauvegardes/htmln-recette-$(date +%Y%m%d-%H%M).sql

ls -lh sauvegardes/htmln-recette-*.sql | tail -1

# État de référence
cd /home/cyrille/DevKinsta/public/ma-maison-mag-2/wp-content/plugins/100son-html-normalizer
git log --oneline -3
```

**Attendus**

- [ ] HEAD = `8423b5e` ou descendant, version `1.0.0-rc2`
- [ ] 5 containers DevKinsta `Up` (nginx, fpm, db, adminer, mailhog)
- [ ] Plugin **Active** sur la sandbox
- [ ] Backup SQL créé dans `~/`

---

## 🚦 Phase B — Smoke test SPA (5 min)

Ouvre `https://ma-maison-mag-2.local/wp-admin/admin.php?page=100son-html-normalizer-spa` avec **DevTools console ouverte**.

| # | Action | Attendu |
|---|---|---|
| B1 | Hard reload `Ctrl+Shift+R` | Page charge, 5 onglets visibles, aucune erreur rouge en console |
| B2 | Clic onglet **Règles** | Liste des 8 préréglages affichée |
| B3 | Clic onglet **Normaliser** | Tableau articles + onglets internes (to_improve / normal / stale) |
| B4 | Clic onglet **Historique** | Tableau des pas (peut être vide si jamais joué) |
| B5 | Clic onglet **Notes** | Cadre éditeur avec barre haute + paragraphe vide |
| B6 | Clic onglet **Réglages** | 7 inputs seuils γ |

- [ ] B1 — Boot sans erreur console
- [ ] B2 — Onglet Règles charge
- [ ] B3 — Onglet Normaliser charge
- [ ] B4 — Onglet Historique charge
- [ ] B5 — Onglet Notes charge
- [ ] B6 — Onglet Réglages charge

> **Stop net si erreur JS** — paster la stack et debugger avant d'aller plus loin.

---

## 📝 Phase C — Onglet Notes (10 min)

| # | Action | Attendu |
|---|---|---|
| C1 | Tape du texte dans le paragraphe vide | Caractères s'affichent, pas de lag |
| C2 | Sélectionne 1 mot → `Ctrl+B` | Mot en gras |
| C3 | Sélectionne 1 mot → bouton `B` dans toolbar | Idem |
| C4 | `Ctrl+K` sur sélection → coller une URL | Lien créé |
| C5 | Clic `+ Ajouter un bloc` → choisir « Titre » | Bloc titre inséré |
| C6 | Clic `+ Ajouter un bloc` → « Liste » → taper 3 items + `Tab` sur le 3e | Liste avec sous-liste |
| C7 | Clic `+ Ajouter un bloc` → « Image » → uploader/insérer | Media library s'ouvre, image insérée |
| C8 | Clic **Enregistrer** | Notice succès « Notes enregistrées », indicateur dirty disparaît |
| C9 | `F5` (reload complet) | Contenu rechargé identique, formatages préservés |
| C10 | Clic **Vider la note** → confirmer | Éditeur vide |
| C11 | Re-saisir + Enregistrer + reload | Contenu restitué |
| C12 | Survol entre deux blocs | **Pas** de `+` au survol (désactivé) |

- [ ] C1 — Saisie réactive
- [ ] C2 — Raccourci gras
- [ ] C3 — Bouton gras
- [ ] C4 — Lien
- [ ] C5 — Insertion titre
- [ ] C6 — Liste imbriquée
- [ ] C7 — Image via medialibrary
- [ ] C8 — Save
- [ ] C9 — Reload preserve
- [ ] C10 — Clear
- [ ] C11 — Re-save preserve
- [ ] C12 — `+` au survol bien désactivé

**Vérification SQL** :

```bash
wp-sandbox option get son100_htmln_notes_rich | head -c 200
```

- [ ] Doit contenir du block grammar (`<!-- wp:paragraph -->...`)

---

## ⚙️ Phase D — Onglet Règles (5 min)

| # | Action | Attendu |
|---|---|---|
| D1 | Toggle off P1 (EmptyParagraphs) | Card grisée |
| D2 | Reload | État OFF persisté |
| D3 | Toggle on P1 | Card normale |
| D4 | Modifier param `keep_text_align` de P6 (RemoveInlineStyles) → save | Persistance |
| D5 | Modifier seuil P5 (ExcessiveBr) à `3` → save | Persistance |
| D6 | Vérifier ordre du pipeline en bas | P3 → P4 → P8 → P6 → P7 → P5 → P1 → P2 |

- [ ] D1 — Toggle off
- [ ] D2 — Persistance OFF
- [ ] D3 — Toggle on
- [ ] D4 — Param P6 persistant
- [ ] D5 — Param P5 persistant
- [ ] D6 — Ordre pipeline correct

---

## 🎯 Phase E — Diagnostic global (5 min)

Onglet **Normaliser** :

| # | Action | Attendu |
|---|---|---|
| E1 | Bouton « Scanner » ou auto au load | Comptage : ~728 articles répartis to_improve / normal / stale |
| E2 | Bascule entre les 3 onglets internes | Tableaux distincts, comptes cohérents |
| E3 | Recherche/filtre sur ID 11448 | Apparaît avec status `to_improve` |

**Cross-check CLI** :

```bash
wp-sandbox htmln scan --dry-run 2>&1 | tail -5
```

- [ ] E1 — Scan total ≈ 728
- [ ] E2 — Onglets cohérents
- [ ] E3 — ID 11448 trouvé
- [ ] Cross-check CLI totaux identiques à SPA

---

## 🧪 Phase F — Recette sur 6 IDs (45 min — **critique**)

**Pour chaque ID**, dérouler ce workflow :

1. Onglet **Normaliser** → cocher l'article
2. Activer toutes les règles (toolbar du tableau)
3. Bouton « Lancer un pas »
4. Sur la page de pas qui s'ouvre, pour chaque article :
   - Ouvrir le **Diff modal** → vérifier HTML before/after + rendu side-by-side
   - Vérifier la **MetricsDiffBar** (delta caractères / mots / headings / etc.)
   - Si pas de régression → bouton **Confirmer**
   - Si régression détectée → modal apparaît, voir cas spécifiques ci-dessous

### Attendus par ID

| ID | Spécificité à vérifier | Coche |
|---|---|---|
| **11448** | Chapô variant A préservé après normalisation. Signature « LA RÉDACTION » et « PHOTOS » ne disparaissent pas. Pas de régression `headings_loss`. | - [ ] |
| **374** | Chapô variant B (h2 court dans Editor widget unique) bien interprété. | - [ ] |
| **1392** | `post_content` déjà clean → soit `normal` (rien à faire), soit normalisation idempotente. `panels_data` non touché par cette extension (c'est SO to Blocks plus tard). | - [ ] |
| **2500** | Bullets convertis sans manger les paragraphes intermédiaires (régression regex `(?:.+?)` du `PLUGIN_CONTEXT.md` §6). | - [ ] |
| **6690** | Citation en `<p>` préservée. Italique de la signature préservé. | - [ ] |
| **6150** | Bullets bruts art3f convertis en `<ul><li>`. | - [ ] |

### Cross-checks systématiques après chaque confirm

```bash
# Le post a-t-il une révision pré-normalisation ?
wp-sandbox post list --post_type=revision --post_parent=<ID> --format=count

# Post-meta de régression refusée si tu as refusé un cas
wp-sandbox post meta get <ID> _son100_htmln_manual_check_required
```

### Onglet **Historique** après les 6 pas

| # | Action | Attendu |
|---|---|---|
| F-H1 | 6 lignes (1 par pas) avec UUID, timestamps, counters | OK |
| F-H2 | Ouvrir le drawer de détail d'un pas | per_article_results visibles |
| F-H3 | Bouton « Exporter » sur un pas | JSON téléchargé (ou copié) |

- [ ] F-H1 — 6 lignes
- [ ] F-H2 — Détail drawer
- [ ] F-H3 — Export JSON

---

## 🛠 Phase G — WP-CLI (5 min)

```bash
# Diagnostic
wp-sandbox htmln scan --dry-run

# Liste des pas
wp-sandbox htmln steps list

# Détail d'un pas créé en phase F
wp-sandbox htmln steps show <uuid-from-list>

# Export JSON d'un pas
wp-sandbox htmln steps export <uuid> > /tmp/step.json
jq '.per_article_results | length' /tmp/step.json
```

- [ ] G1 — `htmln scan` retourne un rapport cohérent
- [ ] G2 — `htmln steps list` liste les pas créés en F
- [ ] G3 — `htmln steps show` détail OK
- [ ] G4 — `htmln steps export` JSON parsable

---

## 🔌 Phase H — Filtre public `htmln/normalize` (3 min)

```bash
wp-sandbox eval '
$dirty = "<p></p><p>contenu</p><p>&nbsp;</p>";
$clean = apply_filters("htmln/normalize", $dirty, ["context" => "test"]);
echo "AVANT : " . $dirty . PHP_EOL;
echo "APRÈS : " . $clean . PHP_EOL;
'
```

**Attendu** : les `<p>` vides ont disparu, reste `<p>contenu</p>`. P1 a fait son travail via le filtre public.

- [ ] H1 — Filtre retourne du HTML normalisé
- [ ] H2 — Pas de `null` / `false` / exception

---

## 🎛 Phase I — Réglages γ (3 min)

| # | Action | Attendu |
|---|---|---|
| I1 | `text_loss_pct : 0 → 2` → Save | Notice succès |
| I2 | Reload | Valeur `2` persistée |
| I3 | Bouton « Restaurer les valeurs par défaut » → Save | Retour à 0 (et 5 pour paragraphs_loss_pct) |
| I4 | `text_loss_pct : -1` (essai validation) | Bouton Save désactivé / champ marqué invalide |

- [ ] I1 — Save accepté
- [ ] I2 — Persistance
- [ ] I3 — Restore defaults
- [ ] I4 — Validation négatif refusée

---

## 🧹 Phase J — Désactivation/réactivation (3 min)

```bash
wp-sandbox plugin deactivate 100son-html-normalizer
# Vérifier que les tables sont toujours là (no-op à la désactivation)
wp-sandbox db query "SHOW TABLES LIKE 'wp_son100_htmln_%'"
# Doit lister 2 tables : diagnostics + steps

wp-sandbox plugin activate 100son-html-normalizer
# Vérifier que tout est intact
wp-sandbox htmln steps list | head -10
```

- [ ] J1 — Désactivation propre (no-op, tables préservées)
- [ ] J2 — Réactivation, données intactes

---

## 🗑 Phase K — Cohabitation V0.1 (5 min — point d'arbitrage)

| # | Action | Observation |
|---|---|---|
| K1 | Menu admin → **HTML Normalizer → Journal** (page V0.1) | Affiche la zone de notes plain-text V0.1 + journal d'évènements |
| K2 | Saisir un texte plain dans cette zone, sauver | Stocké sur `son100_htmln_logs_notes` |
| K3 | Vérifier que ça n'affecte PAS la note riche de la SPA | `son100_htmln_notes_rich` toujours intact (cf. C7) |

→ **Décision attendue** pour `v1.0.0` final : garder les 2 pages cohabitantes, ou masquer la zone Notes V0.1 de la page Journal pour éviter la confusion utilisateur ? À trancher ensuite.

- [ ] K1 — Page Journal V0.1 accessible
- [ ] K2 — Zone notes V0.1 fonctionnelle, stockage option dédiée
- [ ] K3 — Notes SPA isolées de Journal V0.1
- [ ] **Décision actée** : ☐ cohabitation maintenue / ☐ Notes V0.1 masquée

---

## ⚠️ Phase L — Désinstallation complète (optionnelle, **DESTRUCTIF**)

À ne faire que si tu veux valider `uninstall.php`. **La sandbox sera amputée** — reprendre du backup phase A pour repartir.

```bash
# Désactiver puis désinstaller (l'UI WP confirme)
wp-sandbox plugin uninstall 100son-html-normalizer --deactivate

# Vérifier purge complète
wp-sandbox db query "SHOW TABLES LIKE 'wp_son100_htmln_%'"
# → vide

wp-sandbox db query "SELECT option_name FROM wp_options WHERE option_name LIKE 'son100_htmln_%'"
# → vide

wp-sandbox db query "SELECT meta_key FROM wp_postmeta WHERE meta_key LIKE '_son100_htmln_%' LIMIT 1"
# → vide

# Restaurer depuis le backup phase A.
# Le fichier vit dans `sauvegardes/` (dossier monté visible côté
# container), on peut donc passer le chemin container directement.
# Remplace YYYYMMDD-HHMM par l'horodatage réel — `ls sauvegardes/`
# pour retrouver.
cd /home/cyrille/DevKinsta/public/ma-maison-mag-2/wp-content/plugins/100son-html-normalizer

wp-sandbox db import \
  /www/kinsta/public/ma-maison-mag-2/wp-content/plugins/100son-html-normalizer/sauvegardes/htmln-recette-YYYYMMDD-HHMM.sql

# Reinstall pour repartir
cd /home/cyrille/DevKinsta/public/ma-maison-mag-2/wp-content/plugins/100son-html-normalizer
composer install --no-dev
npm install && npm run build
wp-sandbox plugin activate 100son-html-normalizer
```

- [ ] L1 — Tables custom droppées
- [ ] L2 — Options purgées (incl. `son100_htmln_notes_rich`)
- [ ] L3 — Post-meta purgées (`_son100_htmln_*`)
- [ ] L4 — Restore + reinstall OK

---

## Critères de passage pour tag `v1.0.0`

- [ ] Phases A à C **vertes** → onglet Notes livrable
- [ ] Phases A à I **vertes** → `v1.0.0` taggable
- [ ] Phase F sur les 6 IDs **sans régression non-prévue** → condition critique
- [ ] Phase K → décision d'arbitrage actée dans le CHANGELOG

---

## Journal de recette

> Espace libre pour noter observations, bugs détectés, décisions prises. À reporter dans `CHANGELOG.md` ou en issue après chaque session de recette.

### Session du _______________ (à remplir)

**Phases jouées** :

**Bugs détectés** :

**Décisions prises** :

**Reste à faire** :

---

### Session du _______________ (à remplir)

**Phases jouées** :

**Bugs détectés** :

**Décisions prises** :

**Reste à faire** :
