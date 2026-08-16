/**
 * Searchable WP source picker (post content / taxonomies / post meta).
 *
 * Built on WPDS Combobox with grouped options and an `exclude` filter so
 * callers can hide sources already used elsewhere.
 */
import { useMemo } from '@wordpress/element';
import { BaseControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Combobox } from '@wordpress/ui';
import {
	buildWpSourceGroups,
	flattenWpSourceItems,
} from '../utils/wpSourceOptions';

/**
 * @param {Object}   props
 * @param {string}   props.value
 * @param {Function} props.onChange
 * @param {string}   [props.label]
 * @param {string}   [props.help]
 * @param {string}   [props.placeholder]
 * @param {boolean}  [props.allowClear]
 * @param {string[]} [props.include]
 * @param {string[]} [props.exclude]
 * @param {Array}    [props.metaKeys]
 * @param {Array}    [props.taxonomies]
 * @param {Object}   [props.terminology]
 * @param {{ hierarchical?: boolean }} [props.taxonomyFilter]
 * @param {Record<string, string>} [props.contentLabels]
 * @param {string}   [props.className]
 * @param {boolean}  [props.disabled]
 */
export function WpSourceAutocomplete( {
	value = '',
	onChange,
	label,
	help,
	placeholder = __( 'Search sources…', 'vikus-viewer-embed' ),
	allowClear = false,
	include = [ 'post_date', 'taxonomy', 'meta' ],
	exclude = [],
	metaKeys = [],
	taxonomies = [],
	terminology = {},
	taxonomyFilter = null,
	contentLabels = {},
	className = '',
	disabled = false,
} ) {
	const groups = useMemo(
		() =>
			buildWpSourceGroups( {
				include,
				exclude,
				value,
				metaKeys,
				taxonomies,
				terminology,
				taxonomyFilter,
				contentLabels,
			} ),
		[
			include,
			exclude,
			value,
			metaKeys,
			taxonomies,
			terminology,
			taxonomyFilter,
			contentLabels,
		]
	);

	const labelGroups = useMemo(
		() =>
			buildWpSourceGroups( {
				include,
				exclude: [],
				value: '',
				metaKeys,
				taxonomies,
				terminology,
				taxonomyFilter,
				contentLabels,
			} ),
		[
			include,
			metaKeys,
			taxonomies,
			terminology,
			taxonomyFilter,
			contentLabels,
		]
	);

	const selectedItem = useMemo( () => {
		if ( ! value ) {
			return null;
		}
		const fromVisible = flattenWpSourceItems( groups ).find(
			( item ) => item.value === value
		);
		if ( fromVisible ) {
			return fromVisible;
		}
		const fromAll = flattenWpSourceItems( labelGroups ).find(
			( item ) => item.value === value
		);
		return fromAll || { value, label: value };
	}, [ value, groups, labelGroups ] );

	function handleValueChange( next ) {
		onChange( next?.value || '' );
	}

	function isItemEqualToValue( a, b ) {
		return a?.value === b?.value;
	}

	const field = (
		<div className="vikus-admin-app__source-combobox-field">
			<Combobox.Root
				items={ groups }
				value={ selectedItem }
				onValueChange={ handleValueChange }
				isItemEqualToValue={ isItemEqualToValue }
				disabled={ disabled }
			>
				<div className="vikus-admin-app__source-combobox-trigger-row">
					<Combobox.Trigger
						placeholder={ placeholder }
						disabled={ disabled }
						aria-label={ label || __( 'Source', 'vikus-viewer-embed' ) }
					/>
					{ allowClear && selectedItem ? <Combobox.Clear /> : null }
				</div>
				<Combobox.Popup
					positioner={
						<Combobox.Positioner
							style={ {
								'--wp-ui-combobox-z-index': '100000',
							} }
						/>
					}
				>
					<div className="vikus-admin-app__source-combobox-search">
						<Combobox.Input
							placeholder={ __(
								'Filter sources…',
								'vikus-viewer-embed'
							) }
						/>
					</div>
					<Combobox.Empty>
						{ __( 'No matching sources.', 'vikus-viewer-embed' ) }
					</Combobox.Empty>
					<Combobox.List>
						<Combobox.ListBody>
							<Combobox.Collection>
								{ ( group ) => (
									<Combobox.Group
										key={ group.value }
										items={ group.items }
									>
										<Combobox.GroupLabel>
											{ group.label }
										</Combobox.GroupLabel>
										<Combobox.Collection>
											{ ( item ) => (
												<Combobox.Item
													key={ item.value }
													value={ item }
												>
													{ item.label }
												</Combobox.Item>
											) }
										</Combobox.Collection>
									</Combobox.Group>
								) }
							</Combobox.Collection>
						</Combobox.ListBody>
					</Combobox.List>
				</Combobox.Popup>
			</Combobox.Root>
		</div>
	);

	if ( ! label && ! help ) {
		return (
			<div
				className={ `vikus-admin-app__source-combobox ${ className }`.trim() }
			>
				{ field }
			</div>
		);
	}

	return (
		<BaseControl
			__nextHasNoMarginBottom
			className={ `vikus-admin-app__source-combobox ${ className }`.trim() }
			label={ label }
			help={ help }
		>
			{ field }
		</BaseControl>
	);
}
