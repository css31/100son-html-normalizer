/**
 * Hooks d'accès au mode de session courant. Faits pour rester *triviaux*
 * à utiliser sur n'importe quel bouton mutatif :
 *
 *   const isReadOnly = useIsReadOnly();
 *   <Button disabled={ isReadOnly || existingDisabled } …>
 *
 * Et pour le visuel cohérent, on peut wrapper dans `<ReadOnlyTooltip>`
 * (cf. `components/ReadOnlyTooltip.jsx`).
 */

import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';

/**
 * Libellé de tooltip cohérent affiché à chaque contrôle désactivé en
 * lecture seule. Exporté pour permettre une utilisation indirecte
 * (`title="…"`) sans dépendre du composant Tooltip.
 *
 * @type {string}
 */
export const READ_ONLY_TOOLTIP_TEXT = __(
	'Session secondaire — prenez la main pour modifier.',
	'100son-html-normalizer'
);

/**
 * Vrai ssi la session courante est en lecture seule (mode `'secondary'`).
 * Renvoie `false` tant que le mode n'a pas été initialisé (`null`), pour
 * éviter de désactiver des contrôles pendant le boot.
 *
 * @return {boolean} `true` si lecture seule, `false` sinon (primary ou pending).
 */
export function useIsReadOnly() {
	return useSelect(
		( select ) => 'secondary' === select( STORE_NAME ).getSessionMode(),
		[]
	);
}

/**
 * Vrai ssi la session courante détient le verrou (mode `'primary'`).
 *
 * @return {boolean} `true` si primaire, `false` sinon (secondary ou pending).
 */
export function useIsPrimary() {
	return useSelect(
		( select ) => 'primary' === select( STORE_NAME ).getSessionMode(),
		[]
	);
}

/**
 * Mode brut (`'primary'`, `'secondary'` ou `null`). Utile aux composants
 * qui rendent un visuel différent selon les 3 cas.
 *
 * @return {?('primary'|'secondary')} Mode courant ou null avant le premier acquire.
 */
export function useSessionMode() {
	return useSelect( ( select ) => select( STORE_NAME ).getSessionMode(), [] );
}
