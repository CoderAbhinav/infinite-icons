/**
 * Build configuration.
 *
 * Extends the @wordpress/scripts default so the admin app lives under
 * assets/src/ rather than the conventional src/, which this plugin uses for PHP.
 */
const defaults = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaults,
	entry: {
		settings: path.resolve( __dirname, 'assets/src/settings/index.js' ),
	},
	output: {
		...defaults.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
