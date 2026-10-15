<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { api, errorMessage } from '../api';
import { fmtDateTime } from '../util';

/**
 * Move from the old single BizyDash plugin: pick one of its backups, import the core part, then let each module
 * installed pick up its own part. Nothing in the old plugin is changed.
 */
const status = ref( null );
const loading = ref( true );
const picked = ref( '' );
const password = ref( '' );
const busy = ref( false );
const progress = ref( 0 );
const msg = reactive( { type: '', text: '' } );
const fileInput = ref( null );

const load = async () => {
	loading.value = true;
	try {
		status.value = await api.getPath( 'migration' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
	loading.value = false;
};
onMounted( load );

const a = computed( () => ( status.value ? status.value.analysis : null ) );
const OWNER_LABEL = { core: 'Core', tasks: 'BizyTasks', money: 'BizyMoney', sign: 'BizySign', ignored: 'Not used' };

const adopt = async ( body ) => {
	busy.value = true;
	msg.text = '';
	try {
		const r = await ( body instanceof FormData ? api.upload( 'migration/adopt', body ) : api.post( 'migration/adopt', body ) );
		status.value = { ...status.value, adopted: true, analysis: r };
	} catch ( e ) {
		msg.type = 'error';
		msg.text = errorMessage( e );
	}
	busy.value = false;
};
const choose = () => adopt( { file: picked.value, password: password.value } );
const upload = ( f ) => {
	if ( ! f ) return;
	const fd = new FormData();
	fd.append( 'file', f );
	fd.append( 'password', password.value );
	adopt( fd );
};

const runImport = async () => {
	let replace = false;
	if ( a.value && ! a.value.target_empty ) {
		try {
			await ElMessageBox.confirm( 'The new suite already has records. Importing replaces its clients, contacts, team, departments and conversations with the ones from the backup.', 'Replace what is here?', { type: 'warning', confirmButtonText: 'Replace and import' } );
			replace = true;
		} catch ( e ) { return; }
	}
	busy.value = true;
	progress.value = 0;
	msg.text = '';
	try {
		for ( let i = 0; i < 400; i++ ) {
			const r = await api.post( 'migration/step', { replace } );
			progress.value = r.percent;
			if ( r.done ) break;
		}
		await refresh();
		msg.type = 'success';
		msg.text = 'The core data is imported.';
	} catch ( e ) {
		msg.type = 'error';
		msg.text = errorMessage( e );
	}
	busy.value = false;
};

const refresh = async () => {
	status.value = await api.getPath( 'migration' );
};
const importModule = async ( m ) => {
	busy.value = true;
	msg.text = '';
	try {
		await api.post( 'migration/module', { module: m.module } );
		await refresh();
		msg.type = 'success';
		msg.text = ( OWNER_LABEL[ m.module ] || m.module ) + ' is imported.';
	} catch ( e ) {
		msg.type = 'error';
		msg.text = errorMessage( e );
	}
	busy.value = false;
};
const people = async () => {
	busy.value = true;
	try {
		const r = await api.post( 'migration/users', {} );
		msg.type = 'success';
		msg.text = `${ r.people } people were given their new roles. Their old roles stay until you switch the old plugin off.`;
	} catch ( e ) {
		msg.type = 'error';
		msg.text = errorMessage( e );
	}
	busy.value = false;
};
const discard = async () => {
	try {
		await ElMessageBox.confirm( 'Remove the copy of the backup that was kept for this move? Anything not imported yet cannot be imported afterwards.', 'Finish the move', { type: 'warning', confirmButtonText: 'Remove the copy' } );
	} catch ( e ) { return; }
	await api.del( 'migration' );
	await refresh();
};
const done = computed( () => a.value && a.value.core_done );
const failed = computed( () => ( a.value && a.value.report && a.value.report.failed ) || {} );
</script>

<template>
	<el-card v-loading="loading" shadow="never">
		<template #header><div class="bd-widget__head"><span>Move from BizyDash</span></div></template>
		<p class="bd-muted bd-small">Coming from the old single BizyDash plugin? Choose one of its backups. Your clients, contacts, team, departments, conversations, settings and logo come across with the same ids. Invoices, projects, tasks and contracts are kept safely and come across when BizyMoney, BizyTasks and BizySign are installed. The old plugin is not touched.</p>
		<el-alert v-if="msg.text" :title="msg.text" :type="msg.type" show-icon :closable="false" class="bd-mb" />

		<template v-if="status && !status.adopted">
			<el-empty v-if="!status.candidates.length" description="No BizyDash backup was found on this site. Upload one below." :image-size="64" />
			<el-radio-group v-else v-model="picked" class="bd-mb" style="display: grid; gap: 8px">
				<el-radio v-for="c in status.candidates" :key="c.file" :value="c.file" border>
					{{ c.created_at ? fmtDateTime( c.created_at ) : c.modified }} · {{ c.encrypted ? 'password protected' : c.rows + ' records' }} <span v-if="c.plugin_version" class="bd-muted">· v{{ c.plugin_version }}</span>
				</el-radio>
			</el-radio-group>
			<div class="bd-send__test">
				<el-input v-model="password" type="password" show-password placeholder="Password, if the backup has one" style="width: 280px" />
				<el-button type="primary" :loading="busy" :disabled="!picked" @click="choose">Use this backup</el-button>
				<input ref="fileInput" type="file" accept=".bizydash,.zip" hidden @change="upload( $event.target.files[ 0 ] )" />
				<el-button :loading="busy" @click="fileInput.click()">Upload a backup…</el-button>
			</div>
		</template>

		<template v-else-if="a">
			<el-descriptions :column="1" size="small" border class="bd-mb">
				<el-descriptions-item label="Made">{{ fmtDateTime( a.created_at ) }} · BizyDash {{ a.plugin_version }}</el-descriptions-item>
				<el-descriptions-item label="From">{{ a.site_url }} <span v-if="!a.same_site" class="bd-muted">(another site: people are matched by email)</span></el-descriptions-item>
			</el-descriptions>
			<el-table :data="a.tables.filter( ( t ) => t.rows > 0 )" size="small" class="bd-mb">
				<el-table-column label="What" prop="table" min-width="160" />
				<el-table-column label="Records" prop="rows" width="100" align="right" />
				<el-table-column label="Goes to" min-width="140"><template #default="{ row }">{{ OWNER_LABEL[ row.owner ] || row.owner }}</template></el-table-column>
			</el-table>

			<div v-if="!done">
				<el-progress v-if="busy" :percentage="progress" class="bd-mb" />
				<el-button type="primary" :loading="busy" @click="runImport">Import the core data ({{ a.core_rows }} records)</el-button>
				<el-button :disabled="busy" @click="discard">Choose another backup</el-button>
			</div>

			<template v-else>
				<el-alert type="success" show-icon :closable="false" class="bd-mb" title="The core data is in." description="Clients, contacts, departments, team, conversations, activity, lists and settings are imported." />
				<el-alert v-if="Object.keys( failed ).length" type="warning" show-icon :closable="false" class="bd-mb" :title="'Some rows could not be imported: ' + Object.entries( failed ).map( ( [ t, n ] ) => n + ' ' + t ).join( ', ' )" />
				<h4 class="bd-subhead">Modules</h4>
				<ul class="bd-list bd-mb">
					<li v-for="m in a.modules" :key="m.module">
						<div><div class="bd-strong">{{ OWNER_LABEL[ m.module ] || m.module }} <span class="bd-muted bd-small">· {{ m.rows }} records waiting</span></div>
							<div class="bd-muted bd-small">{{ m.done ? 'Imported.' : m.ready ? 'Installed — ready to import.' : m.installed ? 'Installed, but it cannot import yet.' : 'Not installed yet. Install it, then come back here.' }}</div></div>
						<el-button v-if="!m.done" size="small" type="primary" :disabled="!m.ready || busy" @click="importModule( m )">Import</el-button>
						<el-tag v-else type="success" size="small">Done</el-tag>
					</li>
					<li v-if="!a.modules.length" class="bd-muted">Nothing else was in this backup.</li>
				</ul>
				<el-button :loading="busy" @click="people">Give people their new roles</el-button>
				<el-button :disabled="busy" @click="discard">Finish — remove the kept copy</el-button>
			</template>
		</template>
	</el-card>
</template>
