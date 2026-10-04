<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { api, errorMessage, refreshMeta } from '../api';
import { store } from '../store';
import { sdk } from '../sdkInstance';
import PageHeader from '../components/PageHeader.vue';
import ResourceTable from '../components/ResourceTable.vue';
import AttachmentField from '../components/AttachmentField.vue';
import RolesAccess from '../components/RolesAccess.vue';
import PortalSettings from '../components/PortalSettings.vue';
import NotificationSettings from '../components/NotificationSettings.vue';
import SecuritySettings from '../components/SecuritySettings.vue';
import TermsSettings from '../components/TermsSettings.vue';
import { departmentsConfig, lookupsConfig } from '../resources';
import { dept, copyText } from '../util';

const props = defineProps( { initialTab: { type: String, default: 'general' } } );
const form = reactive( JSON.parse( JSON.stringify( store.meta.settings ) ) );
const saving = ref( false );
const tab = ref( props.initialTab === 'brands' ? 'departments' : props.initialTab );
const removingDemo = ref( false );

// Tabs modules add (BizyMoney: payment methods, currencies, invoice wording…).
const moduleTabs = computed( () => sdk.slot( 'settings.tabs', {} ) );
// Backup, migration and other tabs for people who manage the suite.
const adminTabs = computed( () => ( store.canManage ? sdk.slot( 'settings.admin-tabs', {} ) : [] ) );

/** Lists whose options the owner can edit: Core's, plus each module's. */
const LOOKUP_TYPES = computed( () => [
	{ id: 'client_type', label: sdk.t( 'client' ) + ' types' },
	{ id: 'client_status', label: sdk.t( 'client' ) + ' statuses' },
	{ id: 'department_type', label: dept().one + ' types' },
	...sdk.extensions( 'lookup.types' ).map( ( e ) => ( { id: e.id, label: e.label } ) ),
] );
const lookupType = ref( 'client_type' );
const lookupCfg = computed( () => lookupsConfig( lookupType.value ) );

const demoLogin = ref( null );
onMounted( async () => {
	if ( ! store.demoLoaded || ! store.canManage ) {
		return;
	}
	try {
		const st = await api.getPath( 'demo' );
		demoLogin.value = ( st && st.login ) || null;
	} catch ( e ) {
		demoLogin.value = null;
	}
} );
const copy = async ( text ) => {
	if ( await copyText( text ) ) {
		ElMessage.success( 'Copied' );
	} else {
		ElMessage.error( 'Could not copy' );
	}
};

async function removeDemo() {
	try {
		await ElMessageBox.confirm( 'Remove all the sample data that came with the app? Anything you added yourself stays.', 'Remove sample data', { type: 'warning', confirmButtonText: 'Remove sample data' } );
	} catch ( e ) { return; }
	removingDemo.value = true;
	try {
		await api.del( 'demo' );
		window.location.reload();
	} catch ( e ) {
		removingDemo.value = false;
		ElMessage.error( errorMessage( e ) );
	}
}

/* ───────────── Email ───────────── */
const mail = reactive( { method: 'wp', from_name: '', from_email: '', reply_to: '', smtp_host: '', smtp_port: 587, smtp_secure: 'tls', smtp_auth: true, smtp_user: '', smtp_pass: '', smtp_pass_set: false, resolved_from: { name: '', email: '' } } );
const mailSaving = ref( false );
const testTo = ref( store.userEmail || '' );
const testing = ref( false );
const testResult = ref( { type: '', text: '' } );
const loadMail = async () => {
	try {
		Object.assign( mail, await api.getPath( 'mail-settings' ), { smtp_pass: '' } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
onMounted( loadMail );
const saveMail = async ( quiet = false ) => {
	mailSaving.value = true;
	try {
		Object.assign( mail, await api.post( 'mail-settings', { ...mail } ), { smtp_pass: '' } );
		if ( ! quiet ) {
			ElMessage.success( 'Email settings saved' );
		}
		return true;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
		return false;
	} finally {
		mailSaving.value = false;
	}
};
const clearPassword = async () => {
	mail.smtp_pass = '__clear__';
	await saveMail();
};
const sendTest = async () => {
	testing.value = true;
	testResult.value = { type: '', text: '' };
	try {
		if ( ! ( await saveMail( true ) ) ) {
			return;
		}
		const r = await api.post( 'mail-settings/test', { to: testTo.value } );
		testResult.value = { type: 'success', text: `Test email sent to ${ r.to } via ${ r.via }. Check your inbox (and spam folder).` };
	} catch ( e ) {
		testResult.value = { type: 'error', text: errorMessage( e ) };
	} finally {
		testing.value = false;
	}
};

/* ───────────── Modules ───────────── */
const mods = ref( store.modules.map( ( m ) => ( { ...m } ) ) );
const modBusy = ref( false );
const toggleModule = async ( m, on ) => {
	m.enabled = on;
	modBusy.value = true;
	try {
		await api.post( 'modules/' + m.id, { enabled: on } );
		ElMessage.success( on ? m.label + ' is on — reloading…' : m.label + ' is off — reloading…' );
		setTimeout( () => window.location.reload(), 700 );
	} catch ( e ) {
		m.enabled = ! on;
		ElMessage.error( errorMessage( e ) );
		modBusy.value = false;
	}
};
const appLink = computed( () => store.appUrl || '' );

const save = async () => {
	saving.value = true;
	try {
		await api.post( 'settings', JSON.parse( JSON.stringify( form ) ) );
		await refreshMeta();
		ElMessage.success( 'Settings saved' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		saving.value = false;
	}
};
</script>

<template>
	<div>
		<PageHeader title="Settings" description="Everything here is configuration — nothing about your business is hard-coded." />
		<el-tabs v-model="tab" class="bd-tabs">
			<el-tab-pane label="General" name="general">
				<el-card shadow="never">
					<p class="bd-muted bd-mb">{{ $fmt( 'This is what appears on the documents, emails and pages you send — invoices, contracts and the {client} portal.' ) }}</p>
					<el-form label-position="top" style="max-width: 820px" @submit.prevent>
						<el-row :gutter="16">
							<el-col :xs="24" :sm="12"><el-form-item label="Business name"><el-input v-model="form.business_name" placeholder="Your business" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Company name (legal)"><el-input v-model="form.company_name" /></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Logo"><AttachmentField v-model="form.business_logo_id" v-model:url="form.business_logo_url" title="Business logo" /><div v-if="form.business_logo_url" class="bd-logo-prev"><img :src="form.business_logo_url" alt="Logo preview" /></div></el-form-item></el-col>
							<el-col :xs="24" :sm="12"><el-form-item label="Logo for emails (PNG/JPG)"><AttachmentField v-model="form.business_logo_email_id" v-model:url="form.business_logo_email_url" title="Email logo" /><div class="bd-help">Email apps don't show SVG logos, so add a PNG or JPG version here. Not needed if your logo is already a PNG or JPG.</div></el-form-item></el-col>
							<el-col :span="24"><el-form-item label="Address"><el-input v-model="form.business_address" type="textarea" :rows="3" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Email"><el-input v-model="form.business_email" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Phone"><el-input v-model="form.business_phone" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Website"><el-input v-model="form.business_website" placeholder="https://" /></el-form-item></el-col>
							<el-col :span="24"><el-form-item label="Data"><el-switch v-model="form.delete_on_uninstall" active-text="Delete all Bizy data when the plugin is deleted" /></el-form-item></el-col>
							<el-col v-if="store.demoLoaded && store.canManage" :span="24"><el-form-item label="Sample data"><el-button :loading="removingDemo" @click="removeDemo">Remove sample data</el-button><div class="bd-help">The example records added for you to explore. Things you added yourself are kept.</div></el-form-item></el-col>
							<el-col v-if="store.demoLoaded && store.canManage && demoLogin" :span="24">
								<el-card shadow="never" class="bd-demologin">
									<div class="bd-strong">{{ $fmt( 'Try it as a user' ) }}</div>
									<p class="bd-muted bd-small">{{ $fmt( 'Open the address below and sign in with this sample login to see what a {client} sees.' ) }}</p>
									<div class="bd-democopy"><span class="bd-muted">Address</span><a :href="demoLogin.url" target="_blank" rel="noopener">{{ demoLogin.url }}</a><el-button size="small" @click="copy( demoLogin.url )">Copy</el-button></div>
									<div class="bd-democopy"><span class="bd-muted">Username</span><code>{{ demoLogin.username }}</code><el-button size="small" @click="copy( demoLogin.username )">Copy</el-button></div>
									<div class="bd-democopy"><span class="bd-muted">Password</span><code>{{ demoLogin.password }}</code><el-button size="small" @click="copy( demoLogin.password )">Copy</el-button></div>
								</el-card>
							</el-col>
							<el-col v-if="form.delete_on_uninstall" :span="24"><el-form-item label="Backups"><el-switch v-model="form.delete_backups_on_uninstall" active-text="Also delete saved backups" /><div class="bd-help">Off by default, so a backup survives even if you remove the plugin and its data.</div></el-form-item></el-col>
						</el-row>
					</el-form>
					<el-button type="primary" :loading="saving" @click="save">Save settings</el-button>
				</el-card>
			</el-tab-pane>

			<el-tab-pane v-if="store.canManage" label="Words" name="words" lazy>
				<TermsSettings />
			</el-tab-pane>

			<el-tab-pane label="Modules & app" name="modules" lazy>
				<el-card shadow="never" class="bd-mb">
					<template #header><div class="bd-widget__head"><span>Modules</span></div></template>
					<p class="bd-muted bd-small">Switch on only what you use. Turning a module off hides it and stops its automatic jobs and public links — your data is never deleted, and switching it back on restores everything. More modules can be installed as plugins.</p>
					<ul class="bd-list bd-mt">
						<li>
							<div style="min-width: 0"><div class="bd-strong">Core <span class="bd-badge bd-badge--neutral bd-badge--plain">Always on</span></div><div class="bd-muted bd-small">Home, {{ sdk.tp( 'client' ) }}, {{ sdk.tp( 'team' ) }}, {{ sdk.tp( 'department' ) }}, {{ sdk.tp( 'group' ) }}, messages, the portal, activity and settings.</div></div>
							<el-switch :model-value="true" disabled />
						</li>
						<li v-for="m in mods" :key="m.id">
							<div style="min-width: 0"><div class="bd-strong">{{ m.label }} <span class="bd-muted bd-small">v{{ m.version }}</span> <span v-if="m.status !== 'active'" class="bd-badge bd-badge--danger bd-badge--plain">Needs attention</span></div><div class="bd-muted bd-small">{{ m.reason || m.desc }}</div></div>
							<el-switch :model-value="m.enabled" :disabled="m.status !== 'active' || modBusy || !store.canManage" @update:model-value="toggleModule( m, $event )" />
						</li>
						<li v-if="!mods.length" class="bd-muted">No module plugins are installed yet.</li>
					</ul>
					<p v-if="!store.canManage" class="bd-help">Only a WordPress administrator can change modules.</p>
				</el-card>
				<el-card shadow="never">
					<template #header><div class="bd-widget__head"><span>The app</span></div></template>
					<el-form label-position="top" @submit.prevent>
						<el-form-item label="Full-screen app mode">
							<el-switch v-model="form.app_fullscreen" active-text="Hide the WordPress menu, admin bar and notices on Bizy screens" />
						</el-form-item>
						<el-form-item label="Team members">
							<el-switch v-model="form.app_keep_staff" active-text="Keep people who are not WordPress administrators out of wp-admin" />
							<div class="bd-help">They sign in and land straight in the app. Give someone access in Settings → Roles & access, or on their team page.</div>
						</el-form-item>
						<el-form-item label="App on your website">
							<el-switch v-model="form.app_frontend" active-text="Open the app on a page of your site, with no WordPress admin" />
						</el-form-item>
						<el-form-item v-if="form.app_frontend" label="Home page">
							<el-switch v-model="form.app_home" active-text="Use the app as my website's home page" />
							<div class="bd-help">Visitors who are not signed in see the sign-in page at {{ store.homeUrl }}, and your team lands in the app. Your website's own home page is hidden while this is on — a signed-in customer or member who is not on the team still sees it, and you can always open it at <code>{{ store.homeUrl }}?bd_site=1</code>. The address {{ store.homeUrl }}{{ form.app_slug || 'app' }}/ keeps working too.</div>
						</el-form-item>
						<el-form-item v-if="form.app_frontend" label="Sign-in page">
							<el-switch v-model="form.app_login" active-text="Show a branded sign-in and password reset when someone opens the app signed out" />
							<div class="bd-help">It uses your business name and logo. Signing in and password reset run through WordPress itself, so your security plugins and reset rules still apply. Turn it off to use the standard WordPress login.</div>
						</el-form-item>
						<el-form-item v-if="form.app_frontend" label="Page address">
							<el-input v-model="form.app_slug" placeholder="app"><template #prepend>{{ store.homeUrl }}</template><template #append>/</template></el-input>
							<div class="bd-help">Signed-in team members open <a class="bd-link" :href="appLink" target="_blank" rel="noopener">{{ appLink }}</a></div>
						</el-form-item>
					</el-form>
					<el-button type="primary" :loading="saving" @click="save">Save</el-button>
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Email" name="email">
				<el-card shadow="never">
					<p class="bd-muted bd-mb">How Bizy sends email. You can use your own SMTP account here — no extra plugin needed — or keep using the site's mailer.</p>
					<el-form label-position="top" style="max-width: 820px" @submit.prevent>
						<el-form-item label="Mailer">
							<el-radio-group v-model="mail.method" class="bd-seg-sm">
								<el-radio-button value="wp">Site mailer (wp_mail)</el-radio-button>
								<el-radio-button value="smtp">Bizy SMTP</el-radio-button>
							</el-radio-group>
							<div class="bd-help">{{ mail.method === 'smtp' ? 'Used only for Bizy emails. Other plugins on the site are unaffected.' : 'Uses whatever the site already uses to send mail (e.g. FluentSMTP, or the server default).' }}</div>
						</el-form-item>
						<el-row :gutter="16">
							<el-col :xs="24" :sm="8"><el-form-item label="From name"><el-input v-model="mail.from_name" :placeholder="mail.resolved_from.name || 'Your business name'" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="From email"><el-input v-model="mail.from_email" :placeholder="mail.resolved_from.email || 'billing@yourdomain.com'" /></el-form-item></el-col>
							<el-col :xs="24" :sm="8"><el-form-item label="Reply-to"><el-input v-model="mail.reply_to" :placeholder="mail.resolved_from.reply_to || 'Same as business email'" /></el-form-item></el-col>
						</el-row>

						<template v-if="mail.method === 'smtp'">
							<h4 class="bd-subhead">SMTP server</h4>
							<el-row :gutter="16">
								<el-col :xs="24" :sm="12"><el-form-item label="Host"><el-input v-model="mail.smtp_host" placeholder="smtp.yourprovider.com" /></el-form-item></el-col>
								<el-col :xs="12" :sm="6"><el-form-item label="Port"><el-input-number v-model="mail.smtp_port" :min="1" :max="65535" :controls="false" style="width: 100%" /></el-form-item></el-col>
								<el-col :xs="12" :sm="6">
									<el-form-item label="Security">
										<el-select v-model="mail.smtp_secure" style="width: 100%"><el-option value="tls" label="TLS (STARTTLS) · 587" /><el-option value="ssl" label="SSL · 465" /><el-option value="none" label="None" /></el-select>
									</el-form-item>
								</el-col>
								<el-col :xs="24" :sm="8"><el-form-item label="Authentication"><el-switch v-model="mail.smtp_auth" active-text="Use username & password" /></el-form-item></el-col>
								<template v-if="mail.smtp_auth">
									<el-col :xs="24" :sm="8"><el-form-item label="Username"><el-input v-model="mail.smtp_user" autocomplete="off" /></el-form-item></el-col>
									<el-col :xs="24" :sm="8">
										<el-form-item label="Password">
											<el-input v-model="mail.smtp_pass" type="password" show-password autocomplete="new-password" :placeholder="mail.smtp_pass_set ? '•••••••• saved — type to replace' : ''" />
											<div class="bd-help">Stored encrypted and never shown again. <a v-if="mail.smtp_pass_set" href="#" @click.prevent="clearPassword">Remove saved password</a></div>
										</el-form-item>
									</el-col>
								</template>
							</el-row>
						</template>

					</el-form>
					<el-button type="primary" :loading="mailSaving" @click="saveMail()">Save email settings</el-button>

					<h4 class="bd-subhead">Send a test</h4>
					<p class="bd-muted bd-mb">Saves the settings above, then sends a short test message so you know the connection works before you email a client.</p>
					<el-alert v-if="testResult.text" :title="testResult.text" :type="testResult.type" show-icon :closable="false" class="bd-mb" style="max-width: 820px" />
					<div class="bd-send__test">
						<el-input v-model="testTo" placeholder="you@yourcompany.com" style="width: 280px" aria-label="Test recipient" />
						<el-button :loading="testing" :disabled="!testTo" @click="sendTest">Send test email</el-button>
					</div>
					<p class="bd-help bd-mt">Tip: for the best inbox placement, send from an address on your own domain and make sure its SPF and DKIM records allow your SMTP provider.</p>
				</el-card>
			</el-tab-pane>
			<el-tab-pane :label="dept().many" name="departments" lazy>
				<el-card shadow="never">
					<p class="bd-muted bd-mb">The {{ dept().lowerMany }} of your business. Tag each {{ sdk.t( 'client' ).toLowerCase() }} with the ones they belong to, then filter your numbers by {{ dept().lower }}.</p>
					<ResourceTable :config="departmentsConfig()" embedded />
				</el-card>
			</el-tab-pane>

			<el-tab-pane label="Lists & options" name="lists" lazy>
				<el-card shadow="never">
					<p class="bd-muted">Rename, reorder, recolour or deactivate options, or add your own. Built-in options can't be deleted.</p>
					<el-radio-group v-model="lookupType" class="bd-mb"><el-radio-button v-for="t in LOOKUP_TYPES" :key="t.id" :value="t.id">{{ t.label }}</el-radio-button></el-radio-group>
					<ResourceTable :key="lookupType" :config="lookupCfg" :fixed="{ type: lookupType }" embedded @changed="refreshMeta" />
				</el-card>
			</el-tab-pane>

			<el-tab-pane v-for="t in moduleTabs" :key="t.id" :label="t.label" :name="t.id" lazy>
				<component :is="t.component" :form="form" :save="save" :saving="saving" />
			</el-tab-pane>

			<el-tab-pane v-if="store.canManage" label="Notifications" name="notifications" lazy>
				<NotificationSettings />
			</el-tab-pane>

			<el-tab-pane v-if="store.canManage" :label="$fmt( '{Client} portal' )" name="portal" lazy>
				<PortalSettings />
			</el-tab-pane>

			<el-tab-pane v-if="store.canManage" label="Roles & access" name="access" lazy>
				<RolesAccess />
			</el-tab-pane>

			<el-tab-pane v-if="store.wpAdmin" label="Security & privacy" name="security" lazy>
				<SecuritySettings />
			</el-tab-pane>

			<el-tab-pane v-for="t in adminTabs" :key="t.id" :label="t.label" :name="t.id" lazy>
				<component :is="t.component" />
			</el-tab-pane>
		</el-tabs>
	</div>
</template>
