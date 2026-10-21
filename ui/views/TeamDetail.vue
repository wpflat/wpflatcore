<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { Edit } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import RecordDrawer from '../components/RecordDrawer.vue';
import SlotOutlet from '../components/SlotOutlet.vue';
import StatusTag from '../components/StatusTag.vue';
import TeamAccess from '../components/TeamAccess.vue';
import LinkedRecords from '../components/LinkedRecords.vue';
import { avatarStyle, fmtDate, initials } from '../util';
import { linkMeWithCode } from '../linkMe';
import { teamFields } from '../resources';
import { store } from '../store';
import { sdk } from '../sdkInstance';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const member = ref( null );
const error = ref( '' );
const tab = ref( 'overview' );
const key = ref( 0 );
const editing = ref( false );

// What modules see when they add to this page.
const ctx = computed( () => ( { member: member.value, memberId: Number( props.id ), tick: key.value, refresh: () => key.value++, goTab: ( t ) => ( tab.value = t ) } ) );
const tabs = computed( () => sdk.slot( 'team.tabs', ctx.value ) );
const hasStats = computed( () => sdk.slot( 'team.stats', ctx.value ).length > 0 );
const details = computed( () => sdk.extensions( 'team.details', ctx.value ) );

const load = async () => {
	try {
		member.value = await api.get( 'team', props.id );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
onMounted( load );
watch( key, load );

const save = ( values ) => {
	const body = {};
	teamFields().forEach( ( f ) => ( body[ f.name ] = values[ f.name ] ) );
	return api.update( 'team', props.id, body );
};
const saved = () => {
	ElMessage.success( 'Saved' );
	load();
};
const linkMe = () => linkMeWithCode( member.value, load );
const kindLabel = computed( () => ( member.value && member.value.kind === 'contractor' ? 'Contractor' : 'Employee' ) );
</script>

<template>
	<div v-if="error && !member"><el-alert :title="error" type="error" show-icon :closable="false" /></div>
	<el-skeleton v-else-if="!member" :rows="8" animated />
	<div v-else class="bd-detail">
		<el-breadcrumb separator="/" class="bd-mb">
			<el-breadcrumb-item><a href="#/team">{{ $tp( 'team' ) }}</a></el-breadcrumb-item>
			<el-breadcrumb-item>{{ member.name }}</el-breadcrumb-item>
		</el-breadcrumb>

		<div class="bd-hero">
			<span class="bd-ava bd-ava--lg" :style="avatarStyle( member.name )">{{ initials( member.name ) }}</span>
			<div class="bd-hero__main">
				<h1>{{ member.name }}</h1>
				<div class="bd-chips">
					<span class="bd-muted">{{ member.role || kindLabel }}</span>
					<span class="bd-badge bd-badge--neutral bd-badge--plain">{{ kindLabel }}</span>
					<span v-if="member.department_name" class="bd-badge bd-badge--info bd-badge--plain">{{ member.department_name }}</span>
					<StatusTag :status="member.status" />
					<span v-if="member.is_me" class="bd-badge bd-badge--success bd-badge--plain">This is you</span>
				</div>
			</div>
			<SlotOutlet name="team.actions" :ctx="ctx" />
			<el-button :icon="Edit" @click="editing = true">Edit</el-button>
		</div>

		<el-row v-if="hasStats" :gutter="16" class="bd-mb">
			<SlotOutlet name="team.stats" :ctx="ctx" />
		</el-row>

		<el-tabs v-model="tab" class="bd-tabs">
			<el-tab-pane label="Overview" name="overview">
				<el-row :gutter="20"><SlotOutlet name="team.overview" :ctx="ctx" /></el-row>
				<LinkedRecords type="team_member" :id="member.id" title="Linked records" />
			</el-tab-pane>

			<el-tab-pane v-for="t in tabs" :key="t.id" :label="t.label" :name="t.id" lazy>
				<component :is="t.component" v-bind="ctx" />
			</el-tab-pane>

			<el-tab-pane label="Details" name="details">
				<el-card shadow="never" class="bd-mb">
					<el-descriptions :column="1" size="small" border>
						<el-descriptions-item label="Email">{{ member.email }}</el-descriptions-item>
						<el-descriptions-item label="Type">{{ kindLabel }}</el-descriptions-item>
						<el-descriptions-item v-if="member.start_date" label="Started">{{ fmtDate( member.start_date ) }}</el-descriptions-item>
						<el-descriptions-item label="Document emails">{{ member.cc_documents ? 'Copied on every document' : 'Not copied' }}</el-descriptions-item>
						<el-descriptions-item v-for="d in details" :key="d.id" :label="d.label">{{ d.value( member ) }}</el-descriptions-item>
						<el-descriptions-item label="Login">{{ member.is_me ? 'This is you' : member.wp_user_id ? 'Linked to a login' : 'Not linked to a login' }} <a v-if="!member.is_me" href="#" class="bd-link" @click.prevent="linkMe">This is me</a></el-descriptions-item>
						<el-descriptions-item v-if="member.notes" label="Notes">{{ member.notes }}</el-descriptions-item>
					</el-descriptions>
				</el-card>
				<TeamAccess v-if="store.caps.team" :member-id="id" :has-email="!!member.email" @changed="load" />
			</el-tab-pane>
		</el-tabs>

		<RecordDrawer v-model="editing" title="Edit" :fields="teamFields()" :record="member" :save="save" size="560px" @saved="saved" />
	</div>
</template>
