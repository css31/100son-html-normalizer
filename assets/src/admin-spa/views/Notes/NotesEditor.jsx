/**
 * NotesEditor — éditeur Gutenberg restreint pour l'onglet Notes SPA.
 *
 * Architecture inline (pas d'iframe), avec header d'éditeur persistant +
 * toolbar fixe (`hasFixedToolbar: true`) :
 *
 *   <SlotFillProvider>
 *     <BlockEditorProvider settings={ { ..., hasFixedToolbar: true } } ...>
 *       <BlockTools>
 *         <div class="htmln-notes__editor-bar">
 *           <Inserter renderToggle={...} />     ← bouton "+ Ajouter un bloc"
 *           <BlockToolbar hideDragHandle />     ← toolbar contextuelle horizontale
 *         </div>
 *         <div class="htmln-notes__canvas editor-styles-wrapper">
 *           <WritingFlow><ObserveTyping><BlockList/></ObserveTyping></WritingFlow>
 *         </div>
 *       </BlockTools>
 *       <Popover.Slot />
 *     </BlockEditorProvider>
 *   </SlotFillProvider>
 *
 * Pourquoi ce pattern et pas le toolbar flottant par défaut :
 *  - Embarqué hors du contexte post-editor, le popover toolbar essaie de
 *    s'ancrer au bloc et n'a pas l'espace horizontal qu'il attend → ses
 *    items stackent verticalement, rendant la toolbar inutilisable.
 *  - Pas d'inserter principal persistant non plus → l'utilisateur ne sait
 *    plus comment ajouter un bloc en dehors du `+` between-blocks au hover.
 *  - `hasFixedToolbar: true` + `<BlockToolbar>` rendu explicitement = mode
 *    « document toolbar » utilisé par Site Editor / Widgets / Customize —
 *    horizontal stable, indépendant du popover positioning.
 *
 * Whitelist de blocs (`allowedBlockTypes` dans settings) : paragraph,
 * heading, list, list-item, quote, code, separator, image, table.
 * Volontairement restreint — un carnet de notes plugin admin n'a pas
 * besoin de embeds / colonnes / cover. Cf. arbitrage utilisateur
 * « BlockEditor restreint » du 2026-05-12.
 *
 * Enregistrement des blocs core : lazy, une seule fois (flag module-level).
 * On évite `registerCoreBlocks()` (qui pulle 60+ blocs) en n'enregistrant
 * que ceux de la whitelist — bundle équivalent (tous sont externalisés
 * vers `wp.blockLibrary.*` côté @wordpress/scripts) mais l'inserter ne
 * voit pas de blocs inutiles dans son store interne.
 *
 * Save : explicite via bouton « Enregistrer ». Pas d'autosave en V1.0 —
 * complexifierait la gestion de dirty-state et le contrat REST. L'admin
 * tape, relit, sauve. La sortie serveur (sanitization) re-synchronise
 * l'éditeur si jamais elle diffère du payload envoyé.
 *
 * Confirm de sortie : `useBeforeunload` est déjà utilisé par Normalize.
 * Ici on ne le branche pas — la valeur d'une note non sauvegardée est
 * faible (pas d'opération destructive en cours) et un beforeunload sur
 * une note 2-lignes serait agressif. Choix volontaire.
 */

import { __ } from '@wordpress/i18n';
import {
	useState,
	useMemo,
	useEffect,
	useRef,
	useCallback,
} from '@wordpress/element';
import { Button, Popover, SlotFillProvider } from '@wordpress/components';
import {
	BlockEditorProvider,
	BlockList,
	BlockTools,
	BlockToolbar,
	WritingFlow,
	ObserveTyping,
	Inserter,
} from '@wordpress/block-editor';
import {
	parse,
	serialize,
	registerBlockType,
	getBlockType,
} from '@wordpress/blocks';

/**
 * Liste des blocs autorisés (cf. arbitrage utilisateur).
 *
 * L'ordre n'impacte pas l'inserter — il s'aligne sur les catégories
 * natives. Garder l'ordre alphabétique pour la lisibilité du code.
 *
 * @type {string[]}
 */
const ALLOWED_BLOCKS = [
	'core/paragraph',
	'core/heading',
	'core/list',
	'core/list-item',
	'core/quote',
	'core/code',
	'core/separator',
	'core/image',
	'core/table',
];

/**
 * Flag module-level : indique si on a déjà tenté l'enregistrement des
 * blocs core. `registerBlockType` est idempotent (warne et ignore si le
 * type existe déjà) — le flag évite juste les `console.warn` au remount.
 */
let blocksRegistered = false;

/**
 * Enregistre les blocs whitelist. Idempotent.
 *
 * `@wordpress/block-library` expose chaque bloc core comme un module
 * `core/<name>/init` qui appelle `registerBlockType` quand importé. Le
 * pattern moderne (WP 6.x) est `import { registerCoreBlocks } from
 * '@wordpress/block-library'` qui enregistre tout — mais on veut un
 * sous-ensemble, donc on importe les settings de chacun et on appelle
 * `registerBlockType` manuellement avec `block.json` injecté.
 *
 * En pratique, charger toute la lib via `registerCoreBlocks()` puis
 * filtrer côté `allowedBlockTypes` est **plus simple et tout aussi
 * efficace bundle-wise** (wp-scripts externalise vers `wp.blockLibrary`
 * de toute façon). On garde ce chemin par simplicité.
 */
async function ensureCoreBlocks() {
	if ( blocksRegistered ) {
		return;
	}
	// Si l'utilisateur recharge la SPA après modification du whitelist en
	// dev, ce check évite les warns mais ne ré-enregistre rien — c'est OK.
	blocksRegistered = true;

	// On ne réveille pas les blocs déjà enregistrés (cas où une autre
	// vue de la SPA, futur, les aurait déjà chargés).
	if ( getBlockType( 'core/paragraph' ) ) {
		return;
	}

	// Import dynamique pour ne pas peser sur le bundle initial — webpack
	// produira un chunk séparé. La taille reste minimale côté @wordpress/scripts
	// car block-library est marqué externe (résolu à `window.wp.blockLibrary`).
	const blockLibrary = await import( '@wordpress/block-library' );
	if ( typeof blockLibrary.registerCoreBlocks === 'function' ) {
		blockLibrary.registerCoreBlocks();
		return;
	}
	// Filet : si `registerCoreBlocks` est absent (version trop ancienne
	// ou export modifié), on log et on laisse le useEffect retomber sur
	// `registerBlockType` manuel ci-dessous — cas rare, fallback gracieux.
	ALLOWED_BLOCKS.forEach( ( name ) => {
		const moduleKey = name.replace( /^core\//, '' );
		const settings = blockLibrary[ moduleKey ];
		if ( settings && ! getBlockType( name ) ) {
			try {
				registerBlockType( name, settings );
			} catch ( _err ) {
				// Bloc déjà enregistré ou settings invalides — ignore.
			}
		}
	} );
}

/**
 * Settings passées au BlockEditorProvider. Mémoïsées car référencées par
 * référence dans le provider — un nouvel objet à chaque render forcerait
 * un re-rendu coûteux de tout l'éditeur.
 *
 * @return {Object} Settings BlockEditorProvider.
 */
function buildEditorSettings() {
	return {
		// Whitelist d'insertion (cf. arbitrage utilisateur).
		allowedBlockTypes: ALLOWED_BLOCKS,
		// Pas de template imposé : la note démarre vide ou reprend ce
		// qui a été sauvé. L'utilisateur insère ce qu'il veut.
		template: null,
		templateLock: false,
		// Toolbar fixe au-dessus du contenu (mode document) au lieu du
		// popover flottant qui pose des problèmes de positionnement quand
		// l'éditeur est embarqué hors du contexte post-editor (toolbar
		// stackait verticalement faute d'espace horizontal cohérent).
		hasFixedToolbar: true,
		// Désactive la liste des modèles natifs WP qui n'a aucun sens ici.
		__experimentalBlockPatterns: [],
		__experimentalBlockPatternCategories: [],
		// Médias : l'upload nécessite que `wp_enqueue_media()` ait tourné
		// côté serveur (cf. Admin/Assets.php), ce qui est le cas.
		mediaUpload: window.wp?.media ? undefined : null,
	};
}

/**
 * @param {Object}                         props
 * @param {string}                         props.initialContent Block grammar initial (provenant du fetch REST).
 * @param {boolean}                        props.isSaving       Indicateur de sauvegarde en cours.
 * @param {(g: string) => Promise<string>} props.onSave         Persiste un payload, résout sur la version normalisée.
 * @param {() => Promise<void>}            props.onClear        Vide la note côté serveur.
 * @return {JSX.Element} Éditeur Gutenberg + barre d'actions.
 */
export default function NotesEditor( {
	initialContent,
	isSaving,
	onSave,
	onClear,
} ) {
	const [ ready, setReady ] = useState( false );
	const [ blocks, setBlocks ] = useState( () =>
		parse( initialContent || '' )
	);
	const lastSavedRef = useRef( initialContent || '' );
	const isDirty = useMemo(
		() => serialize( blocks ) !== lastSavedRef.current,
		[ blocks ]
	);

	// Enregistre les blocs core au premier mount.
	useEffect( () => {
		let cancelled = false;
		ensureCoreBlocks().then( () => {
			if ( ! cancelled ) {
				setReady( true );
			}
		} );
		return () => {
			cancelled = true;
		};
	}, [] );

	// Settings stables — `useMemo` à dépendances vides : la whitelist ne
	// change jamais, mediaUpload est résolu au premier appel.
	const settings = useMemo( () => buildEditorSettings(), [] );

	const handleSave = useCallback( async () => {
		const grammar = serialize( blocks );
		try {
			const persisted = await onSave( grammar );
			// Resync sur le contenu serveur (sanitization éventuelle).
			lastSavedRef.current = persisted;
			if ( persisted !== grammar ) {
				// Le serveur a modifié le contenu — re-parse pour refléter
				// dans l'éditeur. Cas rare (kses a strippé quelque chose).
				setBlocks( parse( persisted ) );
			}
		} catch ( _err ) {
			// L'erreur est exposée par le hook via `error` — pas de
			// retraitement ici, la Notice s'en charge.
		}
	}, [ blocks, onSave ] );

	const handleClear = useCallback( async () => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__(
				'Vider complètement la note ? Cette action est immédiate côté serveur.',
				'100son-html-normalizer'
			)
		);
		if ( ! confirmed ) {
			return;
		}
		try {
			await onClear();
			lastSavedRef.current = '';
			setBlocks( [] );
		} catch ( _err ) {
			// Idem : erreur déjà routée vers <Notice>.
		}
	}, [ onClear ] );

	if ( ! ready ) {
		return (
			<div className="htmln-notes__editor htmln-notes__editor--booting">
				<p>
					{ __(
						'Chargement de l’éditeur…',
						'100son-html-normalizer'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="htmln-notes__editor">
			<SlotFillProvider>
				<BlockEditorProvider
					value={ blocks }
					onInput={ setBlocks }
					onChange={ setBlocks }
					settings={ settings }
				>
					<BlockTools>
						<div className="htmln-notes__editor-bar">
							{ /* Inserter principal persistant. `rootClientId=""`
							 *   = insertion à la racine du document. Le bouton
							 *   est rendu via `renderToggle` pour le styler en
							 *   bouton WP standard (`@wordpress/components`)
							 *   plutôt qu'avec le rendu par défaut. */ }
							<Inserter
								rootClientId=""
								renderToggle={ ( {
									onToggle,
									disabled,
									isOpen,
								} ) => (
									<Button
										onClick={ onToggle }
										disabled={ disabled }
										variant="secondary"
										aria-expanded={ isOpen }
										className="htmln-notes__inserter-toggle"
									>
										{ __(
											'+ Ajouter un bloc',
											'100son-html-normalizer'
										) }
									</Button>
								) }
							/>
							{ /* Toolbar fixe (cf. settings.hasFixedToolbar) :
							 *   `hideDragHandle` retire la poignée de drag, qui
							 *   n'a pas de sens dans un canevas linéaire à 1 col. */ }
							<BlockToolbar hideDragHandle />
						</div>
						<div className="htmln-notes__canvas editor-styles-wrapper">
							<WritingFlow>
								<ObserveTyping>
									<BlockList />
								</ObserveTyping>
							</WritingFlow>
						</div>
					</BlockTools>
					<Popover.Slot />
				</BlockEditorProvider>
			</SlotFillProvider>

			<div className="htmln-notes__actions">
				<Button
					variant="primary"
					onClick={ handleSave }
					disabled={ isSaving || ! isDirty }
					isBusy={ isSaving && isDirty }
				>
					{ __( 'Enregistrer', '100son-html-normalizer' ) }
				</Button>{ ' ' }
				<Button
					variant="secondary"
					isDestructive
					onClick={ handleClear }
					disabled={ isSaving }
				>
					{ __( 'Vider la note', '100son-html-normalizer' ) }
				</Button>
				{ isDirty && (
					<span className="htmln-notes__dirty-hint description">
						{ __(
							'Modifications non enregistrées.',
							'100son-html-normalizer'
						) }
					</span>
				) }
			</div>
		</div>
	);
}
