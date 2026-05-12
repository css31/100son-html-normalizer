/**
 * Point d'entrée du client REST — re-export des familles d'endpoints.
 *
 * Usage côté vues :
 *
 *   import * as api from '@admin-spa/api';
 *   await api.diagnostics.stats();
 *   await api.steps.run({ post_ids, rule_ids });
 *   await api.posts.preview( 42 );
 *
 * Cf. `includes/Rest/*Controller.php` côté serveur — les fonctions ici
 * suivent strictement les routes V1.0 documentées en cahier §4.5.
 */

export * as diagnostics from './diagnostics';
export * as steps from './steps';
export * as posts from './posts';
export * as settings from './settings';
export * as presets from './presets';
export * as notes from './notes';
export { get, post, del, raw } from './client';
