/**
 * Per-collection Vikus config.style colors.
 */
import {
	Button,
	ColorIndicator,
	ColorPalette,
	Dropdown,
	SelectControl,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const DEFAULT_VIEWER_STYLE = {
	fontColor: '#111111',
	fontColorActive: '#ffffff',
	fontBackground: '#111111',
	textShadow: '1px 1px 0px #f0f0f1',
	canvasBackground: '#f0f0f1',
	timelineBackground: '#ffffff',
	timelineFontColor: '#111111',
	detailBackground: '#ffffff',
	infoBackground: '#111111',
	infoFontColor: '#ffffff',
	searchbarBackground: '#111111',
	fontFamily: 'lato',
};

const COLOR_FIELDS = [
	{
		title: __( 'Canvas', 'vikus-viewer-embed' ),
		fields: [
			{
				key: 'canvasBackground',
				label: __( 'Background', 'vikus-viewer-embed' ),
			},
			{
				key: 'fontColor',
				label: __( 'Text / labels', 'vikus-viewer-embed' ),
			},
		],
	},
	{
		title: __( 'Tags & filters', 'vikus-viewer-embed' ),
		fields: [
			{
				key: 'fontColorActive',
				label: __( 'Active text', 'vikus-viewer-embed' ),
			},
			{
				key: 'fontBackground',
				label: __( 'Active background', 'vikus-viewer-embed' ),
			},
		],
	},
	{
		title: __( 'Timeline', 'vikus-viewer-embed' ),
		fields: [
			{
				key: 'timelineBackground',
				label: __( 'Card background', 'vikus-viewer-embed' ),
			},
			{
				key: 'timelineFontColor',
				label: __( 'Card text', 'vikus-viewer-embed' ),
			},
		],
	},
	{
		title: __( 'Info', 'vikus-viewer-embed' ),
		fields: [
			{
				key: 'infoBackground',
				label: __( 'Background', 'vikus-viewer-embed' ),
			},
			{
				key: 'infoFontColor',
				label: __( 'Text', 'vikus-viewer-embed' ),
			},
		],
	},
	{
		title: __( 'Item detail', 'vikus-viewer-embed' ),
		fields: [
			{
				key: 'detailBackground',
				label: __( 'Background', 'vikus-viewer-embed' ),
			},
		],
	},
	{
		title: __( 'Search', 'vikus-viewer-embed' ),
		fields: [
			{
				key: 'searchbarBackground',
				label: __( 'Background', 'vikus-viewer-embed' ),
			},
		],
	},
];

function themeColorPalettes() {
	const palettes = window.vikusViewerAdminApp?.colorPalettes;
	return Array.isArray( palettes ) ? palettes : [];
}

function themeFontFamilies() {
	const families = window.vikusViewerAdminApp?.fontFamilies;
	if ( ! Array.isArray( families ) || ! families.length ) {
		return [
			{
				label: __( 'Lato (Vikus default)', 'vikus-viewer-embed' ),
				value: 'lato',
			},
		];
	}
	return families.map( ( family ) => ( {
		label: family.name || family.slug,
		value: family.slug,
	} ) );
}

function toHex( color ) {
	if ( ! color || typeof color !== 'string' ) {
		return '';
	}
	const trimmed = color.trim();
	if ( /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( trimmed ) ) {
		if ( trimmed.length === 4 ) {
			return (
				'#' +
				trimmed[ 1 ] +
				trimmed[ 1 ] +
				trimmed[ 2 ] +
				trimmed[ 2 ] +
				trimmed[ 3 ] +
				trimmed[ 3 ]
			).toLowerCase();
		}
		return trimmed.toLowerCase();
	}
	const rgb = trimmed.match(
		/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i
	);
	if ( ! rgb ) {
		return '';
	}
	return (
		'#' +
		[ rgb[ 1 ], rgb[ 2 ], rgb[ 3 ] ]
			.map( ( part ) =>
				Math.max( 0, Math.min( 255, parseInt( part, 10 ) ) )
					.toString( 16 )
					.padStart( 2, '0' )
			)
			.join( '' )
	);
}

function ColorField( { label, value, onChange, colors } ) {
	return (
		<Dropdown
			className="vikus-admin-app__style-color"
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					className="vikus-admin-app__style-indicator-button"
					onClick={ onToggle }
					aria-expanded={ isOpen }
					variant="tertiary"
				>
					<ColorIndicator colorValue={ value || '#fff' } />
					<span>{ label }</span>
				</Button>
			) }
			renderContent={ () => (
				<div className="vikus-admin-app__style-palette">
					<ColorPalette
						colors={ colors }
						value={ value }
						onChange={ ( next ) => {
							const hex = toHex( next );
							if ( hex ) {
								onChange( hex );
							}
						} }
						clearable={ false }
						enableAlpha={ false }
					/>
				</div>
			) }
		/>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.style
 * @param {Function} props.onChange
 */
export function ViewerStyleBuilder( { style = {}, onChange } ) {
	const value = { ...DEFAULT_VIEWER_STYLE, ...style };
	const colors = themeColorPalettes();

	function update( key, next ) {
		onChange( { ...value, [ key ]: next } );
	}

	return (
		<VStack spacing={ 4 }>
			<div className="vikus-admin-app__style-groups">
				{ COLOR_FIELDS.map( ( group ) => (
					<div
						key={ group.title }
						className="vikus-admin-app__style-group"
					>
						<h4 className="vikus-admin-app__style-group-title">
							{ group.title }
						</h4>
						<div className="vikus-admin-app__style-grid">
							{ group.fields.map( ( field ) => (
								<ColorField
									key={ field.key }
									label={ field.label }
									value={ value[ field.key ] }
									colors={ colors }
									onChange={ ( next ) =>
										update( field.key, next )
									}
								/>
							) ) }
						</div>
					</div>
				) ) }
			</div>
			<HStack
				alignment="top"
				spacing={ 4 }
				wrap
				className="vikus-admin-app__style-type-row"
			>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Font', 'vikus-viewer-embed' ) }
					help={ __(
						'Applies on save, no rebuild.',
						'vikus-viewer-embed'
					) }
					value={ value.fontFamily || 'lato' }
					options={ themeFontFamilies() }
					onChange={ ( fontFamily ) =>
						update( 'fontFamily', fontFamily )
					}
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Tag text shadow', 'vikus-viewer-embed' ) }
					help={ __(
						'CSS text-shadow value applied to keyword tags.',
						'vikus-viewer-embed'
					) }
					value={ value.textShadow || '' }
					onChange={ ( textShadow ) =>
						update( 'textShadow', textShadow )
					}
				/>
			</HStack>
			<Button
				variant="tertiary"
				onClick={ () =>
					onChange( {
						...DEFAULT_VIEWER_STYLE,
						fontFamily: value.fontFamily || 'lato',
					} )
				}
			>
				{ __( 'Reset colors to default', 'vikus-viewer-embed' ) }
			</Button>
		</VStack>
	);
}
