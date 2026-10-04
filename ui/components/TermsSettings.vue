<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import { sdk } from '../sdkInstance';

/**
 * "What do you call things?" Clients can be Customers, Students, Learners, Employees, Contractors…; Departments and
 * the Team can be renamed too, and each module adds its own words. A change updates the whole app after a reload.
 */
const data = ref( null );
const words = reactive( {} );
const saving = ref( false );
const LABELS = { client: 'The people or companies you serve', team: 'The people who work with you', department: 'How you group your work', group: 'Sets of people that belong together — a class, cohort or batch', project: 'Projects', task: 'Tasks', invoice: 'Invoices' };

const load = async () => {
	try {
		data.value = await api.getPath( 'terminology' );
		Object.entries( data.value.words ).forEach( ( [ k, w ] ) => ( words[ k ] = { s: w.s, p: w.p } ) );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
onMounted( load );

const presets = ( key ) => ( data.value && data.value.presets[ key ] ) || [];
const apply = ( key, p ) => ( words[ key ] = { s: p.s, p: p.p } );
const save = async () => {
	saving.value = true;
	try {
		await api.post( 'terminology', { words: JSON.parse( JSON.stringify( words ) ) } );
		ElMessage.success( 'Saved — reloading…' );
		setTimeout( () => window.location.reload(), 600 );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
		saving.value = false;
	}
};
const keys = () => Object.keys( words ).filter( ( k ) => k !== 'home' );
</script>

<template>
	<el-card v-if="data" shadow="never">
		<p class="bd-muted bd-mb">Choose the words that fit your business. Everything you and your {{ sdk.tp( 'client' ).toLowerCase() }} read — the app, the portal, emails and documents — follows them.</p>
		<el-form label-position="top" style="max-width: 760px" @submit.prevent>
			<div v-for="k in keys()" :key="k" class="bd-mb">
				<div class="bd-strong" style="text-transform: capitalize">{{ k.replace( '_', ' ' ) }} <span class="bd-muted bd-small">— {{ LABELS[ k ] || 'Used across the app' }}</span></div>
				<div v-if="presets( k ).length" class="bd-chips bd-mt">
					<el-button v-for="p in presets( k )" :key="p.name" size="small" round :type="words[ k ].p === p.p ? 'primary' : 'default'" @click="apply( k, p )">{{ p.name }}</el-button>
				</div>
				<el-row :gutter="12" class="bd-mt">
					<el-col :xs="12"><el-form-item label="One"><el-input v-model="words[ k ].s" maxlength="40" /></el-form-item></el-col>
					<el-col :xs="12"><el-form-item label="Many"><el-input v-model="words[ k ].p" maxlength="40" /></el-form-item></el-col>
				</el-row>
			</div>
		</el-form>
		<el-button type="primary" :loading="saving" @click="save">Save words</el-button>
	</el-card>
</template>
