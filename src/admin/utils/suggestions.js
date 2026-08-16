/**
 * Suggest keyword/year defaults from a post type bootstrap payload.
 */
export function suggestSettingsForPostType( postType, bootstrap, base = {} ) {
	const taxonomies = bootstrap?.taxonomies || [];
	const metaKeys = bootstrap?.metaKeys || [];
	const tagLike =
		taxonomies.find( ( t ) => t.name === 'post_tag' ) ||
		taxonomies.find( ( t ) => /tag|keyword|theme/i.test( t.name + t.label ) ) ||
		taxonomies[ 0 ];

	const yearTax =
		taxonomies.find( ( t ) => /year|date|periodo|período/i.test( t.name + t.label ) ) ||
		null;

	const yearMeta =
		metaKeys.find( ( m ) => /year|ano|date/i.test( m.key + ( m.label || '' ) ) ) ||
		null;

	return {
		...base,
		source_post_type: postType,
		keyword_source: tagLike ? 'taxonomy' : metaKeys[ 0 ] ? 'meta' : 'taxonomy',
		keyword_taxonomies: tagLike ? [ tagLike.name ] : [],
		keyword_meta_key: ! tagLike && metaKeys[ 0 ] ? metaKeys[ 0 ].key : '',
		year_source: yearTax ? 'taxonomy' : yearMeta ? 'meta' : 'post_date',
		year_taxonomy: yearTax ? yearTax.name : '',
		year_meta_key: ! yearTax && yearMeta ? yearMeta.key : '',
		info_markdown: base.info_markdown || '',
	};
}
