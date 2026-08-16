const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: './src/block/index.js',
		admin: './src/admin/index.js',
	},
};
