/**
 * Create-collection wizard inside a Modal.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	SelectControl,
	TextControl,
	ToggleControl,
	Spinner,
	Notice,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalNavigatorProvider as NavigatorProvider,
	__experimentalNavigatorScreen as NavigatorScreen,
	__experimentalNavigatorButton as NavigatorButton,
	__experimentalNavigatorBackButton as NavigatorBackButton,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { dispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { suggestSettingsForPostType } from '../utils/suggestions';

export function CreateCollectionModal( {
	bootstrap,
	defaults,
	loadBootstrap,
	onClose,
	onCreated,
} ) {
	const [ title, setTitle ] = useState( '' );
	const [ postType, setPostType ] = useState(
		bootstrap.postTypes?.[ 0 ]?.name || 'post'
	);
	const [ requireThumb, setRequireThumb ] = useState(
		!! defaults.require_thumbnail
	);
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ ptBootstrap, setPtBootstrap ] = useState( bootstrap );

	useEffect( () => {
		let cancelled = false;
		loadBootstrap( postType )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setPtBootstrap( data );
				}
			} )
			.catch( () => {} );
		return () => {
			cancelled = true;
		};
	}, [ postType, loadBootstrap ] );

	const postTypeOptions = useMemo(
		() =>
			( bootstrap.postTypes || [] ).map( ( pt ) => ( {
				label: `${ pt.label } (${ pt.name })`,
				value: pt.name,
			} ) ),
		[ bootstrap.postTypes ]
	);

	const selectedPt = ( bootstrap.postTypes || [] ).find(
		( p ) => p.name === postType
	);

	const previewSettings = useMemo(
		() =>
			suggestSettingsForPostType( postType, ptBootstrap, {
				...defaults,
				require_thumbnail: requireThumb,
				project_name: title,
				info_markdown:
					defaults.info_markdown || selectedPt?.description || '',
			} ),
		[ postType, ptBootstrap, defaults, requireThumb, title, selectedPt ]
	);

	async function createCollection() {
		setSaving( true );
		setError( '' );
		try {
			const record = await dispatch( coreStore ).saveEntityRecord(
				'postType',
				'vikus_collection',
				{
					title: title || __( 'Vikus Collection', 'vikus-viewer-embed' ),
					status: 'publish',
					vikus_settings: previewSettings,
				}
			);
			if ( record?.id ) {
				onCreated( record.id );
			} else {
				setError( __( 'Could not create collection.', 'vikus-viewer-embed' ) );
			}
		} catch ( err ) {
			setError(
				err?.message || __( 'Could not create collection.', 'vikus-viewer-embed' )
			);
		} finally {
			setSaving( false );
		}
	}

	return (
		<Modal
			title={ __( 'New collection', 'vikus-viewer-embed' ) }
			onRequestClose={ onClose }
			size="medium"
			focusOnMount="firstContentElement"
		>
			<VStack spacing={ 4 }>
				{ error ? (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) : null }

				<NavigatorProvider initialPath="/">
					<NavigatorScreen path="/">
						<VStack spacing={ 4 }>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Collection name', 'vikus-viewer-embed' ) }
								value={ title }
								onChange={ setTitle }
								help={ __(
									'Used as the WordPress title and default project name.',
									'vikus-viewer-embed'
								) }
							/>
							<HStack justify="flex-end">
								<Button variant="tertiary" onClick={ onClose }>
									{ __( 'Cancel', 'vikus-viewer-embed' ) }
								</Button>
								<NavigatorButton variant="primary" path="/source">
									{ __( 'Continue', 'vikus-viewer-embed' ) }
								</NavigatorButton>
							</HStack>
						</VStack>
					</NavigatorScreen>

					<NavigatorScreen path="/source">
						<VStack spacing={ 4 }>
							<HStack alignment="top" spacing={ 4 } wrap>
								<div style={ { flex: '1 1 16rem', minWidth: '14rem' } }>
									<SelectControl
										__nextHasNoMarginBottom
										label={ __(
											'Source post type',
											'vikus-viewer-embed'
										) }
										value={ postType }
										options={ postTypeOptions }
										onChange={ setPostType }
										help={ __(
											'Almost all mapping options are derived from this choice. You will rarely change it after creating the collection.',
											'vikus-viewer-embed'
										) }
									/>
								</div>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Only include posts with a featured image',
										'vikus-viewer-embed'
									) }
									checked={ requireThumb }
									onChange={ setRequireThumb }
								/>
							</HStack>
							{ selectedPt?.description ? (
								<p>{ selectedPt.description }</p>
							) : null }
							<HStack justify="flex-end">
								<NavigatorBackButton variant="tertiary">
									{ __( 'Back', 'vikus-viewer-embed' ) }
								</NavigatorBackButton>
								<NavigatorButton variant="primary" path="/review">
									{ __( 'Continue', 'vikus-viewer-embed' ) }
								</NavigatorButton>
							</HStack>
						</VStack>
					</NavigatorScreen>

					<NavigatorScreen path="/review">
						<VStack spacing={ 4 }>
							<p>
								{ __(
									'Suggested keywords and grouping column based on the selected post type. You can refine everything after creating.',
									'vikus-viewer-embed'
								) }
							</p>
							<ul>
								<li>
									{ __( 'Keywords:', 'vikus-viewer-embed' ) }{ ' ' }
									{ previewSettings.keyword_source === 'meta'
										? previewSettings.keyword_meta_key || '—'
										: ( previewSettings.keyword_taxonomies || [] ).join(
												', '
										  ) || '—' }
								</li>
								<li>
									{ __( 'Grouping column:', 'vikus-viewer-embed' ) }{ ' ' }
									{ previewSettings.year_source }
									{ previewSettings.year_taxonomy
										? ` (${ previewSettings.year_taxonomy })`
										: '' }
									{ previewSettings.year_meta_key
										? ` (${ previewSettings.year_meta_key })`
										: '' }
								</li>
							</ul>
							<HStack justify="flex-end">
								<NavigatorBackButton variant="tertiary">
									{ __( 'Back', 'vikus-viewer-embed' ) }
								</NavigatorBackButton>
								<Button
									variant="primary"
									onClick={ createCollection }
									disabled={ saving }
									isBusy={ saving }
								>
									{ saving ? (
										<Spinner />
									) : (
										__( 'Create collection', 'vikus-viewer-embed' )
									) }
								</Button>
							</HStack>
						</VStack>
					</NavigatorScreen>
				</NavigatorProvider>
			</VStack>
		</Modal>
	);
}
