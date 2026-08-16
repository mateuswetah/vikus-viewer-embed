/**
 * Detail sidebar field row builder.
 */
import {
	Button,
	SelectControl,
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { RowRemoveButton } from './RowRemoveButton';
import { WpSourceAutocomplete } from './WpSourceAutocomplete';
import {
	buildWpSourceGroups,
	getWpSourceLabel,
} from '../utils/wpSourceOptions';

const TYPE_OPTIONS = [
	{ label: __( 'Text', 'vikus-viewer-embed' ), value: 'text' },
	{ label: __( 'Link', 'vikus-viewer-embed' ), value: 'link' },
	{ label: __( 'Markdown / HTML', 'vikus-viewer-embed' ), value: 'markdown' },
	{ label: __( 'Keywords chips', 'vikus-viewer-embed' ), value: 'keywords' },
];

const DISPLAY_OPTIONS = [
	{ label: __( 'Column', 'vikus-viewer-embed' ), value: 'column' },
	{ label: __( 'Wide', 'vikus-viewer-embed' ), value: 'wide' },
];

const DETAIL_INCLUDE = [
	'title',
	'excerpt',
	'content',
	'permalink',
	'post_date',
	'year',
	'keywords',
	'taxonomy',
	'meta',
];

const DETAIL_CONTENT_LABELS = {
	post_date: __( 'Post date', 'vikus-viewer-embed' ),
};

function defaultTypeForSource( source ) {
	if ( source === 'permalink' ) {
		return 'link';
	}
	if ( source === 'keywords' ) {
		return 'keywords';
	}
	if ( source.startsWith( 'meta:' ) ) {
		return 'markdown';
	}
	return 'text';
}

function defaultDisplayForType( type ) {
	return type === 'markdown' || type === 'link' ? 'wide' : 'column';
}

export function DetailFieldsBuilder( {
	fields,
	metaKeys,
	taxonomies,
	terminology,
	onChange,
} ) {
	const labelGroups = buildWpSourceGroups( {
		include: DETAIL_INCLUDE,
		metaKeys,
		taxonomies,
		terminology,
		contentLabels: DETAIL_CONTENT_LABELS,
	} );

	function updateRow( index, patch ) {
		const next = fields.map( ( row, i ) =>
			i === index ? { ...row, ...patch } : row
		);
		onChange( next );
	}

	function addRow( source = 'title' ) {
		const type = defaultTypeForSource( source );
		const label = getWpSourceLabel( source, labelGroups ) || source;
		onChange( [
			...fields,
			{
				name: label,
				source,
				type,
				display: defaultDisplayForType( type ),
			},
		] );
	}

	function removeRow( index ) {
		onChange( fields.filter( ( _, i ) => i !== index ) );
	}

	return (
		<VStack spacing={ 3 }>
			<p>
				{ __(
					'Each row becomes a sidebar field in the viewer. Type affects rendering (e.g. Markdown for HTML-rich metadata; Keywords for chips). Display chooses column vs wide layout.',
					'vikus-viewer-embed'
				) }
			</p>
			<div className="vikus-admin-app__rows">
				{ fields.map( ( row, index ) => (
					<div
						className="vikus-admin-app__row"
						key={ `${ row.source }-${ index }` }
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Label', 'vikus-viewer-embed' ) }
							value={ row.name }
							onChange={ ( name ) =>
								updateRow( index, { name } )
							}
						/>
						<WpSourceAutocomplete
							label={ __( 'Source', 'vikus-viewer-embed' ) }
							value={ row.source }
							onChange={ ( source ) => {
								const type = defaultTypeForSource( source );
								updateRow( index, {
									source,
									type,
									display: defaultDisplayForType( type ),
								} );
							} }
							include={ DETAIL_INCLUDE }
							contentLabels={ DETAIL_CONTENT_LABELS }
							metaKeys={ metaKeys }
							taxonomies={ taxonomies }
							terminology={ terminology }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Type', 'vikus-viewer-embed' ) }
							value={ row.type || 'text' }
							options={ TYPE_OPTIONS }
							onChange={ ( type ) =>
								updateRow( index, {
									type,
									display: defaultDisplayForType( type ),
								} )
							}
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Display', 'vikus-viewer-embed' ) }
							value={ row.display || 'column' }
							options={ DISPLAY_OPTIONS }
							onChange={ ( display ) =>
								updateRow( index, { display } )
							}
						/>
						<RowRemoveButton onClick={ () => removeRow( index ) } />
					</div>
				) ) }
			</div>
			<Button
				className="vikus-admin-app__add-button"
				variant="secondary"
				onClick={ () => addRow( 'title' ) }
			>
				{ __( 'Add field', 'vikus-viewer-embed' ) }
			</Button>
		</VStack>
	);
}
