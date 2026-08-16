/**
 * Crossfilter dimension row builder (WP sources, same idea as Groups).
 */
import {
	Button,
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { RowRemoveButton } from './RowRemoveButton';
import { WpSourceAutocomplete } from './WpSourceAutocomplete';

const SOURCE_INCLUDE = [ 'post_date', 'taxonomy', 'meta' ];

/**
 * @param {Object}   props
 * @param {Array}    props.dims
 * @param {Array}    props.metaKeys
 * @param {Array}    props.taxonomies
 * @param {Object}   props.terminology
 * @param {Function} props.onChange
 */
export function CrossfilterBuilder( {
	dims,
	metaKeys,
	taxonomies,
	terminology,
	onChange,
} ) {
	function updateRow( index, patch ) {
		onChange(
			dims.map( ( row, i ) => ( i === index ? { ...row, ...patch } : row ) )
		);
	}

	return (
		<VStack spacing={ 3 }>
			<p>
				{ __(
					'Each dimension is a filter facet. The first row also fills the keywords column used by search.',
					'vikus-viewer-embed'
				) }
			</p>
			<div className="vikus-admin-app__rows">
				{ ( dims || [] ).map( ( row, index ) => {
					const exclude = ( dims || [] )
						.map( ( d, i ) =>
							i === index ? null : d.source || null
						)
						.filter( Boolean );

					return (
						<div className="vikus-admin-app__row" key={ index }>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Label', 'vikus-viewer-embed' ) }
								value={ row.label || '' }
								onChange={ ( label ) =>
									updateRow( index, { label } )
								}
							/>
							<WpSourceAutocomplete
								label={ __( 'Source', 'vikus-viewer-embed' ) }
								value={ row.source || '' }
								onChange={ ( source ) =>
									updateRow( index, { source } )
								}
								include={ SOURCE_INCLUDE }
								exclude={ exclude }
								allowClear
								metaKeys={ metaKeys }
								taxonomies={ taxonomies }
								terminology={ terminology }
							/>
							<RowRemoveButton
								onClick={ () =>
									onChange(
										dims.filter( ( _, i ) => i !== index )
									)
								}
							/>
						</div>
					);
				} ) }
			</div>
			<Button
				className="vikus-admin-app__add-button"
				variant="secondary"
				onClick={ () =>
					onChange( [
						...( dims || [] ),
						{ label: '', source: '' },
					] )
				}
			>
				{ __( 'Add dimension', 'vikus-viewer-embed' ) }
			</Button>
		</VStack>
	);
}
