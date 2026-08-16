const fs = require( 'fs' );
const path = require( 'path' );

const root = path.join( __dirname, '..' );
const build = path.join( root, 'build' );
const srcBlock = path.join( root, 'src', 'block' );

for ( const file of [ 'block.json', 'render.php' ] ) {
	const from = path.join( srcBlock, file );
	const to = path.join( build, file );
	if ( fs.existsSync( from ) ) {
		fs.copyFileSync( from, to );
	}
}

const dataviewsCss = path.join(
	root,
	'node_modules',
	'@wordpress',
	'dataviews',
	'build-style',
	'style.css'
);
if ( fs.existsSync( dataviewsCss ) ) {
	fs.copyFileSync( dataviewsCss, path.join( build, 'dataviews.css' ) );
}

/*
 * WPDS design tokens (:root --wpds-*). Core registers these as the `wp-theme`
 * stylesheet in WP 7.1+; until then (and as a fallback) ship the package CSS.
 */
const designTokensCss = path.join(
	root,
	'node_modules',
	'@wordpress',
	'theme',
	'src',
	'prebuilt',
	'css',
	'design-tokens.css'
);
if ( fs.existsSync( designTokensCss ) ) {
	fs.copyFileSync( designTokensCss, path.join( build, 'design-tokens.css' ) );
}
