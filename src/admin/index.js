import { createRoot } from '@wordpress/element';
import { App } from './App';
import './style.scss';

const rootEl = document.getElementById( 'vikus-viewer-embed-admin' );
if ( rootEl ) {
	const config = window.vikusViewerAdminApp || {};
	createRoot( rootEl ).render( <App config={ config } /> );
}
