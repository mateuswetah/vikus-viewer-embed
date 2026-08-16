/**
 * Collections list via DataViews; create flow opens a modal.
 */
import { useMemo, useState } from '@wordpress/element';
import {
	Button,
	Spinner,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { Badge } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { useEntityRecords } from '@wordpress/core-data';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { external, pencil, update } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { CreateCollectionModal } from './Create';
import { AdminPage } from '../components/AdminPage';
import { getBuildStatusBadge } from '../utils/buildStatusBadge';

const DEFAULT_VIEW = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	sort: { field: 'title', direction: 'asc' },
	fields: [ 'title', 'source', 'build', 'dirty' ],
	layout: {},
};

export function ListScreen( {
	bootstrap,
	defaults,
	loadBootstrap,
	onEdit,
	openCreate = false,
	onCreateOpenChange,
} ) {
	const [ view, setView ] = useState( DEFAULT_VIEW );
	const [ actionError, setActionError ] = useState( '' );
	const [ localCreateOpen, setLocalCreateOpen ] = useState( false );

	const isCreateOpen =
		typeof onCreateOpenChange === 'function' ? openCreate : localCreateOpen;
	const setCreateOpen =
		typeof onCreateOpenChange === 'function'
			? onCreateOpenChange
			: setLocalCreateOpen;

	const { records, hasResolved } = useEntityRecords(
		'postType',
		'vikus_collection',
		{
			per_page: 100,
			status: 'publish,draft,private,pending',
			context: 'edit',
		}
	);

	const fields = useMemo(
		() => [
			{
				id: 'title',
				label: __( 'Title', 'vikus-viewer-embed' ),
				enableSorting: true,
				getValue: ( { item } ) =>
					item.title?.raw || item.title?.rendered || `#${ item.id }`,
				render: ( { item } ) => (
					<Button variant="link" onClick={ () => onEdit( item.id ) }>
						{ item.title?.raw || item.title?.rendered || `#${ item.id }` }
					</Button>
				),
			},
			{
				id: 'source',
				label: __( 'Source', 'vikus-viewer-embed' ),
				enableSorting: true,
				getValue: ( { item } ) =>
					item.vikus_settings?.source_post_type || '',
			},
			{
				id: 'build',
				label: __( 'Build', 'vikus-viewer-embed' ),
				enableSorting: true,
				getValue: ( { item } ) =>
					item.vikus_build_status?.status || 'idle',
				render: ( { item } ) => {
					const badge = getBuildStatusBadge(
						item.vikus_build_status?.status || 'idle'
					);
					return (
						<Badge intent={ badge.intent }>{ badge.label }</Badge>
					);
				},
			},
			{
				id: 'dirty',
				label: __( 'Settings', 'vikus-viewer-embed' ),
				enableSorting: true,
				getValue: ( { item } ) =>
					item.vikus_build_status?.dirty ? 'changed' : 'ok',
				render: ( { item } ) =>
					item.vikus_build_status?.dirty ? (
						<Badge intent="medium">
							{ __( 'Rebuild needed', 'vikus-viewer-embed' ) }
						</Badge>
					) : (
						'—'
					),
			},
		],
		[ onEdit ]
	);

	const data = records || [];
	const { data: shownData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( data, view, fields ),
		[ data, view, fields ]
	);

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'vikus-viewer-embed' ),
				icon: pencil,
				callback: ( items ) => {
					if ( items[ 0 ] ) {
						onEdit( items[ 0 ].id );
					}
				},
				isPrimary: true,
			},
			{
				id: 'rebuild',
				label: __( 'Rebuild cache', 'vikus-viewer-embed' ),
				icon: update,
				callback: async ( items ) => {
					const item = items[ 0 ];
					if ( ! item ) {
						return;
					}
					setActionError( '' );
					try {
						await apiFetch( {
							path: `/vikus-viewer-embed/v1/collections/${ item.id }/build`,
							method: 'POST',
							data: { force: false },
						} );
						window.location.reload();
					} catch ( err ) {
						setActionError(
							err?.message ||
								__( 'Could not queue rebuild.', 'vikus-viewer-embed' )
						);
					}
				},
			},
			{
				id: 'open-viewer',
				label: __( 'Open viewer', 'vikus-viewer-embed' ),
				icon: external,
				callback: ( items ) => {
					const url = items[ 0 ]?.vikus_build_status?.viewer_url;
					if ( url && items[ 0 ]?.vikus_build_status?.status === 'complete' ) {
						window.open( url, '_blank', 'noopener,noreferrer' );
					}
				},
				isEligible: ( item ) =>
					item?.vikus_build_status?.status === 'complete',
			},
		],
		[ onEdit ]
	);

	if ( ! hasResolved ) {
		return <Spinner />;
	}

	return (
		<>
			<AdminPage
				className="vikus-admin-app__list"
				title={ __( 'Vikus Collections', 'vikus-viewer-embed' ) }
				actions={
					<Button
						variant="primary"
						onClick={ () => setCreateOpen( true ) }
					>
						{ __( 'Add New', 'vikus-viewer-embed' ) }
					</Button>
				}
			>
				<div className="vikus-admin-app__list-body">
					<VStack spacing={ 4 }>
						{ actionError ? <p>{ actionError }</p> : null }
						<DataViews
							data={ shownData }
							fields={ fields }
							view={ view }
							onChangeView={ setView }
							paginationInfo={ paginationInfo }
							defaultLayouts={ { table: {} } }
							getItemId={ ( item ) => String( item.id ) }
							actions={ actions }
						/>
						{ ! data.length ? (
							<p>
								{ sprintf(
									/* translators: none */
									__(
										'No collections yet. Create one to get started.',
										'vikus-viewer-embed'
									)
								) }
							</p>
						) : null }
					</VStack>
				</div>
			</AdminPage>

			{ isCreateOpen ? (
				<CreateCollectionModal
					bootstrap={ bootstrap }
					defaults={ defaults }
					loadBootstrap={ loadBootstrap }
					onClose={ () => setCreateOpen( false ) }
					onCreated={ ( id ) => {
						setCreateOpen( false );
						onEdit( id );
					} }
				/>
			) : null }
		</>
	);
}
