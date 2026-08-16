/**
 * Viewer groups: primary (year / timeline) + optional extra group layouts.
 *
 * The primary group always maps into Vikus’s required CSV `year` column and is
 * the only layout that receives timeline description cards. Extra groups export
 * their own CSV column and layout groupKey.
 */
import {
	Button,
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { RowRemoveButton } from './RowRemoveButton';
import { WpSourceAutocomplete } from './WpSourceAutocomplete';
import {
	buildWpSourceGroups,
	flattenWpSourceItems,
} from '../utils/wpSourceOptions';

const DEFAULT_PRIMARY = {
	title: 'Time',
	type: 'group',
	source: 'year',
	columns: 0,
};

const DEFAULT_INCLUDE = [ 'post_date', 'taxonomy', 'meta' ];
/** Extra groups: WP sources only (not the Filters-built `keywords` CSV column). */
const EXTRA_INCLUDE = [ 'post_date', 'taxonomy', 'meta' ];

/**
 * @param {string} yearSource
 * @param {string} yearTaxonomy
 * @param {string} yearMetaKey
 */
function defaultGroupByValue( yearSource, yearTaxonomy, yearMetaKey ) {
	if ( yearSource === 'taxonomy' && yearTaxonomy ) {
		return `taxonomy:${ yearTaxonomy }`;
	}
	if ( yearSource === 'meta' && yearMetaKey ) {
		return `meta:${ yearMetaKey }`;
	}
	return 'post_date';
}

/**
 * @param {string} value
 */
function parseDefaultGroupBy( value ) {
	if ( value.startsWith( 'taxonomy:' ) ) {
		return {
			year_source: 'taxonomy',
			year_taxonomy: value.slice( 'taxonomy:'.length ),
			year_meta_key: '',
		};
	}
	if ( value.startsWith( 'meta:' ) ) {
		return {
			year_source: 'meta',
			year_meta_key: value.slice( 'meta:'.length ),
			year_taxonomy: '',
		};
	}
	return {
		year_source: 'post_date',
		year_taxonomy: '',
		year_meta_key: '',
	};
}

/**
 * @param {Object}   props
 * @param {Array}    props.layouts
 * @param {string}   props.yearSource
 * @param {string}   props.yearTaxonomy
 * @param {string}   props.yearMetaKey
 * @param {Array}    props.metaKeys
 * @param {Array}    props.taxonomies
 * @param {Object}   props.terminology
 * @param {boolean}  [props.timelineIncludeUnused]
 * @param {Function} [props.onTimelineIncludeUnusedChange]
 * @param {Function} props.onChange Settings patch (layouts and/or year_*).
 */
export function LayoutsBuilder( {
	layouts,
	yearSource = 'post_date',
	yearTaxonomy = '',
	yearMetaKey = '',
	metaKeys,
	taxonomies,
	terminology,
	timelineIncludeUnused = false,
	onTimelineIncludeUnusedChange,
	onChange,
} ) {
	const primary =
		layouts?.find( ( l ) => ( l.source || 'year' ) === 'year' ) ||
		layouts?.[ 0 ] ||
		DEFAULT_PRIMARY;
	const additional = ( layouts || [] ).filter(
		( l ) => l !== primary && ( l.source || '' ) !== 'year'
	);

	const defaultGroupBy = defaultGroupByValue(
		yearSource,
		yearTaxonomy,
		yearMetaKey
	);

	const showTimelineUnusedToggle =
		yearSource === 'taxonomy' &&
		!! yearTaxonomy &&
		typeof onTimelineIncludeUnusedChange === 'function';

	/** Sources already used by the primary year mapping. */
	const defaultMappedSource =
		yearSource === 'taxonomy' && yearTaxonomy
			? `taxonomy:${ yearTaxonomy }`
			: yearSource === 'meta' && yearMetaKey
			? `meta:${ yearMetaKey }`
			: yearSource === 'post_date'
			? 'post_date'
			: null;

	function excludeForExtraRow( index ) {
		const used = additional
			.map( ( row, i ) => ( i === index ? null : row.source ) )
			.filter( Boolean );
		if ( defaultMappedSource ) {
			used.push( defaultMappedSource );
		}
		return used;
	}

	const unusedExtraSources = flattenWpSourceItems(
		buildWpSourceGroups( {
			include: EXTRA_INCLUDE,
			exclude: [
				...( defaultMappedSource ? [ defaultMappedSource ] : [] ),
				...additional.map( ( row ) => row.source ).filter( Boolean ),
			],
			metaKeys,
			taxonomies,
			terminology,
		} )
	);

	function commitLayouts( nextPrimary, nextAdditional ) {
		onChange( {
			layouts: [
				{
					...DEFAULT_PRIMARY,
					...nextPrimary,
					type: 'group',
					source: 'year',
				},
				...nextAdditional.map( ( row ) => ( {
					...row,
					type: 'group',
				} ) ),
			],
		} );
	}

	function updatePrimary( patch ) {
		commitLayouts( { ...primary, ...patch }, additional );
	}

	function updateDefaultGroupBy( value ) {
		onChange( parseDefaultGroupBy( value ) );
	}

	function updateAdditional( index, patch ) {
		commitLayouts(
			primary,
			additional.map( ( row, i ) =>
				i === index ? { ...row, ...patch } : row
			)
		);
	}

	return (
		<VStack spacing={ 4 }>
			<section className="vikus-admin-app__group-section">
				<h3 className="vikus-admin-app__group-section-title">
					{ __( 'Primary group', 'vikus-viewer-embed' ) }
				</h3>
				<p className="vikus-admin-app__group-section-help">
					{ __(
						'Required Time axis (CSV year column). When you group by a taxonomy, term descriptions become timeline cards under those columns.',
						'vikus-viewer-embed'
					) }
				</p>
				<div className="vikus-admin-app__rows">
					<div className="vikus-admin-app__row">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Button title', 'vikus-viewer-embed' ) }
							value={ primary.title || 'Time' }
							onChange={ ( title ) =>
								updatePrimary( { title } )
							}
						/>
						<WpSourceAutocomplete
							label={ __( 'Group by', 'vikus-viewer-embed' ) }
							value={ defaultGroupBy }
							onChange={ updateDefaultGroupBy }
							include={ DEFAULT_INCLUDE }
							metaKeys={ metaKeys }
							taxonomies={ taxonomies }
							terminology={ terminology }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="number"
							min={ 0 }
							max={ 120 }
							label={ __( 'Columns', 'vikus-viewer-embed' ) }
							value={
								primary.columns === undefined ||
								primary.columns === null
									? 0
									: primary.columns
							}
							onChange={ ( v ) =>
								updatePrimary( {
									columns: Math.max(
										0,
										Math.min(
											120,
											parseInt( v, 10 ) || 0
										)
									),
								} )
							}
						/>
					</div>
				</div>
				{ showTimelineUnusedToggle ? (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Include unused terms in the timeline',
							'vikus-viewer-embed'
						) }
						help={ __(
							'Adds columns for taxonomy terms that are not used by any item in this collection.',
							'vikus-viewer-embed'
						) }
						checked={ !! timelineIncludeUnused }
						onChange={ onTimelineIncludeUnusedChange }
					/>
				) : null }
			</section>

			<section className="vikus-admin-app__group-section">
				<h3 className="vikus-admin-app__group-section-title">
					{ __( 'Extra groups', 'vikus-viewer-embed' ) }
				</h3>
				<p className="vikus-admin-app__group-section-help">
					{ __(
						'Optional additional navigation layouts (post date year, taxonomy, or post meta). Each source can be used once. These groups do not get timeline description cards.',
						'vikus-viewer-embed'
					) }
				</p>
				<div className="vikus-admin-app__rows">
					{ additional.map( ( row, index ) => (
						<div className="vikus-admin-app__row" key={ index }>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								label={ __( 'Button title', 'vikus-viewer-embed' ) }
								value={ row.title || '' }
								onChange={ ( title ) =>
									updateAdditional( index, { title } )
								}
							/>
							<WpSourceAutocomplete
								label={ __( 'Group by', 'vikus-viewer-embed' ) }
								value={ row.source || '' }
								onChange={ ( source ) =>
									updateAdditional( index, { source } )
								}
								include={ EXTRA_INCLUDE }
								exclude={ excludeForExtraRow( index ) }
								metaKeys={ metaKeys }
								taxonomies={ taxonomies }
								terminology={ terminology }
							/>
							<TextControl
								__nextHasNoMarginBottom
								__next40pxDefaultSize
								type="number"
								min={ 0 }
								max={ 120 }
								label={ __( 'Columns', 'vikus-viewer-embed' ) }
								value={
									row.columns === undefined ||
									row.columns === null
										? 0
										: row.columns
								}
								onChange={ ( v ) =>
									updateAdditional( index, {
										columns: Math.max(
											0,
											Math.min(
												120,
												parseInt( v, 10 ) || 0
											)
										),
									} )
								}
							/>
							<RowRemoveButton
								onClick={ () =>
									commitLayouts(
										primary,
										additional.filter(
											( _, i ) => i !== index
										)
									)
								}
							/>
						</div>
					) ) }
				</div>
				<Button
					className="vikus-admin-app__add-button"
					variant="secondary"
					disabled={ unusedExtraSources.length === 0 }
					onClick={ () =>
						commitLayouts( primary, [
							...additional,
							{
								title: '',
								type: 'group',
								source: unusedExtraSources[ 0 ].value,
								columns: 0,
							},
						] )
					}
				>
					{ __( 'Add extra group', 'vikus-viewer-embed' ) }
				</Button>
			</section>
		</VStack>
	);
}
