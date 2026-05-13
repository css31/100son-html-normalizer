/**
 * Settings — vue racine F15 (réglages des seuils γ de régression).
 *
 * Phase 6.7 — clôture la Phase 6.
 *
 * 7 inputs entiers ≥ 0 répartis en deux groupes :
 *  - 3 seuils **pourcentages** (text/words/paragraphs loss_pct, unité %)
 *  - 4 seuils **absolus** (headings/images/links/lists loss, unité items)
 *
 * Validation client : entiers ≥ 0, ≤ 100 pour les pourcentages. Si une
 * valeur est invalide à la soumission, on bloque le POST et on affiche
 * la liste des champs fautifs. Le serveur a son propre filet (normalisation
 * silencieuse) mais on préfère que l'admin voie immédiatement ce qui ne va
 * pas plutôt qu'un fallback silent.
 *
 * Persistance via `useSettings.save`. La version retournée par le serveur
 * (après normalisation) est la nouvelle source de vérité — utile si l'admin
 * tape `99.9` sur un champ entier, le serveur cast en `99`.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { useSettings } from '../hooks/useSettings';
import { useExternalSites } from '../hooks/useExternalSites';

/**
 * Schéma des 7 seuils — clé, libellé, unité, max recommandé pour
 * validation client (`null` = pas de max).
 *
 * Ordre identique à `SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS`
 * pour faciliter la relecture entre PHP et JS.
 *
 * @type {Array<{key: string, label: string, unit: 'pct'|'absolute', max: ?number, hint: string}>}
 */
const FIELDS = [
	{
		key: 'text_loss_pct',
		label: __( 'Caractères — perte tolérée', '100son-html-normalizer' ),
		unit: 'pct',
		max: 100,
		hint: __(
			'Pourcentage de caractères que la normalisation peut supprimer sans déclencher une alerte de régression. Recommandé : 0 (aucune perte tolérée).',
			'100son-html-normalizer'
		),
	},
	{
		key: 'words_loss_pct',
		label: __( 'Mots — perte tolérée', '100son-html-normalizer' ),
		unit: 'pct',
		max: 100,
		hint: __(
			'Pourcentage de mots que la normalisation peut supprimer. Recommandé : 0.',
			'100son-html-normalizer'
		),
	},
	{
		key: 'paragraphs_loss_pct',
		label: __( 'Paragraphes — perte tolérée', '100son-html-normalizer' ),
		unit: 'pct',
		max: 100,
		hint: __(
			'Pourcentage de paragraphes que la normalisation peut supprimer (paragraphes vides éliminés par P1, etc.). Default : 5 %.',
			'100son-html-normalizer'
		),
	},
	{
		key: 'headings_loss',
		label: __( 'Titres — perte tolérée', '100son-html-normalizer' ),
		unit: 'absolute',
		max: null,
		hint: __(
			'Nombre absolu de titres qui peuvent être supprimés. Le seuil s’applique à chaque niveau h1..h6 indépendamment. Default : 0.',
			'100son-html-normalizer'
		),
	},
	{
		key: 'images_loss',
		label: __( 'Images — perte tolérée', '100son-html-normalizer' ),
		unit: 'absolute',
		max: null,
		hint: __(
			'Nombre absolu d’images que la normalisation peut supprimer. Default : 0.',
			'100son-html-normalizer'
		),
	},
	{
		key: 'links_loss',
		label: __( 'Liens — perte tolérée', '100son-html-normalizer' ),
		unit: 'absolute',
		max: null,
		hint: __(
			'Nombre absolu de liens (`<a href>`) que la normalisation peut supprimer. Default : 0.',
			'100son-html-normalizer'
		),
	},
	{
		key: 'lists_loss',
		label: __( 'Listes — perte tolérée', '100son-html-normalizer' ),
		unit: 'absolute',
		max: null,
		hint: __(
			'Nombre absolu de listes (`<ul>` / `<ol>`) que la normalisation peut supprimer. Default : 0.',
			'100son-html-normalizer'
		),
	},
];

/**
 * Convertit une chaîne d'input vers un entier ≥ 0 ou retourne null si
 * l'input est invalide (NaN, négatif, > max).
 *
 * @param {string}  raw Valeur du TextControl.
 * @param {?number} max Maximum autorisé (null = pas de max).
 * @return {?number} Entier valide ou null si invalide.
 */
function parseField( raw, max ) {
	const trimmed = String( raw ?? '' ).trim();
	if ( '' === trimmed ) {
		return null;
	}
	// Refuse les notations décimales, scientifiques, hex etc. — entier brut.
	if ( ! /^\d+$/.test( trimmed ) ) {
		return null;
	}
	const parsed = parseInt( trimmed, 10 );
	if ( ! Number.isFinite( parsed ) || parsed < 0 ) {
		return null;
	}
	if ( null !== max && parsed > max ) {
		return null;
	}
	return parsed;
}

/**
 * @return {JSX.Element} Vue Settings complète.
 */
export default function Settings() {
	const {
		thresholds,
		defaults,
		isLoading,
		isSaving,
		error,
		isDirty,
		save,
		clearStatus,
	} = useSettings();

	// Le formulaire stocke les valeurs en string pour permettre à
	// l'utilisateur de vider un champ sans perdre le focus. Les valeurs
	// initiales arrivent quand le fetch initial résout.
	const [ formValues, setFormValues ] = useState( {} );

	useEffect( () => {
		if ( thresholds && 0 === Object.keys( formValues ).length ) {
			setFormValues(
				Object.fromEntries(
					FIELDS.map( ( field ) => [
						field.key,
						String( thresholds[ field.key ] ?? 0 ),
					] )
				)
			);
		}
		// La condition `0 === Object.keys(formValues).length` évite d'écraser
		// les éventuelles modifications en cours quand `useSettings.save`
		// renvoie une version normalisée — celle-là on la veut au state via
		// `handleSave`, pas par cet effet.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ thresholds ] );

	const handleChangeField = useCallback(
		( key, raw ) => {
			setFormValues( ( prev ) => ( {
				...prev,
				[ key ]: String( raw ),
			} ) );
			if ( isDirty || error ) {
				clearStatus();
			}
		},
		[ isDirty, error, clearStatus ]
	);

	const handleRestoreDefaults = useCallback( () => {
		if ( ! defaults ) {
			return;
		}
		setFormValues(
			Object.fromEntries(
				FIELDS.map( ( field ) => [
					field.key,
					String( defaults[ field.key ] ?? 0 ),
				] )
			)
		);
		clearStatus();
	}, [ defaults, clearStatus ] );

	// Calcule les champs invalides à la volée pour la liste d'erreurs et
	// pour désactiver le bouton « Enregistrer ».
	const invalidFields = FIELDS.filter(
		( field ) => null === parseField( formValues[ field.key ], field.max )
	);

	const handleSave = useCallback(
		async ( event ) => {
			event.preventDefault();
			if ( invalidFields.length > 0 ) {
				return;
			}
			const payload = Object.fromEntries(
				FIELDS.map( ( field ) => [
					field.key,
					parseField( formValues[ field.key ], field.max ),
				] )
			);
			try {
				const normalized = await save( payload );
				// Re-synchronise le form avec ce que le serveur a écrit
				// (gestion des cas de bord du type cast 99.9 → 99 etc.).
				setFormValues(
					Object.fromEntries(
						FIELDS.map( ( field ) => [
							field.key,
							String( normalized[ field.key ] ?? 0 ),
						] )
					)
				);
			} catch ( _err ) {
				// L'erreur est déjà capturée par le hook ; on ne fait rien
				// ici, la <Notice> se charge de l'afficher.
			}
		},
		[ invalidFields, formValues, save ]
	);

	if ( isLoading && null === thresholds ) {
		return (
			<div className="htmln-settings htmln-settings--loading">
				<Spinner />{ ' ' }
				{ __( 'Chargement des réglages…', '100son-html-normalizer' ) }
			</div>
		);
	}

	if ( error && null === thresholds ) {
		return (
			<div className="htmln-settings">
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						// translators: %s = message d'erreur.
						__(
							'Impossible de charger les réglages : %s',
							'100son-html-normalizer'
						),
						error
					) }
				</Notice>
			</div>
		);
	}

	return (
		<div className="htmln-settings">
			<header className="htmln-settings__header">
				<h2>
					{ __(
						'Seuils γ de régression structurelle',
						'100son-html-normalizer'
					) }
				</h2>
				<p className="description">
					{ __(
						'La normalisation ne s’écrit jamais en base sans avoir comparé avant et après. Chaque seuil ci-dessous définit la perte maximale tolérée avant que la modale « Régression détectée » ne s’ouvre et ne demande votre décision. Plus le seuil est bas, plus la SPA est prudente.',
						'100son-html-normalizer'
					) }
				</p>
			</header>

			{ isDirty && ! isSaving && (
				<Notice status="success" onRemove={ clearStatus } isDismissible>
					{ __( 'Réglages enregistrés.', '100son-html-normalizer' ) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" onRemove={ clearStatus } isDismissible>
					{ sprintf(
						// translators: %s = message d'erreur.
						__(
							'Échec de l’enregistrement : %s',
							'100son-html-normalizer'
						),
						error
					) }
				</Notice>
			) }

			{ invalidFields.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Certains champs ne sont pas valides (entiers entre 0 et 100 pour les pourcentages). Corrigez-les avant d’enregistrer.',
						'100son-html-normalizer'
					) }
				</Notice>
			) }

			<form onSubmit={ handleSave } className="htmln-settings__form">
				<fieldset className="htmln-settings__group">
					<legend>
						{ __(
							'Seuils en pourcentage',
							'100son-html-normalizer'
						) }
					</legend>
					{ FIELDS.filter( ( field ) => 'pct' === field.unit ).map(
						( field ) => (
							<FieldRow
								key={ field.key }
								field={ field }
								value={ formValues[ field.key ] ?? '' }
								defaultValue={ defaults?.[ field.key ] ?? 0 }
								onChange={ ( raw ) =>
									handleChangeField( field.key, raw )
								}
								disabled={ isSaving }
							/>
						)
					) }
				</fieldset>

				<fieldset className="htmln-settings__group">
					<legend>
						{ __(
							'Seuils en nombre absolu',
							'100son-html-normalizer'
						) }
					</legend>
					{ FIELDS.filter(
						( field ) => 'absolute' === field.unit
					).map( ( field ) => (
						<FieldRow
							key={ field.key }
							field={ field }
							value={ formValues[ field.key ] ?? '' }
							defaultValue={ defaults?.[ field.key ] ?? 0 }
							onChange={ ( raw ) =>
								handleChangeField( field.key, raw )
							}
							disabled={ isSaving }
						/>
					) ) }
				</fieldset>

				<div className="htmln-settings__actions">
					<Button
						type="submit"
						variant="primary"
						disabled={ isSaving || invalidFields.length > 0 }
						isBusy={ isSaving }
					>
						{ __( 'Enregistrer', '100son-html-normalizer' ) }
					</Button>{ ' ' }
					<Button
						type="button"
						variant="secondary"
						onClick={ handleRestoreDefaults }
						disabled={ isSaving || ! defaults }
					>
						{ __(
							'Restaurer les valeurs par défaut',
							'100son-html-normalizer'
						) }
					</Button>
				</div>
			</form>

			<ExternalSitesSection />
		</div>
	);
}

/**
 * Section « Domaines externes » — 2 URLs (Old / Prod) consommées par les
 * boutons d'ouverture rapide dans l'onglet Normaliser. Indépendante du
 * formulaire des seuils γ — chacun a son cycle save/dirty.
 *
 * @return {JSX.Element} Fieldset URLs + boutons.
 */
function ExternalSitesSection() {
	const {
		sites,
		defaults,
		isLoading,
		isSaving,
		error,
		isDirty,
		save,
		clearStatus,
	} = useExternalSites();

	// String state pour autoriser un input vide sans perdre le focus, même
	// pattern que la section seuils γ ci-dessus.
	const [ formValues, setFormValues ] = useState( {} );

	useEffect( () => {
		if ( sites && 0 === Object.keys( formValues ).length ) {
			setFormValues( {
				old_url: String( sites.old_url ?? '' ),
				prod_url: String( sites.prod_url ?? '' ),
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ sites ] );

	const handleChange = useCallback(
		( key, raw ) => {
			setFormValues( ( prev ) => ( {
				...prev,
				[ key ]: String( raw ),
			} ) );
			if ( isDirty || error ) {
				clearStatus();
			}
		},
		[ isDirty, error, clearStatus ]
	);

	const handleRestore = useCallback( () => {
		if ( ! defaults ) {
			return;
		}
		setFormValues( {
			old_url: String( defaults.old_url ?? '' ),
			prod_url: String( defaults.prod_url ?? '' ),
		} );
		clearStatus();
	}, [ defaults, clearStatus ] );

	const handleSave = useCallback(
		async ( event ) => {
			event.preventDefault();
			try {
				const normalized = await save( {
					old_url: ( formValues.old_url ?? '' ).trim(),
					prod_url: ( formValues.prod_url ?? '' ).trim(),
				} );
				// Resync : si l'utilisateur a tapé une URL invalide, le serveur
				// l'a remplacée par le default — on reflète la valeur réelle.
				setFormValues( {
					old_url: String( normalized.old_url ?? '' ),
					prod_url: String( normalized.prod_url ?? '' ),
				} );
			} catch ( _err ) {
				// Erreur affichée via <Notice>.
			}
		},
		[ formValues, save ]
	);

	if ( isLoading && null === sites ) {
		return (
			<section className="htmln-settings__section">
				<Spinner />{ ' ' }
				{ __(
					'Chargement des domaines externes…',
					'100son-html-normalizer'
				) }
			</section>
		);
	}

	return (
		<section className="htmln-settings__section">
			<header className="htmln-settings__header">
				<h2>{ __( 'Domaines externes', '100son-html-normalizer' ) }</h2>
				<p className="description">
					{ __(
						'URLs des sites où ouvrir un article depuis l’onglet Normaliser (boutons « Old » et « Prod »). Schéma http:// ou https:// requis ; le slash final est retiré automatiquement. Une valeur invalide est remplacée par la valeur par défaut.',
						'100son-html-normalizer'
					) }
				</p>
			</header>

			{ isDirty && ! isSaving && (
				<Notice status="success" onRemove={ clearStatus } isDismissible>
					{ __( 'Domaines enregistrés.', '100son-html-normalizer' ) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" onRemove={ clearStatus } isDismissible>
					{ sprintf(
						// translators: %s = message d'erreur.
						__(
							'Échec de l’enregistrement : %s',
							'100son-html-normalizer'
						),
						error
					) }
				</Notice>
			) }

			<form onSubmit={ handleSave } className="htmln-settings__form">
				<fieldset className="htmln-settings__group">
					<legend>
						{ __( 'URLs des sites', '100son-html-normalizer' ) }
					</legend>
					<div className="htmln-settings__field">
						<TextControl
							label={ __(
								'Ancien site (« Old »)',
								'100son-html-normalizer'
							) }
							value={ formValues.old_url ?? '' }
							onChange={ ( raw ) =>
								handleChange( 'old_url', raw )
							}
							disabled={ isSaving }
							help={ sprintf(
								// translators: %s = URL par défaut.
								__(
									'Par défaut : %s.',
									'100son-html-normalizer'
								),
								String( defaults?.old_url ?? '' )
							) }
							type="url"
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</div>
					<div className="htmln-settings__field">
						<TextControl
							label={ __(
								'Site de production (« Prod »)',
								'100son-html-normalizer'
							) }
							value={ formValues.prod_url ?? '' }
							onChange={ ( raw ) =>
								handleChange( 'prod_url', raw )
							}
							disabled={ isSaving }
							help={ sprintf(
								// translators: %s = URL par défaut.
								__(
									'Par défaut : %s.',
									'100son-html-normalizer'
								),
								String( defaults?.prod_url ?? '' )
							) }
							type="url"
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</div>
				</fieldset>

				<div className="htmln-settings__actions">
					<Button
						type="submit"
						variant="primary"
						disabled={ isSaving }
						isBusy={ isSaving }
					>
						{ __( 'Enregistrer', '100son-html-normalizer' ) }
					</Button>{ ' ' }
					<Button
						type="button"
						variant="secondary"
						onClick={ handleRestore }
						disabled={ isSaving || ! defaults }
					>
						{ __(
							'Restaurer les valeurs par défaut',
							'100son-html-normalizer'
						) }
					</Button>
				</div>
			</form>
		</section>
	);
}

/**
 * Une ligne de champ (label + input + hint + default).
 *
 * @param {Object}                props
 * @param {Object}                props.field        Schéma du champ.
 * @param {string}                props.value        Valeur courante (string brute).
 * @param {number}                props.defaultValue Default exposé par le serveur (pour le helpText).
 * @param {(raw: string) => void} props.onChange     Callback.
 * @param {boolean}               props.disabled     Désactive le champ.
 * @return {JSX.Element} Bloc TextControl + helpText.
 */
function FieldRow( { field, value, defaultValue, onChange, disabled } ) {
	const isValid =
		null !== parseField( value, field.max ) || '' === value.trim();
	const unitLabel =
		'pct' === field.unit
			? __( '%', '100son-html-normalizer' )
			: __( 'éléments', '100son-html-normalizer' );
	const helpText = sprintf(
		// translators: 1 = description du seuil, 2 = unité, 3 = valeur par défaut.
		__( '%1$s Unité : %2$s. Default : %3$d.', '100son-html-normalizer' ),
		field.hint,
		unitLabel,
		defaultValue
	);
	return (
		<div
			className={ `htmln-settings__field${
				isValid ? '' : ' htmln-settings__field--invalid'
			}` }
		>
			<TextControl
				label={ field.label }
				value={ value }
				onChange={ onChange }
				disabled={ disabled }
				help={ helpText }
				type="number"
				min={ 0 }
				max={ field.max ?? undefined }
				step={ 1 }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</div>
	);
}
