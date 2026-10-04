<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { api, errorMessage } from '../api';
import { navigate } from '../store';
import Badge from './Badge.vue';

const o = ref( null );
const busy = ref( false );
const testing = reactive( { on: false, step: '', done: 0, total: 0 } );
const checking = ref( false );
const report = ref( null );
const moving = reactive( { on: false, msg: '' } );
const target = ref( 'content' );
const secretInput = ref( '' );
const left = ref( 0 );
let timer = null;

const LAYERS = [
	[ 'hide_login', 'Hide the WordPress sign-in', 'wp-login.php answers “Not Found”. Staff and clients sign in on Bizy’s own pages.' ],
	[ 'hide_admin', 'Hide WordPress admin', 'Only a browser that opened your secret address can reach /wp-admin.' ],
	[ 'hide_rest', 'Hide the WordPress API', 'Strangers get “Not Found” from /wp-json. Signed-in tools and the allowed names below keep working.' ],
	[ 'hide_misc', 'Hide feeds, author pages, XML-RPC, sitemaps and WordPress entry files', 'Stops username lookups and automatic scanners.' ],
	[ 'only_allowlist', 'Everything else is a plain 404', 'Only the app, portal, signing and invoice links, and Bizy’s own files answer. The home address opens the app.' ],
	[ 'strip', 'Remove WordPress fingerprints', 'No generator tags, API links, pingback or “X-Powered-By” headers.' ],
];

const SYMPTOMS = [
	[ 'I cannot get into WordPress admin', 'Open your secret address (Step 3B) once in the browser, then sign in at the page it opens. Lost the address? Use a recovery switch below, then the address can be shown again on this screen.' ],
	[ 'The app or the portal shows a blank page or an error', 'Switch stealth mode off with a recovery switch below (it takes effect on the next page load). Then run the hosting test again and read the warnings: a plugin or a cache is usually involved.' ],
	[ 'Logos, receipts or images are missing', 'Open Step 3A and check the files folder is “In use”. If you just moved the files, use “Go back to the old location” before deleting the old copies. Images are served from /bd/f/…; if that address answers 404, check Settings → Permalinks is not “Plain”.' ],
	[ 'A plugin stopped working (a form, a shop, an editor)', 'It probably needs the WordPress API without a sign-in. Add its name to “Tools allowed to use the API” in Step 3B (for example contact-form-7, fluentform, wc). If it uses the WordPress sign-in page (two-factor, hide-login plugins), use only one hiding method.' ],
	[ 'The whole site shows a “500 Internal Server Error” after the server rules', 'Open .htaccess in the WordPress folder (FTP or the host’s file manager) and delete everything from “# BEGIN Bizy Stealth” to “# END Bizy Stealth”, or replace it with the saved copy .htaccess.bd-backup. Bizy also tries to undo this by itself within seconds.' ],
	[ 'My host uses Nginx, or the test says .htaccess is ignored', 'The server rules cannot be added automatically. Everything else still works. Ask your host to add the rules shown in Step 3C, or leave that step off.' ],
	[ 'The files folder is not writable', 'Give the web server write access to the wp-content folder (permission 755 or 775 for the folder and its owner set to the web user), or ask your host, then run the test again. Nothing is moved until the test passes.' ],
	[ 'I changed host, restored a backup, or cloned the site', 'The files folder lives in wp-content/bd-files, so it travels with wp-content. After any move, run the hosting test again and check Step 3A. Always keep your own backup (Settings → Backup & restore).' ],
];

async function load() {
	try {
		o.value = await api.getPath( 'stealth' );
		secretInput.value = o.value.secret;
		left.value = o.value.seconds_left;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

async function save( patch ) {
	busy.value = true;
	try {
		o.value = await api.post( 'stealth', patch );
		secretInput.value = o.value.secret;
		left.value = o.value.seconds_left;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
		await load();
	} finally {
		busy.value = false;
	}
}

async function setAck( v ) {
	try {
		const r = await api.post( 'stealth/ack', { ack: v } );
		o.value.readiness = r;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

async function downloadCard() {
	try {
		const r = await api.getPath( 'stealth/recovery-card' );
		const url = URL.createObjectURL( new Blob( [ r.text ], { type: 'text/plain' } ) );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = 'bizy-recovery-card.txt';
		a.click();
		URL.revokeObjectURL( url );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

async function runTest() {
	testing.on = true;
	try {
		const steps = Object.keys( o.value.readiness.steps );
		testing.total = steps.length;
		testing.done = 0;
		await api.post( 'stealth/test', { step: 'start' } );
		for ( const id of steps ) {
			testing.step = o.value.readiness.steps[ id ];
			o.value.readiness = await api.post( 'stealth/test', { step: id } );
			testing.done += 1;
		}
		await load();
		ElMessage.success( 'The hosting test is complete.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		testing.on = false;
	}
}

const R = computed( () => ( o.value ? o.value.readiness : null ) );
const verdict = ( l ) => ( R.value && R.value.fresh && R.value.verdicts ? R.value.verdicts[ l ] || 'blocked' : 'untested' );
const can = ( l ) => !! ( R.value && R.value.ack && verdict( l ) !== 'blocked' && verdict( l ) !== 'untested' );
const VERDICT = { ready: [ 'Ready', 'success' ], warn: [ 'Ready, with notes', 'warning' ], blocked: [ 'Not supported here', 'danger' ], untested: [ 'Run the test first', 'neutral' ] };
const LEVEL = { pass: [ 'OK', 'success' ], warn: [ 'Note', 'warning' ], fail: [ 'Problem', 'danger' ], info: [ 'Info', 'info' ] };
const itemsByStep = computed( () => {
	const g = {};
	( R.value ? R.value.items : [] ).forEach( ( i ) => ( g[ i.step ] = g[ i.step ] || [] ).push( i ) );
	return g;
} );

async function turnOn() {
	try {
		await ElMessageBox.confirm( 'Stealth mode starts now. You have 5 minutes to check that the app still works and press Confirm, otherwise it switches itself off again. Keep your secret address safe and keep this browser tab (and a second signed-in one) open.', 'Turn on stealth mode', { confirmButtonText: 'Turn on', type: 'warning' } );
	} catch ( e ) { return; }
	await save( { enabled: true } );
}
async function confirmIt() {
	busy.value = true;
	try {
		o.value = await api.post( 'stealth/confirm' );
		ElMessage.success( 'Stealth mode is confirmed.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		busy.value = false;
	}
}
const saveSecret = ( generate ) => save( generate ? { secret_generate: true } : { secret: secretInput.value } );
const copy = async ( t ) => {
	try {
		await navigator.clipboard.writeText( t );
		ElMessage.success( 'Copied.' );
	} catch ( e ) {
		ElMessage.warning( t );
	}
};

async function moveFiles() {
	moving.on = true;
	moving.msg = 'Copying and checking your files…';
	try {
		let r;
		do {
			r = await api.post( 'files/move', { target: target.value } );
			moving.msg = r.done ? 'Done.' : `Copying… ${ r.remaining } file${ r.remaining === 1 ? '' : 's' } left`;
		} while ( ! r.done );
		ElMessage.success( 'Your files now live in the BD files folder.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		moving.on = false;
		await load();
	}
}
async function cleanup() {
	try {
		await ElMessageBox.confirm( 'Delete the old copies? The new folder has been checked, but this cannot be undone.', 'Clean up', { type: 'warning', confirmButtonText: 'Delete old copies' } );
	} catch ( e ) { return; }
	try {
		const r = await api.post( 'files/cleanup' );
		ElMessage.success( `Removed ${ r.deleted } old file${ r.deleted === 1 ? '' : 's' }.` );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
	await load();
}
async function revertFiles() {
	try {
		await api.post( 'files/revert' );
		ElMessage.success( 'Back to the previous location.' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
	await load();
}

async function runCheck() {
	checking.value = true;
	try {
		report.value = await api.post( 'stealth/check' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		checking.value = false;
	}
}
const groups = computed( () => {
	const g = {};
	( report.value ? report.value.items : [] ).forEach( ( i ) => ( g[ i.group ] = g[ i.group ] || [] ).push( i ) );
	return g;
} );
const mmss = computed( () => `${ Math.floor( left.value / 60 ) }:${ String( left.value % 60 ).padStart( 2, '0' ) }` );
const size = ( b ) => ( b > 1048576 ? ( b / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( b / 1024 ) ) + ' KB' );
const pending = computed( () => ( o.value ? Object.entries( o.value.files.legacy ).filter( ( [ , v ] ) => v.files ).map( ( [ k, v ] ) => `${ v.files } ${ k } (${ size( v.bytes ) })` ).join( ', ' ) : '' ) );
const homeNow = computed( () => ( o.value ? o.value.files.mode === 'bd' && o.value.files.root.includes( '/wp-content/bd-files' ) : false ) );

onMounted( () => {
	load();
	timer = setInterval( () => { if ( left.value > 0 ) left.value -= 1; if ( o.value && o.value.enabled && ! o.value.confirmed && left.value <= 0 ) load(); }, 1000 );
} );
onBeforeUnmount( () => clearInterval( timer ) );
</script>

<template>
	<div v-if="o">
		<el-alert type="warning" show-icon :closable="false" class="bd-mb" title="Beta">
			<span class="bd-small">Hiding WordPress and moving files are new, optional features. They are off until you turn them on, one stage at a time, after reading the steps and passing a test of your hosting. Not every host and plugin combination behaves the same, which is why the test comes first.</span>
		</el-alert>
		<el-alert v-if="o.enabled && !o.confirmed" type="warning" show-icon :closable="false" class="bd-mb" :title="`Stealth mode is on. Confirm within ${ mmss } that everything still works, or it switches itself off.`">
			<el-button type="primary" size="small" :loading="busy" @click="confirmIt">Everything works — keep it on</el-button>
		</el-alert>
		<el-alert v-if="o.recovery" type="info" show-icon :closable="false" class="bd-mb" :title="`A recovery switch is set (${ o.recovery }), so stealth mode is forced off until you remove it.`" />

		<!-- 1 -->
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>1 · Read this first</span><Badge :tone="R.ack ? 'success' : 'neutral'">{{ R.ack ? 'Done' : 'Required' }}</Badge></div></template>
			<p class="bd-muted bd-small">Stealth mode makes the site stop looking like WordPress: WordPress sign-in, admin, API, feeds and core files answer “Not Found”, and nothing in Bizy’s pages points at <code>/wp-content/</code>. Contracts, messages, backups and images move into their own folder. It hides the usual giveaways; it cannot promise to be invisible to someone who studies the site closely.</p>
			<ol class="bd-small bd-steps2">
				<li><strong>Take a backup</strong> before you start. <a href="#/settings?tab=backup" @click.prevent="navigate( '/settings?tab=backup' )">Open Backup &amp; restore</a></li>
				<li><strong>Keep a second browser or private window signed in</strong> while you try things, so you can undo a change from it.</li>
				<li><strong>Save your recovery details.</strong> The card has your secret admin address and the three ways back in. <el-button size="small" @click="downloadCard">Download the recovery card</el-button></li>
				<li><strong>Know the three ways back in</strong> (any one switches stealth mode off): a line in <code>wp-config.php</code>, an empty file in the files folder, or a command on the server. They are listed in the card and at the bottom of this page.</li>
				<li><strong>Do it in stages</strong> (Step 3), and check the app, the portal and a signed contract link after each stage.</li>
			</ol>
			<el-checkbox :model-value="R.ack" @update:model-value="setAck">I have read the instructions and saved my recovery details</el-checkbox>
		</el-card>

		<!-- 2 -->
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>2 · Test my hosting</span><el-button size="small" type="primary" :loading="testing.on" @click="runTest">{{ R.complete ? 'Run the test again' : 'Run the test' }}</el-button></div></template>
			<p class="bd-muted bd-small">Checks, without changing anything, whether this hosting can do each stage: the PHP features, whether the files folder can be created and kept private, whether Bizy’s own addresses work, whether the server obeys .htaccess, whether the site can call itself, and which other plugins might be affected. The result stays valid for 24 hours.</p>
			<div v-if="testing.on" class="bd-mb"><el-progress :percentage="Math.round( 100 * testing.done / Math.max( 1, testing.total ) )" :stroke-width="8" /><div class="bd-muted bd-small">Checking: {{ testing.step }}…</div></div>
			<div v-if="R.complete" class="bd-mb">
				<div class="bd-prefrow" v-for="l in [ [ 'files', 'A · Files folder' ], [ 'stealth', 'B · Hide WordPress' ], [ 'rules', 'C · Server rules' ] ]" :key="l[ 0 ]"><span class="bd-prefrow__l">{{ l[ 1 ] }}</span><Badge :tone="VERDICT[ verdict( l[ 0 ] ) ][ 1 ]">{{ VERDICT[ verdict( l[ 0 ] ) ][ 0 ] }}</Badge></div>
				<div v-if="!R.fresh" class="bd-help">This result is more than 24 hours old. Run the test again before turning anything on.</div>
				<div class="bd-help">Tested {{ R.tested_at }} UTC.</div>
			</div>
			<el-collapse v-if="R.complete">
				<el-collapse-item v-for="( name, id ) in R.steps" :key="id" :title="name">
					<div v-for="( i, n ) in ( itemsByStep[ id ] || [] )" :key="n" class="bd-prefrow"><Badge :tone="LEVEL[ i.level ][ 1 ]">{{ LEVEL[ i.level ][ 0 ] }}</Badge><span class="bd-prefrow__l"><span>{{ i.label }}</span><span v-if="i.detail" class="bd-muted bd-small" style="display: block">{{ i.detail }}</span><span v-if="i.fix && i.level !== 'pass'" class="bd-small" style="display: block; color: var(--bd-badge-warning-text)">What to do: {{ i.fix }}</span></span></div>
				</el-collapse-item>
			</el-collapse>
		</el-card>

		<!-- 3 -->
		<div class="bd-strong bd-mb-xs" style="margin: 16px 0 8px">3 · Turn it on, in stages</div>
		<el-alert v-if="!R.ack || !R.fresh" type="info" show-icon :closable="false" class="bd-mb" title="The stages unlock after you tick the box in Step 1 and run the test in Step 2." />

		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>A · BD files folder</span><Badge :tone="VERDICT[ verdict( 'files' ) ][ 1 ]">{{ VERDICT[ verdict( 'files' ) ][ 0 ] }}</Badge></div></template>
			<p class="bd-muted bd-small"><strong>What changes:</strong> contracts, messages, backups and images are copied into <code>wp-content/bd-files</code> and checked, then Bizy switches over. Nothing is deleted until you choose. <strong>Look for:</strong> contract PDFs still download and verify, logos still show. <strong>Undo:</strong> “Go back to the previous location” (until you delete the old copies).</p>
			<div class="bd-small bd-mb">Now: <code>{{ o.files.root }}</code> <Badge :tone="o.files.mode === 'bd' ? 'success' : 'neutral'">{{ o.files.mode === 'bd' ? 'In use' : 'Not moved yet' }}</Badge> <Badge :tone="o.files.outside_webroot ? 'info' : 'success'">{{ o.files.outside_webroot ? 'Outside the web root' : 'Inside wp-content' }}</Badge></div>
			<div v-if="o.files.mode !== 'bd'" class="bd-small bd-mb">Waiting to be moved: {{ pending || 'nothing yet' }}.</div>
			<el-radio-group v-if="!homeNow && !o.files.fixed_by_constant" v-model="target" class="bd-mb" style="display: grid; gap: 8px">
				<el-radio value="content">wp-content/bd-files <span class="bd-muted bd-small">— recommended: survives WordPress updates, works on every host</span></el-radio>
				<el-radio value="outside" :disabled="!o.files.targets.outside.writable">Beside the WordPress folder <span class="bd-muted bd-small">— advanced: more private, but a host update, restore or migration can remove it{{ o.files.targets.outside.writable ? '' : ' (not possible on this host)' }}</span></el-radio>
			</el-radio-group>
			<el-button v-if="!homeNow && !o.files.fixed_by_constant" type="primary" :disabled="!can( 'files' )" :loading="moving.on" @click="moveFiles">{{ moving.on ? moving.msg : 'Move my files' }}</el-button>
			<span v-if="homeNow" class="bd-muted bd-small">The files are in wp-content/bd-files.</span>
			<span v-if="o.files.fixed_by_constant" class="bd-muted bd-small">The location is fixed by BIZYDASH_FILES_DIR in wp-config.php.</span>
			<div v-if="o.files.old_copies" class="bd-mt"><el-button size="small" @click="cleanup">Delete the old copies ({{ o.files.old_count }})</el-button><el-button size="small" text @click="revertFiles">Go back to the previous location</el-button></div>
		</el-card>

		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>B · Hide WordPress (stealth mode)</span><Badge :tone="o.enabled ? 'success' : VERDICT[ verdict( 'stealth' ) ][ 1 ]">{{ o.enabled ? ( o.confirmed ? 'On' : 'On — waiting for confirmation' ) : VERDICT[ verdict( 'stealth' ) ][ 0 ] }}</Badge></div></template>
			<p class="bd-muted bd-small"><strong>What changes:</strong> the WordPress entry points answer a plain “Not Found”, and anything Bizy does not own does too. Sign-in happens on the app and portal pages. <strong>Look for:</strong> you can still sign in to the app and the portal, a signed contract link and an invoice link still open, and WordPress admin opens through your secret address. <strong>Undo:</strong> the Turn off button, the 5-minute confirm timer, or a recovery switch.</p>
			<div class="bd-strong bd-small">Your secret admin address</div>
			<div style="display: flex; gap: 8px; flex-wrap: wrap; max-width: 640px; margin: 4px 0 8px">
				<el-input v-model="secretInput" placeholder="at least 8 letters or numbers" style="flex: 1; min-width: 220px"><template #prepend>{{ o.secret_url ? o.secret_url.replace( o.secret, '' ) : '/' }}</template></el-input>
				<el-button :loading="busy" @click="saveSecret( false )">Save</el-button>
				<el-button :loading="busy" @click="saveSecret( true )">Generate one</el-button>
			</div>
			<div v-if="o.secret_url" class="bd-small bd-mb">Your address: <a :href="o.secret_url" target="_blank" rel="noopener">{{ o.secret_url }}</a> <el-button size="small" text @click="copy( o.secret_url )">Copy</el-button> <span class="bd-muted">Opening it once keeps WordPress admin available for 12 hours.</span></div>
			<div v-for="l in LAYERS" :key="l[ 0 ]" class="bd-prefrow">
				<span class="bd-prefrow__l"><span class="bd-strong">{{ l[ 1 ] }}</span><span class="bd-muted bd-small" style="display: block">{{ l[ 2 ] }}</span></span>
				<el-switch :model-value="o.layers[ l[ 0 ] ]" :disabled="busy" @update:model-value="( v ) => save( { [ l[ 0 ] ]: v } )" />
			</div>
			<div class="bd-mt"><span class="bd-strong bd-small">Tools allowed to use the API without a WordPress sign-in</span>
				<div style="display: flex; gap: 8px; max-width: 520px; margin-top: 4px"><el-input :model-value="o.rest_allow" placeholder="novamira, wp-abilities" @change="( v ) => save( { rest_allow: v } )" /></div>
				<div class="bd-help">Names, separated by commas. “novamira” keeps the Novamira deployment tool working; add the name of any plugin whose public pages need the API.</div>
			</div>
			<div class="bd-mt">
				<el-button v-if="!o.enabled" type="primary" :loading="busy" :disabled="!can( 'stealth' ) || !o.secret" @click="turnOn">Turn on stealth mode</el-button>
				<el-button v-else :loading="busy" @click="save( { enabled: false } )">Turn off</el-button>
				<span v-if="!o.secret && !o.enabled" class="bd-muted bd-small" style="margin-left: 10px">Choose your secret admin address first.</span>
			</div>
		</el-card>

		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>C · Server rules (.htaccess)</span><Badge :tone="o.rules.applied ? 'success' : VERDICT[ verdict( 'rules' ) ][ 1 ]">{{ o.rules.applied ? 'On' : VERDICT[ verdict( 'rules' ) ][ 0 ] }}</Badge></div></template>
			<p class="bd-muted bd-small"><strong>What changes:</strong> a marked block at the top of .htaccess hides readme.html, license.txt, wp-includes, wp-content and wp-cron.php from outsiders (a copy of .htaccess is kept first). <strong>Look for:</strong> the Stealth check at the bottom shows no WordPress paths. <strong>Undo:</strong> turn this off, delete the marked block, or restore .htaccess.bd-backup. It undoes itself if the site stops answering. Needs stage B to be on.</p>
			<div class="bd-prefrow"><span class="bd-prefrow__l"><span class="bd-strong">Add the server rules</span></span><el-switch :model-value="o.rules.applied" :disabled="busy || !o.enabled || !can( 'rules' ) || !o.rules.supported" @update:model-value="( v ) => save( { rules: v } )" /></div>
			<p v-if="!o.rules.supported" class="bd-help">This server does not read .htaccess (it looks like Nginx), so the rules cannot be added automatically. Ask your host to add them to the server configuration:</p>
			<pre v-if="!o.rules.supported" class="bd-code">{{ o.rules.rules }}</pre>
		</el-card>

		<!-- 4 -->
		<el-card shadow="never" class="bd-mb">
			<template #header><div class="bd-widget__head"><span>4 · If something does not work</span></div></template>
			<el-collapse>
				<el-collapse-item v-for="( s, i ) in SYMPTOMS" :key="i" :title="s[ 0 ]"><div class="bd-small">{{ s[ 1 ] }}</div></el-collapse-item>
			</el-collapse>
			<div class="bd-strong bd-small" style="margin-top: 14px">Three ways back in (any one switches stealth mode off, even if the site will not load properly)</div>
			<ol class="bd-small bd-steps2">
				<li>Add this line to <code>wp-config.php</code>: <code>{{ o.wpconfig.constant }}</code></li>
				<li>Create an empty file at <code>{{ o.wpconfig.file }}</code> over FTP or the host’s file manager.</li>
				<li>Run <code>{{ o.wpconfig.wpcli }}</code> on the server.</li>
			</ol>
			<el-button size="small" @click="downloadCard">Download the recovery card</el-button>
		</el-card>

		<el-card shadow="never">
			<template #header><div class="bd-widget__head"><span>What can an outsider see?</span><el-button size="small" type="primary" :loading="checking" @click="runCheck">Run the check</el-button></div></template>
			<p class="bd-muted bd-small">After turning things on: the server asks its own public addresses the way a stranger would, and reports anything that still says WordPress.</p>
			<template v-if="report">
				<el-alert :type="report.failed ? 'warning' : 'success'" show-icon :closable="false" :title="report.failed ? `${ report.failed } thing${ report.failed === 1 ? '' : 's' } still reveal WordPress; ${ report.passed } look fine.` : `All ${ report.passed } checks passed.`" class="bd-mb" />
				<div v-for="( list, g ) in groups" :key="g" class="bd-mb">
					<div class="bd-strong bd-small">{{ g }}</div>
					<div v-for="( i, n ) in list" :key="n" class="bd-prefrow"><Badge :tone="i.info ? 'info' : i.ok ? 'success' : 'danger'">{{ i.info ? 'Info' : i.ok ? 'OK' : 'Exposed' }}</Badge><span class="bd-prefrow__l"><span>{{ i.label }}</span><span v-if="i.detail" class="bd-muted bd-small" style="display: block">{{ i.detail }}</span></span></div>
				</div>
			</template>
		</el-card>
	</div>
</template>

<style scoped>
.bd-code { background: var(--bd-hover); padding: 10px 12px; border-radius: 8px; font-size: 12px; overflow-x: auto; white-space: pre; }
.bd-steps2 { margin: 0 0 12px; padding-left: 18px; display: grid; gap: 8px; }
</style>
