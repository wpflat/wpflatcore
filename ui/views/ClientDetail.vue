<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { Edit } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import { store } from '../store';
import { sdk } from '../sdkInstance';
import PageHeader from '../components/PageHeader.vue';
import RecordDrawer from '../components/RecordDrawer.vue';
import SlotOutlet from '../components/SlotOutlet.vue';
import StatusTag from '../components/StatusTag.vue';
import LinkedRecords from '../components/LinkedRecords.vue';
import { clientFields } from '../resources';
import { avatarStyle, fmtDateTime, initials, labelOf, colorOf } from '../util';
import ClientContacts from './ClientContacts.vue';
import ClientPortalCard from '../components/ClientPortalCard.vue';
import Messages from './Messages.vue';

const props = defineProps( { id: { type: String, required: true } } );

const client = ref( null );
const error = ref( '' );
const tick = ref( 0 );
const tab = ref( 'overview' );
const edit = reactive( { open: false } );
const history = ref( [] );
const allHistory = ref( false );
const shownHistory = computed( () => ( allHistory.value ? history.value : history.value.slice( 0, 8 ) ) );

// What modules see when they add to this page.
const ctx = computed( () => ( { client: client.value, clientId: Number( props.id ), tick: tick.value, refresh: () => tick.value++, goTab: ( t ) => ( tab.value = t ) } ) );
const tabs = computed( () => sdk.slot( 'client.tabs', ctx.value ) );
const hasStats = computed( () => sdk.slot( 'client.stats', ctx.value ).length > 0 );

const load = async () => {
	try {
		const [ c, act ] = await Promise.all( [ api.get( 'clients', props.id ), api.list( 'activity', { client_id: props.id, per_page: 30 } ) ] );
		client.value = c;
		history.value = act.items;
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
onMounted( load );
watch( tick, load );

const refresh = () => tick.value++;

const saveClient = async ( values ) => {
	const payload = {};
	clientFields().forEach( ( f ) => ( payload[ f.name ] = values[ f.name ] ) );
	const res = await api.update( 'clients', props.id, payload );
	ElMessage.success( `${ sdk.t( 'client' ) } saved` );
	return res;
};
</script>

<template>
	<div v-if="error && !client"><el-alert :title="error" type="error" show-icon :closable="false" /></div>
	<el-skeleton v-else-if="!client" :rows="8" animated />
	<div v-else class="bd-detail">
		<el-breadcrumb separator="/" class="bd-mb">
			<el-breadcrumb-item><a href="#/clients">{{ $tp( 'client' ) }}</a></el-breadcrumb-item>
			<el-breadcrumb-item>{{ client.name }}</el-breadcrumb-item>
		</el-breadcrumb>

		<div class="bd-hero">
			<span class="bd-ava bd-ava--lg" :style="avatarStyle( client.name )">{{ initials( client.name ) }}</span>
			<div class="bd-hero__main">
				<h1>{{ client.name }}</h1>
				<div class="bd-chips">
					<span class="bd-badge bd-badge--plain" :style="colorOf( 'client_type', client.type ) ? { color: colorOf( 'client_type', client.type ), background: colorOf( 'client_type', client.type ) + '1a' } : undefined">{{ labelOf( 'client_type', client.type ) }}</span>
					<StatusTag :status="client.status" />
					<span v-for="b in client.departments" :key="b.id" class="bd-badge bd-badge--neutral bd-badge--plain">{{ b.name }}</span>
					<a v-for="g in client.groups || []" :key="'g' + g.id" class="bd-badge bd-badge--plain bd-groupchip" :href="'#/groups/' + g.id" :style="g.color ? { color: g.color, background: g.color + '1a' } : undefined">{{ g.name }}</a>
				</div>
			</div>
			<SlotOutlet name="client.actions" :ctx="ctx" />
			<el-button :icon="Edit" @click="edit.open = true">{{ $fmt( 'Edit {client}' ) }}</el-button>
		</div>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />

		<el-row v-if="hasStats" :gutter="16" class="bd-mb">
			<SlotOutlet name="client.stats" :ctx="ctx" />
		</el-row>

		<el-tabs v-model="tab" class="bd-tabs">
			<el-tab-pane label="Overview" name="overview">
				<div class="bd-ovgrid"><SlotOutlet name="client.overview" :ctx="ctx" /></div>
				<el-card shadow="never" class="bd-widget bd-mb bd-people">
					<template #header><div class="bd-widget__head"><span>People</span><a href="#" @click.prevent="tab = 'contacts'">Manage contacts</a></div></template>
					<ul class="bd-list">
						<li v-for="c in client.contacts" :key="c.id">
							<div><div class="bd-strong">{{ c.name }} <span class="bd-badge bd-badge--plain" :class="c.kind === 'primary' ? 'bd-badge--info' : 'bd-badge--neutral'">{{ c.kind === 'primary' ? 'Gets documents' : 'Copied' }}</span></div><div class="bd-muted bd-small">{{ [ c.job_title, c.email ].filter( Boolean ).join( ' · ' ) }}</div></div>
						</li>
						<li v-if="!client.contacts.length" class="bd-muted">No contacts yet.</li>
					</ul>
				</el-card>
				<el-card v-if="client.notes || client.address" shadow="never" class="bd-mb">
					<el-descriptions :column="1" size="small">
						<el-descriptions-item v-if="client.address" label="Address">{{ client.address }}</el-descriptions-item>
						<el-descriptions-item v-if="client.notes" label="Notes">{{ client.notes }}</el-descriptions-item>
					</el-descriptions>
				</el-card>
				<LinkedRecords type="client" :id="client.id" title="Linked records" />
			</el-tab-pane>

			<el-tab-pane v-for="t in tabs" :key="t.id" :label="t.label" :name="t.id" lazy>
				<component :is="t.component" v-bind="ctx" />
			</el-tab-pane>

			<el-tab-pane name="contacts" lazy>
				<template #label>Contacts<span v-if="client.contacts && client.contacts.length" class="bd-tabcount">{{ client.contacts.length }}</span></template>
				<ClientContacts :client-id="id" :reload-key="tick" @changed="refresh" />
				<ClientPortalCard :client-id="id" :reload-key="tick" />
			</el-tab-pane>

			<el-tab-pane v-if="store.caps.messages" label="Messages" name="messages" lazy>
				<Messages :client-id="id" embedded />
			</el-tab-pane>

			<el-tab-pane label="History" name="history" lazy>
				<SlotOutlet name="client.history" :ctx="ctx" />
				<h3 class="bd-subhead">Activity</h3>
				<el-card shadow="never" class="bd-mb">
					<ul v-if="history.length" class="bd-activity">
						<li v-for="a in shownHistory" :key="a.id"><span>{{ a.summary }}</span><time>{{ fmtDateTime( a.created_at ) }}</time></li>
					</ul>
					<el-empty v-else description="No activity yet." :image-size="72" />
					<div v-if="history.length > 8" class="bd-mt"><el-button text type="primary" @click="allHistory = !allHistory">{{ allHistory ? 'Show fewer' : `Show all ${ history.length }` }}</el-button></div>
				</el-card>
			</el-tab-pane>
		</el-tabs>

		<RecordDrawer v-model="edit.open" :title="$fmt( 'Edit {client}' )" :fields="clientFields()" :record="client" :save="saveClient" @saved="refresh" />
	</div>
</template>
