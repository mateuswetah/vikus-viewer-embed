import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	Placeholder,
	Spinner,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );

function Edit( { attributes, setAttributes } ) {
	const { collectionId, height } = attributes;
	const blockProps = useBlockProps( {
		className: 'vikus-viewer-block-editor',
	} );

	const collections = useSelect( ( select ) => {
		return select( coreStore ).getEntityRecords( 'postType', 'vikus_collection', {
			per_page: 100,
			status: 'publish,draft,private',
		} );
	}, [] );

	const options = [
		{ label: __( 'Select a collection…', 'vikus-viewer-embed' ), value: 0 },
		...( collections || [] ).map( ( post ) => ( {
			label: post.title?.rendered || `#${ post.id }`,
			value: post.id,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Vikus Viewer Embed', 'vikus-viewer-embed' ) }>
					{ collections === null ? (
						<Spinner />
					) : (
						<SelectControl
							label={ __( 'Collection', 'vikus-viewer-embed' ) }
							value={ collectionId }
							options={ options }
							onChange={ ( value ) =>
								setAttributes( { collectionId: parseInt( value, 10 ) || 0 } )
							}
						/>
					) }
					<TextControl
						label={ __( 'Height', 'vikus-viewer-embed' ) }
						help={ __( 'CSS height value, e.g. 80vh or 600px', 'vikus-viewer-embed' ) }
						value={ height }
						onChange={ ( value ) => setAttributes( { height: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				{ ! collectionId ? (
					<Placeholder
						icon="images-alt2"
						label={ __( 'Vikus Viewer Embed', 'vikus-viewer-embed' ) }
						instructions={ __(
							'Select a Vikus collection in the block sidebar.',
							'vikus-viewer-embed'
						) }
					/>
				) : (
					<div
						style={ {
							border: '1px dashed #8c8f94',
							padding: '2rem',
							textAlign: 'center',
							background: '#f6f7f7',
						} }
					>
						<strong>{ __( 'Vikus Viewer Embed', 'vikus-viewer-embed' ) }</strong>
						<p>
							{ __( 'Collection ID:', 'vikus-viewer-embed' ) }{ ' ' }
							{ collectionId }
						</p>
						<p>
							{ __( 'Height:', 'vikus-viewer-embed' ) } { height }
						</p>
					</div>
				) }
			</div>
		</>
	);
}
