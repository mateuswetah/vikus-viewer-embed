/**
 * Root router for the Vikus admin app.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { ListScreen } from './screens/List';
import { EditScreen } from './screens/Edit';

function readRoute() {
	const params = new URLSearchParams( window.location.search );
	let screen = params.get( 'screen' ) || 'list';
	const id = parseInt( params.get( 'id' ) || '0', 10 );
	// Create is a modal on the list, not a separate page.
	if ( screen === 'create' ) {
		screen = 'list';
	}
	return {
		screen,
		id: Number.isFinite( id ) ? id : 0,
		openCreate: params.get( 'screen' ) === 'create',
	};
}

function pushRoute( screen, id = 0 ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'page', 'vikus-viewer-embed' );
	if ( ! screen || screen === 'list' ) {
		url.searchParams.delete( 'screen' );
		url.searchParams.delete( 'id' );
	} else {
		url.searchParams.set( 'screen', screen );
		if ( id ) {
			url.searchParams.set( 'id', String( id ) );
		} else {
			url.searchParams.delete( 'id' );
		}
	}
	window.history.pushState( {}, '', url.toString() );
}

export function App( { config } ) {
	const initial = useMemo( () => {
		if ( config?.screen === 'edit' && config.collectionId ) {
			return {
				screen: 'edit',
				id: config.collectionId,
				openCreate: false,
			};
		}
		if ( config?.screen === 'create' ) {
			return { screen: 'list', id: 0, openCreate: true };
		}
		return readRoute();
	}, [ config ] );

	const [ route, setRoute ] = useState( initial );
	const [ createOpen, setCreateOpen ] = useState( !! initial.openCreate );
	const [ bootstrap, setBootstrap ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		const onPop = () => {
			const next = readRoute();
			setRoute( next );
			setCreateOpen( !! next.openCreate );
		};
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [] );

	const navigate = useCallback( ( screen, id = 0 ) => {
		pushRoute( screen, id );
		setRoute( { screen, id, openCreate: false } );
		if ( screen !== 'list' ) {
			setCreateOpen( false );
		}
	}, [] );

	const setCreateOpenAndUrl = useCallback( ( open ) => {
		setCreateOpen( open );
		const url = new URL( window.location.href );
		url.searchParams.set( 'page', 'vikus-viewer-embed' );
		url.searchParams.delete( 'id' );
		if ( open ) {
			url.searchParams.set( 'screen', 'create' );
		} else {
			url.searchParams.delete( 'screen' );
		}
		window.history.replaceState( {}, '', url.toString() );
	}, [] );

	const loadBootstrap = useCallback( async ( postType = '' ) => {
		const path =
			'/vikus-viewer-embed/v1/admin-bootstrap' +
			( postType ? `?post_type=${ encodeURIComponent( postType ) }` : '' );
		const data = await apiFetch( { path } );
		setBootstrap( data );
		return data;
	}, [] );

	useEffect( () => {
		loadBootstrap().catch( ( err ) => {
			setError( err?.message || __( 'Failed to load admin data.', 'vikus-viewer-embed' ) );
		} );
	}, [ loadBootstrap ] );

	const defaults = useMemo(
		() => bootstrap?.defaults || config?.defaults || {},
		[ bootstrap, config ]
	);

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( ! bootstrap ) {
		return <Spinner />;
	}

	const showList =
		route.screen === 'list' || ( route.screen === 'edit' && ! route.id );

	return (
		<>
			{ route.screen === 'edit' && route.id > 0 && (
				<EditScreen
					collectionId={ route.id }
					bootstrap={ bootstrap }
					defaults={ defaults }
					loadBootstrap={ loadBootstrap }
					onBack={ () => navigate( 'list' ) }
				/>
			) }
			{ showList && (
				<ListScreen
					bootstrap={ bootstrap }
					defaults={ defaults }
					loadBootstrap={ loadBootstrap }
					openCreate={ createOpen }
					onCreateOpenChange={ setCreateOpenAndUrl }
					onEdit={ ( id ) => navigate( 'edit', id ) }
				/>
			) }
		</>
	);
}
