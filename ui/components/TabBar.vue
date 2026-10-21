<script setup>
import { computed } from 'vue';
import { BarChart3, CheckSquare, ClipboardList, FileSignature, Folder, Home, LayoutGrid, MessageSquare, Plus, Receipt, Users, UsersRound, Wallet, Circle } from 'lucide-vue-next';
import { store, routeModule, navigate } from '../store';
import { sdk } from '../sdkInstance';

/** The phone's bottom bar. It follows the app you are in: Home shows Core's pages, a module shows its own. The last tab, Apps, switches app. */
const CORE_ICONS = { '/': Home, '/clients': Users, '/team': UsersRound, '/messages': MessageSquare };
const guess = ( label ) => {
	const l = String( label ).toLowerCase();
	if ( /overview|home|dashboard|analytics/.test( l ) ) return BarChart3;
	if ( /invoice|billing|bill|payment/.test( l ) ) return Receipt;
	if ( /expense|payroll|pay\b/.test( l ) ) return Wallet;
	if ( /task/.test( l ) ) return CheckSquare;
	if ( /project|file|folder|explorer/.test( l ) ) return Folder;
	if ( /contract|document|template|sign/.test( l ) ) return FileSignature;
	if ( /form/.test( l ) ) return ClipboardList;
	if ( /client|customer|student/.test( l ) ) return Users;
	return Circle;
};

const path = computed( () => ( store.route.path || '/' ).replace( /\/+$/, '' ) || '/' );
const moduleId = computed( () => routeModule( path.value.split( '/' )[ 1 ] || '' ) );
const mod = computed( () => store.nav.modules.find( ( m ) => m.id === moduleId.value ) );
const quick = computed( () => sdk.quickFor( mod.value ? mod.value.id : 'core' ).filter( ( a ) => ! a.when || a.when() ) );

/** Pages worth a tab: not settings, analytics or other occasional ones (those stay under Apps). */
const SKIP = /setting|analytic|template|payroll|catalog/i;
const tabs = computed( () => {
	let list;
	if ( mod.value ) {
		list = mod.value.items.filter( ( i ) => ! i.url && i.route );
		const main = list.filter( ( i ) => ! SKIP.test( i.label ) );
		list = ( main.length > 1 ? main : list ).map( ( i ) => ( { label: i.label, route: i.route, icon: guess( i.label ) } ) );
	} else {
		list = [ { label: store.nav.core[ 0 ] ? store.nav.core[ 0 ].label : 'Home', route: '/', icon: Home } ].concat(
			store.nav.core.filter( ( i ) => i.route === '/clients' || i.route === '/team' ).map( ( i ) => ( { label: i.label, route: i.route, icon: CORE_ICONS[ i.route ] } ) ),
			[ { label: 'Messages', route: '/messages', icon: MessageSquare, badge: store.unread } ]
		);
	}
	return list.slice( 0, quick.value.length ? 3 : 4 );
} );

/** The most specific tab wins, so Invoices lights up on an invoice and not Overview. */
const active = computed( () => {
	let best = null;
	for ( const t of tabs.value ) {
		const hit = t.route === '/' ? path.value === '/' : path.value === t.route || path.value.startsWith( t.route + '/' );
		if ( hit && ( ! best || t.route.length > best.route.length ) ) best = t;
	}
	return best;
} );

const entries = computed( () => {
	const out = tabs.value.map( ( t ) => ( { ...t, kind: 'tab' } ) );
	if ( quick.value.length ) out.splice( Math.min( 2, out.length ), 0, { kind: 'add', label: 'New' } );
	out.push( { kind: 'apps', label: 'Apps', icon: LayoutGrid } );
	return out;
} );

const go = ( e ) => {
	if ( e.kind === 'tab' ) {
		store.appsOpen = false;
		navigate( e.route );
	} else if ( e.kind === 'apps' ) {
		store.appsOpen = true;
	} else if ( quick.value.length === 1 ) {
		navigate( quick.value[ 0 ].route );
	} else {
		store.quickOpen = true;
	}
};
</script>

<template>
	<nav class="bd-tabbar" aria-label="Main">
		<button v-for="e in entries" :key="e.kind + e.label" type="button" :class="[ 'bd-tab', e.kind === 'add' && 'bd-tab--add', e.kind === 'tab' && active && active.route === e.route && 'is-active', e.kind === 'apps' && store.appsOpen && 'is-active' ]" :aria-label="e.kind === 'add' ? ( quick.length === 1 ? quick[ 0 ].label : 'Create new' ) : e.label" :aria-current="e.kind === 'tab' && active && active.route === e.route ? 'page' : undefined" @click="go( e )">
			<span v-if="e.kind === 'add'" class="bd-tab__plus"><Plus :size="26" :stroke-width="2.4" /></span>
			<template v-else>
				<span class="bd-tab__icon"><component :is="e.icon" :size="24" :stroke-width="e.kind === 'tab' && active && active.route === e.route ? 2.1 : 1.8" /><i v-if="e.badge" class="bd-tab__badge">{{ e.badge > 9 ? '9+' : e.badge }}</i></span>
				<span class="bd-tab__label">{{ e.label }}</span>
			</template>
		</button>
	</nav>
</template>
