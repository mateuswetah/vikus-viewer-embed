/**
 * Edit collection: name, tabbed settings, status rail.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Spinner,
	Notice,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { external } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { CollectionSettingsForm } from '../components/CollectionSettingsForm';
import { StatusRail } from '../components/StatusRail';
import { AdminPage } from '../components/AdminPage';
import { Breadcrumbs } from '../components/Breadcrumbs';

export function EditScreen( {
	collectionId,
	bootstrap,
	defaults,
	loadBootstrap,
	onBack,
} ) {
	// Load without a query object so the record is stored under the "default"
	// context. editEntityRecord / getRawEntityRecord only read that slot;
	// fetching with `{ context: 'edit' }` left it empty and made the first
	// save throw: can't access property "title" of undefined.
	// Entity baseURLParams already request REST context=edit.
	const { record, hasResolved } = useSelect(
		( select ) => {
			const core = select( coreStore );
			return {
				record: core.getEntityRecord(
					'postType',
					'vikus_collection',
					collectionId
				),
				hasResolved: core.hasFinishedResolution( 'getEntityRecord', [
					'postType',
					'vikus_collection',
					collectionId,
				] ),
			};
		},
		[ collectionId ]
	);

	const { saveEntityRecord } = useDispatch( coreStore );

	const [ title, setTitle ] = useState( '' );
	const [ settings, setSettings ] = useState( null );
	const [ build, setBuild ] = useState( {} );
	const [ ptData, setPtData ] = useState( bootstrap );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ dirtyLocal, setDirtyLocal ] = useState( false );

	// Reset local form when switching collections.
	useEffect( () => {
		setTitle( '' );
		setSettings( null );
		setBuild( {} );
		setDirtyLocal( false );
		setNotice( null );
	}, [ collectionId ] );

	// Hydrate once per load; do not clobber in-progress edits when the
	// entity record reference updates after save.
	useEffect( () => {
		if ( ! record || settings !== null ) {
			return;
		}
		setTitle( record.title?.raw || '' );
		setSettings( {
			...defaults,
			...( record.vikus_settings || {} ),
		} );
		setBuild( record.vikus_build_status || {} );
		setDirtyLocal( false );
	}, [ record, defaults, settings ] );

	const sourcePt = settings?.source_post_type || 'post';

	const sourcePtLabel = useMemo( () => {
		const match = ( bootstrap?.postTypes || [] ).find(
			( pt ) => pt.name === sourcePt
		);
		return match?.label || sourcePt;
	}, [ bootstrap, sourcePt ] );

	useEffect( () => {
		if ( ! sourcePt ) {
			return;
		}
		let cancelled = false;
		loadBootstrap( sourcePt ).then( ( data ) => {
			if ( ! cancelled ) {
				setPtData( data );
			}
		} );
		return () => {
			cancelled = true;
		};
	}, [ sourcePt, loadBootstrap ] );

	const updateSettings = useCallback( ( patch ) => {
		setSettings( ( prev ) => ( { ...prev, ...patch } ) );
		setDirtyLocal( true );
	}, [] );

	async function save() {
		setSaving( true );
		setNotice( null );
		try {
			const saved = await saveEntityRecord(
				'postType',
				'vikus_collection',
				{
					id: collectionId,
					title,
					vikus_settings: settings,
				},
				{ throwOnError: true }
			);
			if ( saved ) {
				setTitle( saved.title?.raw || title );
				if ( saved.vikus_settings ) {
					setSettings( {
						...defaults,
						...saved.vikus_settings,
					} );
				}
				if ( saved.vikus_build_status ) {
					setBuild( saved.vikus_build_status );
				}
			}
			setDirtyLocal( false );
			setNotice( {
				status: 'success',
				message: __( 'Collection saved.', 'vikus-viewer-embed' ),
			} );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err?.message || __( 'Could not save collection.', 'vikus-viewer-embed' ),
			} );
		} finally {
			setSaving( false );
		}
	}

	const refreshBuild = useCallback( async () => {
		const fresh = await apiFetch( {
			path: `/wp/v2/vikus_collection/${ collectionId }?context=edit`,
		} );
		return fresh?.vikus_build_status;
	}, [ collectionId ] );

	if ( ! hasResolved || ! settings ) {
		return <Spinner />;
	}

	if ( ! record ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Collection not found.', 'vikus-viewer-embed' ) }
			</Notice>
		);
	}

	const viewerUrl =
		build.status === 'complete' && build.viewer_url
			? build.viewer_url
			: null;

	return (
		<AdminPage
			ariaLabel={ __( 'Edit collection', 'vikus-viewer-embed' ) }
			hasPadding
			breadcrumbs={
				<Breadcrumbs
					items={ [
						{
							label: __( 'Vikus Collections', 'vikus-viewer-embed' ),
							onClick: onBack,
						},
						{ label: __( 'Edit collection', 'vikus-viewer-embed' ) },
					] }
				/>
			}
			actions={
				<HStack spacing={ 2 } justify="flex-end">
					{ viewerUrl ? (
						<Button
							variant="secondary"
							icon={ external }
							iconPosition="right"
							href={ viewerUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Open viewer', 'vikus-viewer-embed' ) }
						</Button>
					) : null }
					<Button
						variant="primary"
						onClick={ save }
						disabled={ saving || ! dirtyLocal }
						isBusy={ saving }
					>
						{ __( 'Save', 'vikus-viewer-embed' ) }
					</Button>
				</HStack>
			}
		>
			<div className="vikus-admin-app__layout">
				<VStack spacing={ 6 }>
					{ notice ? (
						<Notice
							status={ notice.status }
							onRemove={ () => setNotice( null ) }
						>
							{ notice.message }
						</Notice>
					) : null }

					<CollectionSettingsForm
						title={ title }
						settings={ settings }
						ptData={ ptData }
						sourcePostType={ {
							name: sourcePt,
							label: sourcePtLabel,
						} }
						onTitleChange={ ( v ) => {
							setTitle( v );
							setDirtyLocal( true );
						} }
						onSettingsChange={ updateSettings }
					/>
				</VStack>

				<aside className="vikus-admin-app__status">
					<StatusRail
						collectionId={ collectionId }
						build={ build }
						hasUnsavedChanges={ dirtyLocal }
						onRefresh={ refreshBuild }
						onBuildChange={ setBuild }
					/>
				</aside>
			</div>
		</AdminPage>
	);
}
