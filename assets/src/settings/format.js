/**
 * Small display helpers.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Formats a byte count for humans.
 *
 * @param {number} bytes Size in bytes.
 * @return {string} e.g. "4.1 MB".
 */
export function formatBytes( bytes ) {
	if ( ! bytes ) {
		return '';
	}
	if ( bytes >= 1024 * 1024 ) {
		return sprintf(
			/* translators: %s: Size in megabytes. */
			__( '%s MB', 'infinite-icons' ),
			( bytes / ( 1024 * 1024 ) ).toFixed( 1 )
		);
	}
	return sprintf(
		/* translators: %s: Size in kilobytes. */
		__( '%s KB', 'infinite-icons' ),
		Math.round( bytes / 1024 )
	);
}

/**
 * Formats an icon count.
 *
 * @param {number} count Number of icons.
 * @return {string} e.g. "6,184 icons".
 */
export function formatCount( count ) {
	return sprintf(
		/* translators: %s: Number of icons. */
		_n( '%s icon', '%s icons', count, 'infinite-icons' ),
		count.toLocaleString()
	);
}

/**
 * Formats a variant key for display.
 *
 * @param {string} key   Variant key, empty for the default.
 * @param {string} label Variant label from the manifest.
 * @return {string} Label to show.
 */
export function variantLabel( key, label ) {
	return label || ( key === '' ? __( 'Default', 'infinite-icons' ) : key );
}
