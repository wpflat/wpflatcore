import { store } from './store';

/**
 * Phone behaviour that cannot be done in CSS alone:
 * - every table gets its column names copied onto its cells (data-label) so the stylesheet can show each row as a card;
 * - the browser's "install this app" offer is kept for the Apps sheet;
 * - the service worker that lets the app open quickly and show a friendly page offline.
 */
export function startMobile( root, workerUrl ) {
	let queued = false;
	const enhance = () => {
		queued = false;
		root.querySelectorAll( '.el-table' ).forEach( ( t ) => {
			if ( t.closest( '.bd-keep-table' ) ) return;
			const labels = [ ...t.querySelectorAll( '.el-table__header-wrapper th' ) ].map( ( th ) => ( th.querySelector( '.cell' ) || th ).textContent.trim() );
			t.querySelectorAll( '.el-table__body-wrapper tbody tr.el-table__row' ).forEach( ( tr ) => {
				let titled = false;
				const cells = [ ...tr.children ];
				cells.forEach( ( td, i ) => {
					const label = labels[ i ] || '';
					if ( td.dataset.label !== label ) td.dataset.label = label;
					const selection = td.classList.contains( 'el-table-column--selection' );
					const empty = ! td.textContent.trim() && ! td.querySelector( 'button,input,a,img,svg,.el-tag' );
					td.classList.toggle( 'bd-cell-empty', empty && ! selection );
					if ( ! selection && ! titled ) {
						td.classList.add( 'bd-cell-title' );
						titled = true;
					} else {
						td.classList.remove( 'bd-cell-title' );
					}
					td.classList.toggle( 'bd-cell-actions', ! selection && label === '' && i === cells.length - 1 && i > 0 );
				} );
			} );
		} );
	};
	const queue = () => {
		if ( queued || ! store.isMobile ) return;
		queued = true;
		window.requestAnimationFrame( enhance );
	};
	new MutationObserver( queue ).observe( root, { childList: true, subtree: true } );
	queue();

	window.addEventListener( 'beforeinstallprompt', ( e ) => {
		e.preventDefault();
		store.installPrompt = e;
	} );
	window.addEventListener( 'appinstalled', () => ( store.installPrompt = null ) );

	if ( workerUrl && 'serviceWorker' in navigator ) {
		window.addEventListener( 'load', () => navigator.serviceWorker.register( workerUrl, { scope: workerUrl.replace( /sw\.js.*$/, '' ) } ).catch( () => {} ) );
	}
}
