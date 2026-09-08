/**
 * Mounts the Appearance → Icons app.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import App from './App';
import './style.scss';

domReady( () => {
	const settings = window.infiniteIconsSettings;
	const node = document.getElementById( 'infinite-icons-root' );
	if ( ! node || ! settings ) {
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( settings.nonce ) );

	node.textContent = '';
	createRoot( node ).render( <App /> );
} );
