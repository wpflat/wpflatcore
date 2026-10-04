<script setup>
import { ref, reactive, onMounted, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import { store } from '../store';
import PageHeader from '../components/PageHeader.vue';
import { openNotification } from '../notifications';

const tab = ref( 'list' );
const filter = ref( 'all' );
const items = ref( [] );
const loading = ref( true );
const more = ref( true );
const prefs = ref( [] );
const saving = ref( false );

const toDate = ( t ) => new Date( String( t ).replace( ' ', 'T' ) + 'Z' );
const when = ( t ) => toDate( t ).toLocaleString( [], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } );

async function load( reset = true ) {
	loading.value = true;
	try {
		const before = ! reset && items.value.length ? items.value[ items.value.length - 1 ].id : '';
		const r = await api.getPath( 'notifications', { filter: filter.value, limit: 30, before } );
		items.value = reset ? r.items : items.value.concat( r.items );
		more.value = r.items.length === 30;
		store.notifUnread = r.unread;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
}
async function readAll() {
	const r = await api.post( 'notifications/read', { all: true } );
	store.notifUnread = r.unread;
	items.value.forEach( ( i ) => { i.read = true; } );
}
async function loadPrefs() {
	prefs.value = ( await api.getPath( 'notifications/prefs' ) ).prefs;
}
async function savePrefs() {
	saving.value = true;
	try {
		const body = {};
		prefs.value.forEach( ( p ) => { body[ p.type ] = { app: p.app, email: p.email }; } );
		prefs.value = ( await api.post( 'notifications/prefs', { prefs: body } ) ).prefs;
		ElMessage.success( 'Notification settings saved.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
}
const groups = () => {
	const g = {};
	prefs.value.forEach( ( p ) => ( g[ p.group ] = g[ p.group ] || [] ).push( p ) );
	return g;
};

onMounted( () => { load(); loadPrefs(); } );
watch( filter, () => load() );
</script>

<template>
	<div>
		<PageHeader title="Notifications" description="What happened, and how you want to hear about it." />
		<el-tabs v-model="tab" class="bd-tabs">
			<el-tab-pane label="Notifications" name="list">
				<div class="bd-toolbar">
					<el-radio-group v-model="filter" size="default"><el-radio-button value="all">All</el-radio-button><el-radio-button value="unread">Unread</el-radio-button></el-radio-group>
					<el-button v-if="store.notifUnread" class="bd-import-btn" @click="readAll">Mark all as read</el-button>
				</div>
				<el-card shadow="never">
					<div v-loading="loading && !items.length" style="min-height: 80px">
						<div v-if="!items.length && !loading" class="bd-muted" style="padding: 24px; text-align: center">{{ filter === 'unread' ? 'Nothing unread.' : 'No notifications yet. They appear here when something happens: a task is assigned, an invoice is paid, a contract is signed.' }}</div>
						<button v-for="n in items" :key="n.id" type="button" :class="[ 'bd-np__item', 'bd-np__item--page', { 'is-unread': !n.read } ]" @click="openNotification( n ); n.read = true">
							<span class="bd-np__dot" />
							<span class="bd-np__body"><span class="bd-np__t">{{ n.title }}</span><span v-if="n.body" class="bd-np__s">{{ n.body }}</span><span class="bd-np__m">{{ n.client_name ? n.client_name + ' · ' : '' }}{{ n.group }} · {{ when( n.at ) }}</span></span>
						</button>
					</div>
					<div v-if="more && items.length" style="text-align: center; padding-top: 10px"><el-button :loading="loading" @click="load( false )">Load more</el-button></div>
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Settings" name="prefs">
				<el-card shadow="never">
					<p class="bd-muted bd-small">Choose, for each kind of event, whether it shows in the bell and whether it is emailed straight away, in one daily summary, or not at all.</p>
					<div v-for="( list, g ) in groups()" :key="g" class="bd-mt">
						<div class="bd-strong bd-mb-xs">{{ g }}</div>
						<div v-for="p in list" :key="p.type" class="bd-prefrow">
							<span class="bd-prefrow__l">{{ p.label }}</span>
							<el-switch v-model="p.app" size="small" active-text="In app" />
							<el-select v-if="!p.external_email" v-model="p.email" size="small" style="width: 150px"><el-option value="instant" label="Email: instantly" /><el-option value="digest" label="Email: daily summary" /><el-option value="off" label="Email: off" /></el-select>
							<span v-else class="bd-muted bd-small" style="width: 150px">Emailed separately</span>
						</div>
					</div>
					<div class="bd-mt"><el-button type="primary" :loading="saving" @click="savePrefs">Save</el-button></div>
				</el-card>
			</el-tab-pane>
		</el-tabs>
	</div>
</template>
