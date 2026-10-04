<script setup>
import { ref, computed, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { Edit } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import { store, navigate } from '../store';
import { sdk } from '../sdkInstance';
import RecordDrawer from '../components/RecordDrawer.vue';
import RemoteSelect from '../components/RemoteSelect.vue';
import StatusTag from '../components/StatusTag.vue';
import { groupFields } from '../resources';
import { avatarStyle, initials } from '../util';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );

const group = ref( null );
const error = ref( '' );
const edit = ref( false );
const adding = ref( [] );
const busy = ref( false );

const members = computed( () => ( group.value && group.value.members ) || [] );
const memberIds = computed( () => members.value.map( ( m ) => m.id ) );

const load = async () => {
	try {
		group.value = await api.get( 'groups', props.id );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
onMounted( load );

const basics = () => {
	const g = group.value;
	return { name: g.name, description: g.description || '', color: g.color || '', status: g.status };
};
const saveGroup = async ( values ) => {
	const payload = {};
	groupFields().forEach( ( f ) => ( payload[ f.name ] = values[ f.name ] ) );
	const res = await api.update( 'groups', props.id, payload );
	ElMessage.success( `${ sdk.t( 'group' ) } saved` );
	return res;
};

const saveMembers = async ( ids, message ) => {
	busy.value = true;
	try {
		await api.update( 'groups', props.id, { ...basics(), member_ids: ids } );
		ElMessage.success( message );
		adding.value = [];
		await load();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		busy.value = false;
	}
};
const addMembers = () => saveMembers( [ ...memberIds.value, ...adding.value ], sdk.fmt( '{Clients} added' ) );
const removeMember = ( m ) => saveMembers( memberIds.value.filter( ( x ) => x !== m.id ), sdk.fmt( '{Client} removed' ) );
</script>

<template>
	<div v-if="error && !group"><el-alert :title="error" type="error" show-icon :closable="false" /></div>
	<el-skeleton v-else-if="!group" :rows="6" animated />
	<div v-else class="bd-detail">
		<el-breadcrumb separator="/" class="bd-mb">
			<el-breadcrumb-item><a href="#/groups" @click.prevent="navigate( '/groups' )">{{ $tp( 'group' ) }}</a></el-breadcrumb-item>
			<el-breadcrumb-item>{{ group.name }}</el-breadcrumb-item>
		</el-breadcrumb>

		<div class="bd-pagehead">
			<div>
				<h1><span class="bd-groupdot" :style="{ background: group.color || '#6b7280' }" />{{ group.name }}</h1>
				<p v-if="group.description" class="bd-muted">{{ group.description }}</p>
				<div class="bd-chips"><StatusTag :status="group.status" /><span class="bd-badge bd-badge--neutral bd-badge--plain">{{ members.length }} {{ ( members.length === 1 ? $t( 'client' ) : $tp( 'client' ) ).toLowerCase() }}</span></div>
			</div>
			<div class="bd-pagehead__actions"><el-button v-if="store.caps.clients || store.canManage" :icon="Edit" @click="edit = true">{{ $fmt( 'Edit {group}' ) }}</el-button></div>
		</div>

		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>{{ $tp( 'client' ) }}</span></div></template>
			<div v-if="store.caps.clients || store.canManage" class="bd-addmembers">
				<RemoteSelect v-model="adding" multiple :exclude="memberIds" resource="clients" :label="( c ) => c.name" :params="{ status: 'active' }" :placeholder="$fmt( 'Add {clients}' )" aria-label="Add members" />
				<el-button type="primary" :disabled="!adding.length" :loading="busy" @click="addMembers">{{ $fmt( 'Add {clients}' ) }}</el-button>
			</div>
			<el-table :data="members" size="small">
				<template #empty><el-empty :description="$fmt( 'No {clients} in this {group} yet.' )" :image-size="72" /></template>
				<el-table-column :label="$t( 'client' )" min-width="200">
					<template #default="{ row }">
						<a class="bd-cell-client bd-link" :href="'#/clients/' + row.id"><span class="bd-ava bd-ava--sm" :style="avatarStyle( row.name )">{{ initials( row.name ) }}</span><span>{{ row.name }}</span></a>
					</template>
				</el-table-column>
				<el-table-column label="Email" min-width="200" prop="email" />
				<el-table-column v-if="store.caps.clients || store.canManage" label="" width="110" align="right">
					<template #default="{ row }"><el-button size="small" text type="danger" :disabled="busy" @click="removeMember( row )">Remove</el-button></template>
				</el-table-column>
			</el-table>
		</el-card>

		<RecordDrawer v-model="edit" :title="$fmt( 'Edit {group}' )" :fields="groupFields()" :record="group" :save="saveGroup" @saved="load" />
	</div>
</template>
