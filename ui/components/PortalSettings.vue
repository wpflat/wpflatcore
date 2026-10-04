<script setup>
import { reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage, refreshMeta } from '../api';
import { store } from '../store';

const s = store.meta.settings;
const pages = store.meta.portal_pages || [];
const form = reactive( {
	portal_enabled: s.portal_enabled !== false,
	portal_slug: s.portal_slug || 'portal',
	portal_title: s.portal_title || '',
	portal_welcome: s.portal_welcome || '',
	portal_show_messages: s.portal_show_messages !== false,
	messages_max_mb: s.messages_max_mb || 25,
	messages_notify: s.messages_notify || '',
} );
// A switch for every page a module adds, and for each of that page's own options.
pages.forEach( ( p ) => {
	form[ p.key ] = s[ p.key ] !== false;
	( p.options || [] ).forEach( ( o ) => ( form[ o.key ] = s[ o.key ] !== false ) );
} );
const saving = ref( false );
const url = () => `${ store.homeUrl || window.location.origin + '/' }${ form.portal_slug || 'portal' }/`.replace( /([^:])\/\//g, '$1/' );

const openIt = () => window.open( url(), '_blank' );

async function save() {
	saving.value = true;
	try {
		await api.post( 'settings', { ...form } );
		await refreshMeta();
		ElMessage.success( 'Portal settings saved.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
}
</script>

<template>
	<div>
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>{{ $fmt( '{Client} portal' ) }}</span></div></template>
			<p class="bd-muted bd-small">{{ $fmt( 'A page where each {client} signs in and sees only their own information. {Clients} get a login automatically (see Roles & access) and you can email each contact an invitation from the {client}\'s Contacts tab.' ) }}</p>
			<el-form label-position="top" class="bd-mt">
				<el-form-item><el-switch v-model="form.portal_enabled" :active-text="$fmt( '{Client} portal is on' )" /></el-form-item>
				<el-row :gutter="16">
					<el-col :xs="24" :sm="12"><el-form-item label="Address word"><el-input v-model="form.portal_slug" placeholder="portal"><template #prepend>/</template><template #append>/</template></el-input><div class="bd-help">{{ $fmt( '{Clients} open' ) }} <strong>{{ url() }}</strong>. Changing it breaks addresses you already shared.</div></el-form-item></el-col>
					<el-col :xs="24" :sm="12"><el-form-item label="Portal name"><el-input v-model="form.portal_title" :placeholder="$fmt( '{Client} portal' )" /><div class="bd-help">Shown in the browser tab.</div></el-form-item></el-col>
				</el-row>
				<el-form-item label="Welcome message"><el-input v-model="form.portal_welcome" type="textarea" :rows="3" placeholder="Shown at the top of the overview. Leave empty for the standard line." /></el-form-item>
				<el-form-item :label="$fmt( 'What {clients} can see' )">
					<div class="bd-portal-opts">
						<template v-for="p in pages" :key="p.slug">
							<el-switch v-model="form[ p.key ]" :active-text="p.label" />
							<el-switch v-for="o in p.options" :key="o.key" v-model="form[ o.key ]" :disabled="!form[ p.key ]" :active-text="o.label" />
						</template>
						<el-switch v-model="form.portal_show_messages" active-text="Messages and file sharing" />
					</div>
					<div class="bd-help">A section also needs its module switched on. Drafts, costs, team pay and internal notes are never shown in the portal.</div>
				</el-form-item>
				<el-row :gutter="16">
					<el-col :xs="24" :sm="8"><el-form-item :label="$fmt( 'Largest file {clients} can send (MB)' )"><el-input-number v-model="form.messages_max_mb" :min="1" :max="200" /><div class="bd-help">Also limited by your server's upload size.</div></el-form-item></el-col>
					<el-col :xs="24" :sm="16"><el-form-item :label="$fmt( 'Email me about new {client} messages' )"><el-input v-model="form.messages_notify" placeholder="you@example.com, team@example.com" /><div class="bd-help">Leave empty to use the site administrator's address. The person who owns what the conversation is about, and anyone who already replied, are also told. One email per conversation every ten minutes.</div></el-form-item></el-col>
				</el-row>
			</el-form>
			<el-button type="primary" :loading="saving" @click="save">Save</el-button>
			<el-button @click="openIt">Open the portal</el-button>
		</el-card>
	</div>
</template>

<style scoped>
.bd-portal-opts { display: grid; gap: 10px; }
</style>
