/**
 * Build grouped WP field source options (post content / taxonomies / meta).
 */
import { __ } from '@wordpress/i18n';

/**
 * Built-in post-content sources keyed by include token.
 *
 * @type {Record<string, { value: string, label: string }>}
 */
const CONTENT_SOURCE_DEFS = {
	title: {
		value: 'title',
		label: __( 'Title', 'vikus-viewer-embed' ),
	},
	excerpt: {
		value: 'excerpt',
		label: __( 'Excerpt', 'vikus-viewer-embed' ),
	},
	content: {
		value: 'content',
		label: __( 'Content', 'vikus-viewer-embed' ),
	},
	permalink: {
		value: 'permalink',
		label: __( 'Permalink', 'vikus-viewer-embed' ),
	},
	post_date: {
		value: 'post_date',
		label: __( 'Post date (year)', 'vikus-viewer-embed' ),
	},
	year: {
		value: 'year',
		label: __( 'Year (group column)', 'vikus-viewer-embed' ),
	},
	keywords: {
		value: 'keywords',
		label: __( 'Keywords', 'vikus-viewer-embed' ),
	},
};

const CONTENT_ORDER = [
	'title',
	'excerpt',
	'content',
	'permalink',
	'post_date',
	'year',
	'keywords',
];

/**
 * @param {Object}   args
 * @param {string[]} [args.include] Tokens: content keys and/or `taxonomy` / `meta`.
 * @param {string[]} [args.exclude] Source values to hide (current `value` is kept).
 * @param {string}   [args.value]   Currently selected source (never excluded).
 * @param {Array}    [args.metaKeys]
 * @param {Array}    [args.taxonomies]
 * @param {Object}   [args.terminology]
 * @param {{ hierarchical?: boolean }} [args.taxonomyFilter]
 * @param {Record<string, string>} [args.contentLabels] Override labels for content keys.
 * @return {Array<{ value: string, label: string, items: Array<{ value: string, label: string }> }>}
 */
export function buildWpSourceGroups( {
	include = [ 'post_date', 'taxonomy', 'meta' ],
	exclude = [],
	value = '',
	metaKeys = [],
	taxonomies = [],
	terminology = {},
	taxonomyFilter = null,
	contentLabels = {},
} = {} ) {
	const includeSet = new Set( include || [] );
	const excludeSet = new Set(
		( exclude || [] ).filter( ( source ) => source && source !== value )
	);

	const contentItems = [];
	for ( const key of CONTENT_ORDER ) {
		const def = CONTENT_SOURCE_DEFS[ key ];
		if ( ! def || excludeSet.has( def.value ) ) {
			continue;
		}
		if ( ! includeSet.has( key ) ) {
			continue;
		}
		contentItems.push( {
			value: def.value,
			label: contentLabels[ key ] || def.label,
		} );
	}

	const taxItems = [];
	if ( includeSet.has( 'taxonomy' ) ) {
		let list = taxonomies || [];
		if ( taxonomyFilter?.hierarchical === true ) {
			list = list.filter( ( t ) => t.hierarchical );
		} else if ( taxonomyFilter?.hierarchical === false ) {
			list = list.filter( ( t ) => ! t.hierarchical );
		}
		for ( const t of list ) {
			const source = `taxonomy:${ t.name }`;
			if ( excludeSet.has( source ) ) {
				continue;
			}
			taxItems.push( {
				value: source,
				label: t.label
					? `${ t.label } (${ t.name })`
					: String( t.name ),
			} );
		}
	}

	const metaItems = [];
	if ( includeSet.has( 'meta' ) ) {
		for ( const m of metaKeys || [] ) {
			const source = `meta:${ m.key }`;
			if ( excludeSet.has( source ) ) {
				continue;
			}
			metaItems.push( {
				value: source,
				label: m.label || m.key,
			} );
		}
	}

	const groups = [];
	if ( contentItems.length > 0 ) {
		groups.push( {
			value: 'post_content',
			label: __( 'Post content', 'vikus-viewer-embed' ),
			items: contentItems,
		} );
	}
	if ( taxItems.length > 0 ) {
		groups.push( {
			value: 'taxonomies',
			label: __( 'Taxonomies', 'vikus-viewer-embed' ),
			items: taxItems,
		} );
	}
	if ( metaItems.length > 0 ) {
		groups.push( {
			value: 'post_meta',
			label:
				terminology?.source || __( 'Post meta', 'vikus-viewer-embed' ),
			items: metaItems,
		} );
	}

	return groups;
}

/**
 * @param {Array<{ items?: Array }>} groups
 * @return {Array<{ value: string, label: string }>}
 */
export function flattenWpSourceItems( groups ) {
	return ( groups || [] ).flatMap( ( group ) => group.items || [] );
}

/**
 * Resolve a display label for a stored source value.
 *
 * @param {string} value
 * @param {Array<{ items?: Array }>} groups Unfiltered groups preferred.
 * @return {string}
 */
export function getWpSourceLabel( value, groups ) {
	if ( ! value ) {
		return '';
	}
	const match = flattenWpSourceItems( groups ).find(
		( item ) => item.value === value
	);
	return match?.label || value;
}
