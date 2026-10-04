<script setup>
import { computed, ref, watch, watchEffect, onBeforeUnmount } from 'vue';
import { routeModule, store } from './store';
import { sdk } from './sdkInstance';
import Sidebar from './components/Sidebar.vue';
import TopBar from './components/TopBar.vue';
import Activity from './views/Activity.vue';
import ClientDetail from './views/ClientDetail.vue';
import Clients from './views/Clients.vue';
import GroupDetail from './views/GroupDetail.vue';
import Groups from './views/Groups.vue';
import Dashboard from './views/Dashboard.vue';
import DepartmentDetail from './views/DepartmentDetail.vue';
import DepartmentsOverview from './views/DepartmentsOverview.vue';
import Team from './views/Team.vue';
import TeamDetail from './views/TeamDetail.vue';
import Settings from './views/Settings.vue';
import ModuleOff from './views/ModuleOff.vue';
import Messages from './views/Messages.vue';
import Notifications from './views/Notifications.vue';
import { api } from './api';

const menuOpen = ref( false );
watch( () => store.route.path, () => ( menuOpen.value = false ) );

/** Core's own screens first; anything else is a module's screen (registered by its bundle) or "module is off". */
const view = computed( () => {
	const p = store.route.parts;
	const [ section, id ] = p;
	switch ( section ) {
		case 'notifications':
			return { c: Notifications, key: 'notifications' };
		case 'messages':
			return { c: Messages, key: 'messages', props: { threadId: Number( id ) || 0 } };
		case 'clients':
			return id ? { c: ClientDetail, key: 'client-' + id, props: { id } } : { c: Clients, key: 'clients' };
		case 'team':
			return id ? { c: TeamDetail, key: 'team-' + id, props: { id } } : { c: Team, key: 'team' };
		case 'departments':
			return id !== undefined && /^\d+$/.test( id ) ? { c: DepartmentDetail, key: 'dept-' + id, props: { id } } : { c: DepartmentsOverview, key: 'depts' };
		case 'groups':
			return id !== undefined && /^\d+$/.test( id ) ? { c: GroupDetail, key: 'group-' + id, props: { id } } : { c: Groups, key: 'groups' };
		case 'activity':
			return { c: Activity, key: 'activity' };
		case 'settings':
			return { c: Settings, key: 'settings-' + ( store.route.query.tab || '' ), props: { initialTab: store.route.query.tab || 'general' } };
		case undefined:
			return { c: Dashboard, key: 'dashboard' };
		default:
			break;
	}
	const hit = sdk.resolve( store.route.path );
	if ( hit ) {
		return { c: hit.route.component, key: hit.route.module + ':' + store.route.path, props: { ...hit.params, query: store.route.query } };
	}
	const mod = routeModule( section );
	if ( 'core' !== mod && ! store.enabled( mod ) ) {
		return { c: ModuleOff, key: 'off-' + mod, props: { module: mod } };
	}
	return { c: Dashboard, key: 'dashboard' };
} );

// Element Plus dark variables hang off html.dark; scope the toggle to this screen's lifetime.
watchEffect( () => {
	document.documentElement.classList.toggle( 'dark', store.theme === 'dark' );
} );
onBeforeUnmount( () => document.documentElement.classList.remove( 'dark' ) );

// Unread counts for the sidebar and the bell.
let unreadTimer = null;
const pollUnread = async () => {
	if ( document.hidden ) return;
	try {
		const r = await api.getPath( 'notifications/summary' );
		store.notifUnread = r.notifications || 0;
		store.unread = r.messages || 0;
	} catch ( e ) { /* the badges just stay as they were */ }
};
pollUnread();
unreadTimer = setInterval( pollUnread, 20000 );
onBeforeUnmount( () => clearInterval( unreadTimer ) );
</script>

<template>
	<div :class="[ 'bd-app', 'bd-shell', store.theme === 'dark' && 'dark', store.fullscreen && 'is-fullscreen' ]">
		<Sidebar :open="menuOpen" @close="menuOpen = false" />
		<div class="bd-shell__main">
			<TopBar @menu="menuOpen = !menuOpen" />
			<main class="bd-main">
				<component :is="view.c" :key="view.key" v-bind="view.props || {}" />
			</main>
		</div>
	</div>
</template>
