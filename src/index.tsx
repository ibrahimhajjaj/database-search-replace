import { createRoot } from '@wordpress/element';

import { App } from './App';
import './style.scss';

const mountPoint = document.getElementById( 'safesr-root' );

if ( mountPoint ) {
	createRoot( mountPoint ).render( <App /> );
}
