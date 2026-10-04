<script setup>
import { reactive, ref, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage, refreshMeta } from '../api';
import { store } from '../store';

const s = store.meta.settings;
const form = reactive( { notify_due_days: s.notify_due_days ?? 3, notify_overdue_every: s.notify_overdue_every || 7, notify_overdue_max: s.notify_overdue_max ?? 3 } );
const catalog = ref( [] );
const saving = ref( false );

onMounted( async () => {
	try {
		catalog.value = await api.getPath( 'notifications/catalog' );
	} catch ( e ) { /* the table stays empty */ }
} );

const groups = ( aud ) => {
	const g = {};
	catalog.value.filter( ( c ) => c.audience === aud ).forEach( ( c ) => ( g[ c.group ] = g[ c.group ] || [] ).push( c ) );
	return g;
};

async function save() {
	saving.value = true;
	try {
		await api.post( 'settings', { ...form, notify_disabled: catalog.value.filter( ( c ) => ! c.enabled ).map( ( c ) => c.type ) } );
		await refreshMeta();
		ElMessage.success( 'Notification settings saved.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
}
</script>

<template>
	<el-card shadow="never">
		<template #header><div class="bd-widget__head"><span>Notifications</span></div></template>
		<p class="bd-muted bd-small">Decide which events notify people at all. Each person can still choose, on their own Notifications page, how they get them (in the app, by email, or as a daily summary). Reminders go out once a day.</p>
		<el-form label-position="top" class="bd-mt">
			<el-row :gutter="16">
				<el-col :xs="24" :sm="8"><el-form-item label="Remind clients about things due in the next (days)"><el-input-number v-model="form.notify_due_days" :min="0" :max="30" /><div class="bd-help">0 turns "due soon" reminders off.</div></el-form-item></el-col>
				<el-col :xs="24" :sm="8"><el-form-item label="Repeat overdue reminders every (days)"><el-input-number v-model="form.notify_overdue_every" :min="1" :max="60" /></el-form-item></el-col>
				<el-col :xs="24" :sm="8"><el-form-item label="Send at most this many overdue reminders"><el-input-number v-model="form.notify_overdue_max" :min="0" :max="10" /><div class="bd-help">0 turns overdue reminders off.</div></el-form-item></el-col>
			</el-row>
		</el-form>
		<el-row :gutter="24">
			<el-col v-for="aud in [ 'client', 'team' ]" :key="aud" :xs="24" :md="12">
				<div class="bd-strong bd-mb-xs" style="margin-top: 8px">{{ aud === 'client' ? 'Notifications to clients' : 'Notifications to the team' }}</div>
				<div v-for="( list, g ) in groups( aud )" :key="g">
					<div class="bd-muted bd-small" style="margin: 8px 0 2px">{{ g }}</div>
					<div v-for="c in list" :key="c.type" class="bd-prefrow"><span class="bd-prefrow__l">{{ c.label }}</span><el-switch v-model="c.enabled" size="small" /></div>
				</div>
			</el-col>
		</el-row>
		<div class="bd-mt"><el-button type="primary" :loading="saving" @click="save">Save</el-button></div>
	</el-card>
</template>
