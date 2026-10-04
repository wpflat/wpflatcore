import { reactive } from 'vue';

const boot = window.bizycoreBoot || {};

function parseHash() {
	const raw = ( window.location.hash || '#/' ).slice( 1 ) || '/';
	const [ path, qs = '' ] = raw.split( '?' );
	return {
		path,
		parts: path.split( '/' ).filter( Boolean ),
		query: Object.fromEntries( new URLSearchParams( qs ) ),
	};
}

export const store = reactive( {
	meta: boot.meta || { settings: {}, lookups: {}, enums: {} },
	nav: boot.nav && boot.nav.core ? boot.nav : { core: [], modules: [], footer: [] },
	modules: boot.modules || [],
	coreAnalytics: boot.coreAnalytics || {},
	frontend: !! boot.frontend,
	fullscreen: !! boot.fullscreen,
	adminUrl: boot.adminUrl || '',
	appUrl: boot.appUrl || '',
	logoutUrl: boot.logoutUrl || '',
	canManage: !! boot.canManage,
	caps: boot.caps || {},
	wpAdmin: !! boot.wpAdmin,
	unread: 0,
	notifUnread: 0,
	freshInstall: !! boot.freshInstall,
	demoLoaded: !! boot.demoLoaded,
	backupAlert: boot.backupAlert || '',
	staffOnly: !! boot.staffOnly,
	user: boot.user || '',
	userEmail: boot.userEmail || '',
	userId: Number( boot.userId ) || 0,
	homeUrl: boot.homeUrl || '/',
	themePref: [ 'light', 'dark', 'auto' ].includes( boot.theme ) ? boot.theme : 'light', // what the person chose
	theme: 'light', // what is shown right now: light | dark
	route: parseHash(),
} );

const systemDark = () => !! ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches );
const resolveTheme = ( pref ) => ( pref === 'auto' ? ( systemDark() ? 'dark' : 'light' ) : pref );
store.theme = resolveTheme( store.themePref );
if ( window.matchMedia ) {
	// "System" follows the device live, e.g. when it switches to dark at sunset.
	window.matchMedia( '(prefers-color-scheme: dark)' ).addEventListener( 'change', () => {
		if ( store.themePref === 'auto' ) {
			store.theme = resolveTheme( 'auto' );
		}
	} );
}
/** Choose Light, Dark or System. The sign-in page reads the same choice. */
store.setTheme = ( pref ) => {
	store.themePref = pref;
	store.theme = resolveTheme( pref );
	try {
		window.localStorage.setItem( 'bd-theme', pref === 'auto' ? 'auto' : pref );
	} catch ( e ) {}
};
store.setTheme( store.themePref );

/** Is this module switched on? (Core is always on.) */
store.enabled = ( id ) => id === 'core' || store.nav.modules.some( ( m ) => m.id === id );

/** Which module an in-app route belongs to (each module lists the first path segments it owns). */
export const routeModule = ( first ) => {
	const hit = ( store.modules || [] ).find( ( m ) => ( m.routes || [] ).includes( first ) );
	return hit ? hit.id : 'core';
};

// Opening from a WordPress menu entry (e.g. "Bizy Tasks") lands on that module's overview.
if ( ! window.location.hash && boot.initialRoute && boot.initialRoute !== '/' ) {
	window.location.hash = boot.initialRoute;
	store.route = parseHash();
}

window.addEventListener( 'hashchange', () => {
	store.route = parseHash();
} );

export const navigate = ( to ) => {
	window.location.hash = to;
};
