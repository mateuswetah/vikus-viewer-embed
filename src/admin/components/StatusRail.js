/**
 * Build status side panel.
 */
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	ExternalLink,
	Notice,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { Badge, Icon } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';
import { blockDefault, page, shortcode } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { getBuildStatusBadge } from '../utils/buildStatusBadge';

/**
 * @param {Object}   props
 * @param {number}   props.collectionId
 * @param {Object}   props.build
 * @param {boolean}  props.hasUnsavedChanges
 * @param {Function} props.onRefresh
 * @param {Function} [props.onBuildChange]
 */
export function StatusRail( {
	collectionId,
	build: initialBuild,
	hasUnsavedChanges,
	onRefresh,
	onBuildChange,
} ) {
	const [ build, setBuild ] = useState( initialBuild || {} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		setBuild( initialBuild || {} );
	}, [ initialBuild ] );

	const status = build.status || 'idle';
	const active = status === 'queued' || status === 'running';
	const mayBuild = build.can_build !== false;
	const canBuild = mayBuild && ! hasUnsavedChanges && ! active;
	const isComplete = status === 'complete';
	const statusBadge = getBuildStatusBadge( status );

	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		const timer = setInterval( async () => {
			try {
				const fresh = await onRefresh();
				if ( fresh ) {
					setBuild( fresh );
					onBuildChange?.( fresh );
				}
			} catch ( e ) {
				// Ignore polling errors.
			}
		}, 2500 );
		return () => clearInterval( timer );
	}, [ active, onRefresh, onBuildChange ] );

	async function queue( force ) {
		setBusy( true );
		setError( '' );
		try {
			const fresh = await apiFetch( {
				path: `/vikus-viewer-embed/v1/collections/${ collectionId }/build`,
				method: 'POST',
				data: { force },
			} );
			setBuild( fresh );
			onBuildChange?.( fresh );
		} catch ( err ) {
			setError( err?.message || __( 'Build request failed.', 'vikus-viewer-embed' ) );
		} finally {
			setBusy( false );
		}
	}

	async function cancel() {
		setBusy( true );
		setError( '' );
		try {
			const fresh = await apiFetch( {
				path: `/vikus-viewer-embed/v1/collections/${ collectionId }/build`,
				method: 'DELETE',
			} );
			setBuild( fresh );
			onBuildChange?.( fresh );
		} catch ( err ) {
			setError( err?.message || __( 'Cancel failed.', 'vikus-viewer-embed' ) );
		} finally {
			setBusy( false );
		}
	}

	return (
		<VStack spacing={ 4 } className="vikus-admin-app__build-status">
			<HStack
				alignment="center"
				justify="space-between"
				spacing={ 2 }
				wrap
				className="vikus-admin-app__build-status-heading"
			>
				<h2 className="vikus-admin-app__panel-title">
					{ __( 'Build status', 'vikus-viewer-embed' ) }
				</h2>
				<HStack
					alignment="center"
					justify="flex-end"
					spacing={ 2 }
					expanded={ false }
					className="vikus-admin-app__build-status-row"
				>
					<Badge intent={ statusBadge.intent }>
						{ statusBadge.label }
					</Badge>
					{ active ? (
						<span className="vikus-admin-app__build-status-progress">
							{ `( ${ build.completed || 0 }/${
								build.total || 0
							} )` }
						</span>
					) : null }
				</HStack>
			</HStack>

			{ hasUnsavedChanges ? (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'Save changes before building.', 'vikus-viewer-embed' ) }
				</Notice>
			) : null }

			{ build.dirty && ! hasUnsavedChanges ? (
				<Notice status="info" isDismissible={ false }>
					{ __( 'Settings changed since the last build.', 'vikus-viewer-embed' ) }
				</Notice>
			) : null }

			{ ! mayBuild ? (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'You need permission to upload media to queue a build.',
						'vikus-viewer-embed'
					) }
				</Notice>
			) : null }

			{ error ? (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) : null }

			{ build.message ? (
				<p className="vikus-admin-app__build-status-message">
					{ build.message }
				</p>
			) : null }
			{ build.last_error ? (
				<p className="vikus-admin-app__build-status-error">
					{ sprintf(
						/* translators: %s: error message */
						__( 'Last error: %s', 'vikus-viewer-embed' ),
						build.last_error
					) }
				</p>
			) : null }

			{ mayBuild ? (
				<VStack spacing={ 2 }>
					<HStack wrap>
						<Button
							variant="primary"
							disabled={ ! canBuild || busy }
							isBusy={ busy && ! active }
							onClick={ () => queue( false ) }
						>
							{ __( 'Queue build', 'vikus-viewer-embed' ) }
						</Button>
						<Button
							variant="secondary"
							disabled={ ! canBuild || busy }
							onClick={ () => queue( true ) }
						>
							{ __( 'Force rebuild', 'vikus-viewer-embed' ) }
						</Button>
					</HStack>

					{ active ? (
						<Button
							variant="tertiary"
							isDestructive
							disabled={ busy }
							onClick={ cancel }
						>
							{ __( 'Cancel build', 'vikus-viewer-embed' ) }
						</Button>
					) : null }
				</VStack>
			) : null }

			{ isComplete ? (
				<div className="vikus-admin-app__publish">
					<h3 className="vikus-admin-app__panel-title">
						{ __( 'Use this viewer', 'vikus-viewer-embed' ) }
					</h3>
					<p className="vikus-admin-app__publish-intro">
						{ __(
							'The cache is ready. Open the dedicated page, embed the shortcode, or insert the Vikus Viewer Embed block in the editor.',
							'vikus-viewer-embed'
						) }
					</p>
					<ul className="vikus-admin-app__publish-list">
						{ build.viewer_url ? (
							<li>
								<strong className="vikus-admin-app__publish-label">
									<Icon icon={ page } size={ 16 } />
									{ __( 'Page link', 'vikus-viewer-embed' ) }
								</strong>
								<ExternalLink href={ build.viewer_url }>
									{ __( 'Open viewer', 'vikus-viewer-embed' ) }
								</ExternalLink>
							</li>
						) : null }
						{ build.shortcode ? (
							<li>
								<strong className="vikus-admin-app__publish-label">
									<Icon icon={ shortcode } size={ 16 } />
									{ __( 'Shortcode', 'vikus-viewer-embed' ) }
								</strong>
								<code className="vikus-admin-app__shortcode">
									{ build.shortcode }
								</code>
							</li>
						) : null }
						<li>
							<strong className="vikus-admin-app__publish-label">
								<Icon icon={ blockDefault } size={ 16 } />
								{ __( 'Block', 'vikus-viewer-embed' ) }
							</strong>
							<span>
								{ __(
									'Insert the “Vikus Viewer Embed” block and select this collection.',
									'vikus-viewer-embed'
								) }
							</span>
						</li>
					</ul>
				</div>
			) : null }
		</VStack>
	);
}
