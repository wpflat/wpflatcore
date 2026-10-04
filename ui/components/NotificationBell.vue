<script setup>
import { ref } from 'vue';
import { Bell, CheckCheck } from 'lucide-vue-next';
import { api } from '../api';
import { store, navigate } from '../store';
import { fmtDateTime } from '../util';
import { openNotification } from '../notifications';

const items = ref( [] );
const loading = ref( false );
const open = ref( false );

const toDate = ( t ) => new Date( String( t ).replace( ' ', 'T' ) + 'Z' );
const ago = ( t ) => {
	const s = ( Date.now() - toDate( t ).getTime() ) / 1000;
	return s < 60 ? 'now' : s < 3600 ? Math.floor( s / 60 ) + 'm' : s < 86400 ? Math.floor( s / 3600 ) + 'h' : Math.floor( s / 86400 ) + 'd';
};

async function load() {
	loading.value = true;
	try {
		const r = await api.getPath( 'notifications', { limit: 12 } );
		items.value = r.items;
		store.notifUnread = r.unread;
	} catch ( e ) { /* leave the list as it was */ } finally {
		loading.value = false;
	}
}
async function readAll() {
	try {
		const r = await api.post( 'notifications/read', { all: true } );
		store.notifUnread = r.unread;
		items.value.forEach( ( i ) => { i.read = true; } );
	} catch ( e ) { /* ignore */ }
}
async function go( n ) {
	open.value = false;
	await openNotification( n );
	n.read = true;
}
function viewAll() {
	open.value = false;
	navigate( '/notifications' );
}
</script>

<template>
	<el-popover v-model:visible="open" trigger="click" placement="bottom-end" :width="380" popper-class="bd-notifpop" :show-arrow="false" @show="load">
		<template #reference>
			<button type="button" class="bd-iconbtn bd-bell" aria-label="Notifications">
				<el-icon :size="18"><Bell :stroke-width="1.75" /></el-icon>
				<span v-if="store.notifUnread" class="bd-bell__badge">{{ store.notifUnread > 99 ? '99+' : store.notifUnread }}</span>
			</button>
		</template>
		<div class="bd-np">
			<div class="bd-np__head"><strong>Notifications</strong><button v-if="store.notifUnread" type="button" class="bd-np__link" @click="readAll"><CheckCheck :size="14" /> Mark all read</button></div>
			<div v-loading="loading" class="bd-np__list">
				<div v-if="!items.length && !loading" class="bd-np__empty">You are all caught up.</div>
				<button v-for="n in items" :key="n.id" type="button" :class="[ 'bd-np__item', { 'is-unread': !n.read } ]" @click="go( n )">
					<span class="bd-np__dot" />
					<span class="bd-np__body"><span class="bd-np__t">{{ n.title }}</span><span v-if="n.body" class="bd-np__s">{{ n.body }}</span><span class="bd-np__m">{{ n.client_name ? n.client_name + ' · ' : '' }}{{ n.group }} · {{ ago( n.at ) }}</span></span>
				</button>
			</div>
			<div class="bd-np__foot"><button type="button" class="bd-np__link" @click="viewAll">View all and settings</button></div>
		</div>
	</el-popover>
</template>
