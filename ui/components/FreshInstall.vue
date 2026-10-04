<script setup>
import { ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { h } from 'vue';
import { api } from '../api';
import { navigate, store } from '../store';
import { sdk } from '../sdkInstance';
import { copyText } from '../util';

/** Shown on Home of a brand-new install: bring your data over instead of starting from nothing. */
const hidden = ref( false );
const loading = ref( false );
const loadDemo = async () => {
	loading.value = true;
	try {
		await api.post( 'demo', {} );
		ElMessage.success( 'Sample data added' );
	} catch ( e ) {
		ElMessage.error( ( e && e.message ) || 'Could not add sample data' );
		loading.value = false;
		return;
	}
	let login = null;
	try {
		login = ( ( await api.getPath( 'demo' ) ) || {} ).login || null;
	} catch ( e ) {
		login = null;
	}
	if ( login ) {
		const row = ( label, value ) => h( 'p', { style: 'margin: 6px 0; word-break: break-all' }, [ h( 'span', { class: 'bd-muted' }, label + ': ' ), h( 'strong', value ), ' ', h( 'a', { href: '#', onClick: ( ev ) => { ev.preventDefault(); copyText( value ); ElMessage.success( 'Copied' ); } }, 'Copy' ) ] );
		try {
			await ElMessageBox( {
				title: 'Sample data is ready',
				message: h( 'div', [ h( 'p', sdk.fmt( 'Want to see what a {client} sees? Open the address below and sign in with this sample login.' ) ), row( 'Address', login.url ), row( 'Username', login.username ), row( 'Password', login.password ) ] ),
				confirmButtonText: 'Continue',
				showClose: false,
				closeOnClickModal: false,
				closeOnPressEscape: false,
			} );
		} catch ( e ) { /* closed: carry on */ }
	}
	window.location.reload();
};
const dismiss = async () => {
	hidden.value = true;
	try { await api.post( 'backups/fresh-dismiss', {} ); } catch ( e ) { /* it just shows again next time */ }
};
</script>

<template>
	<el-alert v-if="store.backupAlert" type="warning" show-icon :closable="false" class="bd-mb" title="The last automatic backup failed." :description="store.backupAlert">
	</el-alert>
	<el-card v-if="store.freshInstall && !hidden" shadow="never" class="bd-mb bd-welcome">
		<div class="bd-widget__head"><span>Welcome — starting fresh, or moving over?</span><a href="#" @click.prevent="dismiss">Hide</a></div>
		<p class="bd-muted bd-small">If you have a backup, restore it now and everything is back. Coming from the old BizyDash plugin? Bring its data across.</p>
		<el-button type="primary" @click="navigate( '/settings?tab=backup' )">Restore a backup</el-button>
		<el-button @click="navigate( '/settings?tab=migrate' )">Move from BizyDash</el-button>
		<el-button :loading="loading" @click="loadDemo">Try with sample data</el-button>
		<el-button text @click="dismiss">I’m starting fresh</el-button>
	</el-card>
</template>
