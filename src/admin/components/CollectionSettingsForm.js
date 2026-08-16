/**
 * Collection editor: name + Data / Sidebars / Appearance / Advanced tabs.
 */
import { useMemo } from '@wordpress/element';
import {
	TabPanel,
	TextControl,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { DataForm } from '@wordpress/dataviews';
import { SettingsCard } from './SettingsCard';
import { LayoutsBuilder } from './LayoutsBuilder';
import { DetailFieldsBuilder } from './DetailFieldsBuilder';
import { CrossfilterBuilder } from './CrossfilterBuilder';
import { WpSourceAutocomplete } from './WpSourceAutocomplete';
import { ViewerStyleBuilder } from './ViewerStyleBuilder';

const PLUGIN_URL =
	( typeof window !== 'undefined' &&
		window.vikusViewerAdminApp?.pluginUrl ) ||
	'';
const ILLUSTRATION_TAGS = `${ PLUGIN_URL }vendor-vikus/img/infobar_tags_b.svg`;
const ILLUSTRATION_TIME = `${ PLUGIN_URL }vendor-vikus/img/infobar_time_b.svg`;

const DEFAULT_KEYWORD_INCLUDE = [ 'taxonomy', 'meta' ];
const HIERARCHICAL_KEYWORD_INCLUDE = [ 'taxonomy' ];

/**
 * Encode keyword settings as a WpSourceAutocomplete value.
 *
 * @param {Object} settings
 * @return {string}
 */
function keywordSourceValue( settings ) {
	if ( ( settings.keyword_source || 'taxonomy' ) === 'meta' ) {
		const key = settings.keyword_meta_key || '';
		return key ? `meta:${ key }` : '';
	}
	const tax = ( settings.keyword_taxonomies || [] )[ 0 ] || '';
	return tax ? `taxonomy:${ tax }` : '';
}

/**
 * Decode a source picker value into keyword settings fields.
 *
 * @param {string} source
 * @return {Object}
 */
function parseKeywordSource( source ) {
	if ( source.startsWith( 'meta:' ) ) {
		return {
			keyword_source: 'meta',
			keyword_meta_key: source.slice( 'meta:'.length ),
			keyword_taxonomies: [],
		};
	}
	if ( source.startsWith( 'taxonomy:' ) ) {
		const tax = source.slice( 'taxonomy:'.length );
		return {
			keyword_source: 'taxonomy',
			keyword_meta_key: '',
			keyword_taxonomies: tax ? [ tax ] : [],
		};
	}
	return {
		keyword_source: 'taxonomy',
		keyword_meta_key: '',
		keyword_taxonomies: [],
	};
}

/**
 * @param {Object}   props
 * @param {string}   props.title
 * @param {Object}   props.settings
 * @param {Object}   props.ptData
 * @param {{ name: string, label: string }} [props.sourcePostType]
 * @param {Function} props.onTitleChange
 * @param {Function} props.onSettingsChange
 */
export function CollectionSettingsForm( {
	title,
	settings,
	ptData,
	sourcePostType,
	onTitleChange,
	onSettingsChange,
} ) {
	const terms = ptData?.terminology || {};

	const data = useMemo(
		() => ( {
			keyword_delimiter: settings.keyword_delimiter || ',',
			project_name: settings.project_name || '',
			info_markdown: settings.info_markdown || '',
			filter_type: settings.filter_type || 'default',
			sprite_size: Number( settings.sprite_size ) || 128,
			medium_size: Number( settings.medium_size ) || 1024,
			large_size: Number( settings.large_size ) || 4096,
			sheet_dimension: Number( settings.sheet_dimension ) || 2048,
			batch_size: Number( settings.batch_size ) || 15,
			search_enabled:
				settings.search_enabled === undefined
					? true
					: !! settings.search_enabled,
			pages_enabled: !! settings.pages_enabled,
			sort_keywords: settings.sort_keywords || 'alphabetical',
		} ),
		[ settings ]
	);

	const fields = useMemo(
		() => [
			{
				id: 'filter_type',
				label: __( 'Filter mode', 'vikus-viewer-embed' ),
				type: 'text',
				Edit: 'toggleGroup',
				elements: [
					{
						value: 'default',
						label: __( 'Default', 'vikus-viewer-embed' ),
					},
					{
						value: 'hierarchical',
						label: __( 'Hierarchical', 'vikus-viewer-embed' ),
					},
					{
						value: 'crossfilter',
						label: __( 'Crossfilter', 'vikus-viewer-embed' ),
					},
				],
			},
			{
				id: 'project_name',
				label: __( 'Project name', 'vikus-viewer-embed' ),
				type: 'text',
				description: __(
					'Shown in the viewer. Leave blank to use the collection name.',
					'vikus-viewer-embed'
				),
			},
			{
				id: 'info_markdown',
				label: __( 'Info panel (Markdown)', 'vikus-viewer-embed' ),
				type: 'text',
				Edit: 'textarea',
				rows: 8,
				description: __(
					'Content for the left info panel (info.md). Prefills from the post type description on create.',
					'vikus-viewer-embed'
				),
			},
			{
				id: 'keyword_delimiter',
				label: __( 'Keyword delimiter', 'vikus-viewer-embed' ),
				type: 'text',
				elements: [
					{ value: ',', label: ',' },
					{ value: ';', label: ';' },
				],
				description: __(
					'Delimiter used when splitting and joining keyword values in data.csv.',
					'vikus-viewer-embed'
				),
			},
			{
				id: 'sprite_size',
				label: __( 'Sprite cell', 'vikus-viewer-embed' ),
				type: 'integer',
			},
			{
				id: 'medium_size',
				label: __( 'Medium size', 'vikus-viewer-embed' ),
				type: 'integer',
			},
			{
				id: 'large_size',
				label: __( 'Large size', 'vikus-viewer-embed' ),
				type: 'integer',
			},
			{
				id: 'sheet_dimension',
				label: __( 'Sheet dimension', 'vikus-viewer-embed' ),
				type: 'integer',
			},
			{
				id: 'batch_size',
				label: __( 'Batch size', 'vikus-viewer-embed' ),
				type: 'integer',
			},
			{
				id: 'search_enabled',
				label: __( 'Show search bar', 'vikus-viewer-embed' ),
				type: 'boolean',
				description: __(
					'Hide the viewer search bar when disabled.',
					'vikus-viewer-embed'
				),
			},
			{
				id: 'pages_enabled',
				label: __( 'Enable detail pages', 'vikus-viewer-embed' ),
				type: 'boolean',
				description: __(
					'When enabled, the featured image is page 1 and additional image attachments on the item become further pages in the detail preview.',
					'vikus-viewer-embed'
				),
			},
			{
				id: 'sort_keywords',
				label: __( 'Filter values order', 'vikus-viewer-embed' ),
				type: 'text',
				Edit: 'toggleGroup',
				elements: [
					{
						value: 'alphabetical',
						label: __( 'A–Z', 'vikus-viewer-embed' ),
					},
					{
						value: 'alphabetical-reverse',
						label: __( 'Z–A', 'vikus-viewer-embed' ),
					},
					{
						value: 'count',
						label: __( 'Count ↓', 'vikus-viewer-embed' ),
					},
					{
						value: 'count-reverse',
						label: __( 'Count ↑', 'vikus-viewer-embed' ),
					},
				],
				isVisible: ( item ) => item.filter_type !== 'crossfilter',
			},
		],
		[]
	);

	function onFormChange( edits ) {
		if ( ! Object.keys( edits ).length ) {
			return;
		}
		onSettingsChange( edits );
	}

	const filtersModeForm = useMemo(
		() => ( {
			fields: [ 'filter_type' ],
		} ),
		[]
	);

	const filtersSortForm = useMemo(
		() => ( {
			fields: [ 'sort_keywords' ],
		} ),
		[]
	);

	const infoForm = useMemo(
		() => ( {
			fields: [
				{
					id: 'project_name_row',
					layout: {
						type: 'row',
						alignment: 'start',
						styles: {
							project_name: {
								flex: '1 1 20rem',
								minWidth: '16rem',
							},
						},
					},
					children: [ 'project_name' ],
				},
				'info_markdown',
			],
		} ),
		[]
	);

	const searchForm = useMemo(
		() => ( {
			fields: [ 'search_enabled' ],
		} ),
		[]
	);

	const csvForm = useMemo(
		() => ( {
			fields: [
				{
					id: 'csv_row',
					layout: {
						type: 'row',
						alignment: 'start',
						styles: {
							keyword_delimiter: { flex: '0 0 10rem' },
						},
					},
					children: [ 'keyword_delimiter' ],
				},
			],
		} ),
		[]
	);

	const imageProcessingForm = useMemo(
		() => ( {
			fields: [
				'pages_enabled',
				{
					id: 'texture_sizes',
					layout: {
						type: 'row',
						alignment: 'start',
						styles: {
							sprite_size: {
								flex: '1 1 8rem',
								minWidth: '8rem',
							},
							medium_size: {
								flex: '1 1 8rem',
								minWidth: '8rem',
							},
							large_size: {
								flex: '1 1 8rem',
								minWidth: '8rem',
							},
						},
					},
					children: [ 'sprite_size', 'medium_size', 'large_size' ],
				},
				{
					id: 'pipeline_row',
					layout: {
						type: 'row',
						alignment: 'start',
						styles: {
							sheet_dimension: {
								flex: '1 1 10rem',
								minWidth: '8rem',
							},
							batch_size: {
								flex: '1 1 10rem',
								minWidth: '8rem',
							},
						},
					},
					children: [ 'sheet_dimension', 'batch_size' ],
				},
			],
		} ),
		[]
	);

	return (
		<VStack spacing={ 6 } className="vikus-admin-app__editor">
			<HStack
				alignment="top"
				spacing={ 4 }
				justify="flex-start"
				wrap
				className="vikus-admin-app__name-row"
			>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={
						<>
							{ __( 'Collection name', 'vikus-viewer-embed' ) }
							<span
								className="vikus-admin-app__required"
								aria-hidden="true"
							>
								{ ' *' }
							</span>
							<span className="screen-reader-text">
								{ __( '(required)', 'vikus-viewer-embed' ) }
							</span>
						</>
					}
					value={ title || '' }
					onChange={ onTitleChange }
					className="vikus-admin-app__collection-name"
				/>
				{ sourcePostType ? (
					<div className="vikus-admin-app__source-meta">
						<span className="components-base-control__label">
							{ __( 'Source', 'vikus-viewer-embed' ) }
						</span>
						<p className="vikus-admin-app__source-meta-value">
							<span>{ sourcePostType.label }</span>
							<code>{ sourcePostType.name }</code>
						</p>
					</div>
				) : null }
			</HStack>

			<TabPanel
				className="vikus-admin-app__tabs"
				tabs={ [
					{
						name: 'data',
						title: __( 'Data', 'vikus-viewer-embed' ),
					},
					{
						name: 'sidebars',
						title: __( 'Sidebars', 'vikus-viewer-embed' ),
					},
					{
						name: 'appearance',
						title: __( 'Appearance', 'vikus-viewer-embed' ),
					},
					{
						name: 'advanced',
						title: __( 'Advanced', 'vikus-viewer-embed' ),
					},
				] }
			>
				{ ( tab ) => {
					if ( tab.name === 'data' ) {
						return (
							<VStack spacing={ 4 } className="vikus-admin-app__tab">
								<SettingsCard
									title={ __( 'Filters', 'vikus-viewer-embed' ) }
									description={ __(
										'How visitors filter items in the viewer.',
										'vikus-viewer-embed'
									) }
									illustration={ ILLUSTRATION_TAGS }
								>
									<VStack spacing={ 4 }>
										<DataForm
											data={ data }
											fields={ fields }
											form={ filtersModeForm }
											onChange={ onFormChange }
										/>
										{ settings.filter_type === 'default' ? (
											<WpSourceAutocomplete
												label={ __(
													'Keyword source',
													'vikus-viewer-embed'
												) }
												value={ keywordSourceValue(
													settings
												) }
												onChange={ ( source ) =>
													onSettingsChange(
														parseKeywordSource(
															source
														)
													)
												}
												include={
													DEFAULT_KEYWORD_INCLUDE
												}
												allowClear
												placeholder={ __(
													'Select taxonomy or meta…',
													'vikus-viewer-embed'
												) }
												metaKeys={
													ptData?.metaKeys || []
												}
												taxonomies={
													ptData?.taxonomies || []
												}
												terminology={ terms }
											/>
										) : null }
										{ settings.filter_type ===
										'hierarchical' ? (
											<WpSourceAutocomplete
												label={ __(
													'Taxonomy',
													'vikus-viewer-embed'
												) }
												value={ keywordSourceValue( {
													...settings,
													keyword_source: 'taxonomy',
												} ) }
												onChange={ ( source ) =>
													onSettingsChange(
														parseKeywordSource(
															source
														)
													)
												}
												include={
													HIERARCHICAL_KEYWORD_INCLUDE
												}
												taxonomyFilter={ {
													hierarchical: true,
												} }
												allowClear
												placeholder={ __(
													'Select hierarchical taxonomy…',
													'vikus-viewer-embed'
												) }
												taxonomies={
													ptData?.taxonomies || []
												}
												terminology={ terms }
											/>
										) : null }
										{ settings.filter_type ===
										'crossfilter' ? (
											<CrossfilterBuilder
												dims={
													settings.crossfilter_dims ||
													[]
												}
												metaKeys={
													ptData?.metaKeys || []
												}
												taxonomies={
													ptData?.taxonomies || []
												}
												terminology={ terms }
												onChange={ (
													crossfilter_dims
												) =>
													onSettingsChange( {
														crossfilter_dims,
													} )
												}
											/>
										) : null }
										{ settings.filter_type !==
										'crossfilter' ? (
											<DataForm
												data={ data }
												fields={ fields }
												form={ filtersSortForm }
												onChange={ onFormChange }
											/>
										) : null }
									</VStack>
								</SettingsCard>

								<SettingsCard
									title={ __( 'Groups', 'vikus-viewer-embed' ) }
									description={ __(
										'Navigation layouts in the viewer. The primary group is the Time axis; extra groups are optional.',
										'vikus-viewer-embed'
									) }
									illustration={ ILLUSTRATION_TIME }
								>
									<LayoutsBuilder
										layouts={ settings.layouts || [] }
										yearSource={
											settings.year_source ||
											'post_date'
										}
										yearTaxonomy={
											settings.year_taxonomy || ''
										}
										yearMetaKey={
											settings.year_meta_key || ''
										}
										metaKeys={ ptData.metaKeys || [] }
										taxonomies={
											ptData.taxonomies || []
										}
										terminology={ terms }
										timelineIncludeUnused={
											!! settings.timeline_include_unused
										}
										onTimelineIncludeUnusedChange={ (
											timeline_include_unused
										) =>
											onSettingsChange( {
												timeline_include_unused,
											} )
										}
										onChange={ onSettingsChange }
									/>
								</SettingsCard>
							</VStack>
						);
					}

					if ( tab.name === 'sidebars' ) {
						return (
							<VStack spacing={ 4 } className="vikus-admin-app__tab">
								<SettingsCard
									title={ __( 'Info', 'vikus-viewer-embed' ) }
									description={ __(
										'Left sidebar: project title and introductory markdown.',
										'vikus-viewer-embed'
									) }
								>
									<DataForm
										data={ data }
										fields={ fields }
										form={ infoForm }
										onChange={ onFormChange }
									/>
								</SettingsCard>

								<SettingsCard
									title={ __( 'Item detail', 'vikus-viewer-embed' ) }
									description={ __(
										'Right sidebar fields shown when an item is selected.',
										'vikus-viewer-embed'
									) }
								>
									<DetailFieldsBuilder
										fields={ settings.detail_fields || [] }
										metaKeys={ ptData.metaKeys || [] }
										taxonomies={ ptData.taxonomies || [] }
										terminology={ terms }
										onChange={ ( detail_fields ) =>
											onSettingsChange( { detail_fields } )
										}
									/>
								</SettingsCard>
							</VStack>
						);
					}

					if ( tab.name === 'appearance' ) {
						return (
							<VStack spacing={ 4 } className="vikus-admin-app__tab">
								<SettingsCard
									title={ __( 'Colors & type', 'vikus-viewer-embed' ) }
									description={ __(
										'Font applies on save. Colors are written to the viewer style and need a rebuild.',
										'vikus-viewer-embed'
									) }
								>
									<ViewerStyleBuilder
										style={ settings.viewer_style || {} }
										onChange={ ( viewer_style ) =>
											onSettingsChange( { viewer_style } )
										}
									/>
								</SettingsCard>

								<SettingsCard
									title={ __( 'Search', 'vikus-viewer-embed' ) }
									description={ __(
										'Show or hide the search bar above the canvas.',
										'vikus-viewer-embed'
									) }
								>
									<DataForm
										data={ data }
										fields={ fields }
										form={ searchForm }
										onChange={ onFormChange }
									/>
								</SettingsCard>
							</VStack>
						);
					}

					return (
						<VStack spacing={ 4 } className="vikus-admin-app__tab">
							<SettingsCard
								title={ __( 'Source', 'vikus-viewer-embed' ) }
								description={ __(
									'Rarely changed inclusion rules for the source post type.',
									'vikus-viewer-embed'
								) }
							>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Only include posts with a featured image',
										'vikus-viewer-embed'
									) }
									help={ __(
										'Recommended for Vikus. Turn off only if items without thumbnails should appear.',
										'vikus-viewer-embed'
									) }
									checked={ !! settings.require_thumbnail }
									onChange={ ( require_thumbnail ) =>
										onSettingsChange( { require_thumbnail } )
									}
								/>
							</SettingsCard>

							<SettingsCard
								title={ __( 'CSV', 'vikus-viewer-embed' ) }
								description={ __(
									'How keyword values are split and joined in data.csv.',
									'vikus-viewer-embed'
								) }
							>
								<DataForm
									data={ data }
									fields={ fields }
									form={ csvForm }
									onChange={ onFormChange }
								/>
							</SettingsCard>

							<SettingsCard
								title={ __( 'Image processing', 'vikus-viewer-embed' ) }
								description={ __(
									'Texture sizes, sprite sheet dimension, and batch size for the build pipeline.',
									'vikus-viewer-embed'
								) }
							>
								<DataForm
									data={ data }
									fields={ fields }
									form={ imageProcessingForm }
									onChange={ onFormChange }
								/>
							</SettingsCard>
						</VStack>
					);
				} }
			</TabPanel>
		</VStack>
	);
}
