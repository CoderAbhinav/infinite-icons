/**
 * Thin wrapper around the plugin's REST routes.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Calls one of the plugin's endpoints.
 *
 * @param {string} path   Path below infinite-icons/v1, e.g. "/packs".
 * @param {Object} [opts] Extra apiFetch options.
 * @return {Promise<Object>} Parsed response body.
 */
export function request( path, opts = {} ) {
	return apiFetch( { path: `/infinite-icons/v1${ path }`, ...opts } );
}

export const getPacks = ( refresh = false ) =>
	request( `/packs${ refresh ? '?refresh=1' : '' }` );

export const installPack = ( slug ) =>
	request( '/packs/install', { method: 'POST', data: { slug } } );

export const removePack = ( slug ) =>
	request( `/packs/${ slug }`, { method: 'DELETE' } );

export const refreshIndex = () =>
	request( '/packs/refresh-index', { method: 'POST' } );

export const saveSettings = ( data ) =>
	request( '/packs/settings', { method: 'POST', data } );

export const searchIcons = ( params ) => {
	const query = new URLSearchParams();
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			query.set( key, value );
		}
	} );
	return request( `/icons?${ query.toString() }` );
};

/**
 * Turns any thrown value into something worth showing a person.
 *
 * @param {unknown} error Rejected value from apiFetch.
 * @return {string} Message to display.
 */
export function errorMessage( error ) {
	if ( typeof error === 'string' ) {
		return error;
	}
	if ( error && typeof error.message === 'string' ) {
		return error.message;
	}
	return '';
}
