<script setup>
import { computed } from 'vue';
import { Activity, Boxes, Check, ChevronRight, Download, LogOut, Monitor, Moon, Settings, Sun, Home } from 'lucide-vue-next';
import { api } from '../api';
import { store, routeModule, navigate } from '../store';
import { sdk } from '../sdkInstance';

/** "Switch app": every app you can open, this app's other pages, then settings and the way out. */
const iconFor = ( name ) => ( { home: Home, settings: Settings, activity: Activity }[ name ] ) || sdk.state.icons[ name ] || Boxes;
const path = computed( () => ( store.route.path || '/' ).replace( /\/+$/, '' ) || '/' );
const current = computed( () => routeModule( path.value.split( '/' )[ 1 ] || '' ) );
const currentMod = computed( () => store.nav.modules.find( ( m ) => m.id === current.value ) );
const apps = computed( () => [ { id: 'core', label: ( store.nav.core[ 0 ] && store.nav.core[ 0 ].label ) || 'Home', icon: Home, route: '/' } ].concat( store.nav.modules.map( ( m ) => ( { id: m.id, label: m.label, icon: iconFor( m.icon ), route: m.route } ) ) ) );
const pages = computed( () => ( currentMod.value ? currentMod.value.items : store.nav.core.filter( ( i ) => i.route !== '/' ).concat( store.nav.footer.filter( ( i ) => i.route === '/activity' ) ) ).filter( ( i ) => i.route || i.url ) );
const pagesTitle = computed( () => ( currentMod.value ? currentMod.value.label + ' pages' : 'Pages' ) );

const close = () => ( store.appsOpen = false );
const open = ( a ) => {
	close();
	navigate( a.route );
};
const openPage = ( i ) => {
	close();
	if ( i.url ) {
		window.open( i.url, '_blank', 'noopener' );
	} else {
		navigate( i.route );
	}
};
const THEMES = [ { id: 'light', label: 'Light', icon: Sun }, { id: 'dark', label: 'Dark', icon: Moon }, { id: 'auto', label: 'System', icon: Monitor } ];
const theme = ( id ) => {
	store.setTheme( id );
	api.post( 'preferences', { theme: id } ).catch( () => {} );
};
const standalone = window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches;
const ios = /iphone|ipad|ipod/i.test( navigator.userAgent ) && ! standalone;
const install = async () => {
	const p = store.installPrompt;
	if ( p ) {
		p.prompt();
		await p.userChoice.catch( () => {} );
		store.installPrompt = null;
	}
};
</script>

<template>
	<el-drawer :model-value="store.appsOpen" direction="btt" size="auto" :with-header="false" append-to-body class="bd-sheet" @update:model-value="store.appsOpen = $event">
		<div class="bd-sheet__grab" aria-hidden="true" />
		<div class="bd-sheet__head"><h2>Switch app</h2><button type="button" class="bd-sheet__done" @click="close">Done</button></div>
		<p class="bd-sheet__hint">The tabs along the bottom change to match the app you pick.</p>
		<div class="bd-apps">
			<button v-for="a in apps" :key="a.id" type="button" :class="[ 'bd-app-tile', a.id === current && 'is-current' ]" @click="open( a )">
				<span class="bd-app-tile__icon"><component :is="a.icon" :size="24" :stroke-width="1.9" /></span>
				<span class="bd-app-tile__label">{{ a.label }}</span>
				<span v-if="a.id === current" class="bd-app-tile__cur">Current</span>
			</button>
		</div>
		<div v-if="pages.length" class="bd-sheet__group">
			<div class="bd-sheet__title">{{ pagesTitle }}</div>
			<button v-for="i in pages" :key="i.label" type="button" class="bd-sheet__row" @click="openPage( i )"><span>{{ i.label }}</span><ChevronRight :size="16" :stroke-width="2" /></button>
		</div>
		<div class="bd-sheet__group">
			<button v-for="i in store.nav.footer" :key="i.route" type="button" class="bd-sheet__row" @click="openPage( i )"><span>{{ i.label }}</span><ChevronRight :size="16" :stroke-width="2" /></button>
			<div class="bd-sheet__row bd-sheet__row--theme"><span>Appearance</span>
				<div class="bd-segmini"><button v-for="t in THEMES" :key="t.id" type="button" :class="{ 'is-on': store.themePref === t.id }" :aria-label="t.label" @click="theme( t.id )"><component :is="t.icon" :size="16" :stroke-width="1.9" /></button></div>
			</div>
			<button v-if="store.installPrompt" type="button" class="bd-sheet__row" @click="install"><span>Install this app on your phone</span><Download :size="16" :stroke-width="2" /></button>
			<div v-else-if="ios" class="bd-sheet__row bd-sheet__row--note">Install it: tap Share, then “Add to Home Screen”.</div>
			<a v-if="store.logoutUrl" class="bd-sheet__row bd-sheet__row--out" :href="store.logoutUrl"><span>Sign out{{ store.user ? ' (' + store.user + ')' : '' }}</span><LogOut :size="16" :stroke-width="2" /></a>
		</div>
	</el-drawer>
</template>
