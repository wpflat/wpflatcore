<script setup>
import { ref, watch, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';

const props = defineProps( { memberId: { type: [ String, Number ], required: true }, hasEmail: Boolean } );
const emit = defineEmits( [ 'changed' ] );

const info = ref( null );
const role = ref( '' );
const modules = ref( [] );
const invite = ref( true );
const sending = ref( false );
const saving = ref( false );

async function load() {
	try {
		info.value = await api.getPath( `team/${ props.memberId }/access` );
		role.value = info.value.role;
		modules.value = info.value.modules || [];
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

async function resend() {
	sending.value = true;
	try {
		info.value = await api.post( `team/${ props.memberId }/invite`, {} );
		ElMessage.success( 'Invite sent to ' + ( info.value.email || 'them' ) + '.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		sending.value = false;
	}
}

async function save() {
	const wasLogin = !! ( info.value && info.value.has_login );
	saving.value = true;
	try {
		info.value = await api.post( `team/${ props.memberId }/access`, { role: role.value, modules: modules.value, invite: invite.value && ! info.value.has_login && ! info.value.invited } );
		if ( info.value.invite_error ) ElMessage.warning( 'Access saved, but the invite could not be sent: ' + info.value.invite_error );
		else ElMessage.success( role.value ? ( invite.value && ! wasLogin && info.value.invited ? 'Role saved and invite sent.' : 'Saved.' ) : 'Access removed.' );
		emit( 'changed' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
}

onMounted( load );
watch( () => props.memberId, load );
</script>

<template>
	<el-card v-if="info" shadow="never" class="bd-mb">
		<template #header><div class="bd-widget__head"><span>Access</span></div></template>
		<el-alert v-if="info.is_wp_admin" type="info" show-icon :closable="false" title="This person is a WordPress administrator and already has full access." />
		<template v-else>
			<p class="bd-muted bd-small">What this person can open in the app. {{ info.has_login ? 'Login: ' + info.login + '.' : ( info.pending ? 'Waiting for them to accept the invite.' : 'They do not have a login yet. Choose a role and invite them: they pick their own username and password when they accept, and their login is created then.' ) }}</p>
			<el-select v-model="role" placeholder="No access" clearable style="width: 100%; max-width: 360px">
				<el-option v-for="r in info.roles" :key="r.key" :value="r.key" :label="r.label" />
			</el-select>
			<div v-if="role" class="bd-help bd-mt-xs">{{ ( info.roles.find( ( r ) => r.key === role ) || {} ).desc }}</div>
			<div v-if="role === 'wpflat_team_manager'" class="bd-mt">
				<div class="bd-strong bd-small bd-mb-xs">Also lets them manage</div>
				<el-checkbox-group v-model="modules"><el-checkbox v-for="( label, k ) in info.manager_modules" :key="k" :value="k" :label="label" /></el-checkbox-group>
			</div>
			<div v-if="role && !info.has_login && !info.invited" class="bd-mt"><el-checkbox v-model="invite">Email them an invite to join now</el-checkbox></div>
			<div class="bd-mt" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
				<el-button type="primary" :loading="saving" :disabled="!hasEmail && !info.has_login" @click="save">{{ info.has_login ? 'Save access' : ( role && invite && !info.invited ? 'Save and send invite' : 'Save role' ) }}</el-button>
				<el-button v-if="!info.has_login && info.pending" :loading="sending" @click="resend">{{ info.invited ? 'Resend invite' : 'Send invite' }}</el-button>
				<span v-if="info.invited && !info.has_login" class="bd-muted bd-small">Invited {{ info.invited.slice( 0, 10 ) }}, not accepted yet</span>
				<span v-else-if="info.joined" class="bd-muted bd-small">Joined {{ info.joined.slice( 0, 10 ) }}</span>
			</div>
		</template>
	</el-card>
</template>

<style scoped>
.bd-mt-xs { margin-top: 4px; }
.bd-mb-xs { margin-bottom: 4px; }
</style>
