<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Paperclip, Plus, Search, ArrowLeft, Send } from 'lucide-vue-next';
import { api, errorMessage } from '../api';
import { store, navigate } from '../store';
import RemoteSelect from '../components/RemoteSelect.vue';
import { fmtDateTime } from '../util';

const props = defineProps( { threadId: { type: Number, default: 0 }, clientId: { type: [ String, Number ], default: 0 }, embedded: Boolean } );

const COLORS = [ '#7a5af8', '#0e9384', '#dd2590', '#e04f16', '#2970ff', '#4e5ba6', '#ca8504' ];
const color = ( n ) => { let h = 0; String( n ).split( '' ).forEach( ( c ) => { h = ( h * 31 + c.charCodeAt( 0 ) ) >>> 0; } ); return COLORS[ h % COLORS.length ]; };
const ini = ( n ) => { const p = String( n || '?' ).trim().split( /\s+/ ); return ( p[ 0 ][ 0 ] + ( p[ 1 ] ? p[ 1 ][ 0 ] : '' ) ).toUpperCase(); };
const toDate = ( t ) => new Date( String( t ).replace( ' ', 'T' ) + 'Z' );
const hm = ( t ) => toDate( t ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
const dayKey = ( t ) => { const x = toDate( t ); return `${ x.getFullYear() }-${ x.getMonth() }-${ x.getDate() }`; };
const dayLabel = ( t ) => {
	const x = toDate( t ), n = new Date(), y = new Date( Date.now() - 864e5 );
	const k = ( d ) => `${ d.getFullYear() }-${ d.getMonth() }-${ d.getDate() }`;
	return k( x ) === k( n ) ? 'Today' : k( x ) === k( y ) ? 'Yesterday' : x.toLocaleDateString( [], { weekday: 'short', day: 'numeric', month: 'short', year: x.getFullYear() === n.getFullYear() ? undefined : 'numeric' } );
};
const ago = ( t ) => {
	if ( ! t ) return '';
	const s = ( Date.now() - toDate( t ).getTime() ) / 1000;
	return s < 60 ? 'now' : s < 3600 ? Math.floor( s / 60 ) + 'm' : s < 86400 ? hm( t ) : s < 604800 ? toDate( t ).toLocaleDateString( [], { weekday: 'short' } ) : toDate( t ).toLocaleDateString( [], { day: 'numeric', month: 'short' } );
};
const size = ( b ) => ( b > 1048576 ? ( b / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( b / 1024 ) ) + ' KB' );
const ext = ( n ) => { const m = /\.([a-z0-9]+)$/i.exec( n ); return m ? m[ 1 ].slice( 0, 4 ).toUpperCase() : 'FILE'; };
const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
const linkify = ( s ) => esc( s ).replace( /(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>' );

const status = ref( 'open' );
const q = ref( '' );
const onlyUnread = ref( false );
const threads = ref( [] );
const cur = ref( 0 );
const detail = ref( null );
const msgs = ref( [] );
const otherRead = ref( 0 );
const typing = ref( '' );
const text = ref( '' );
const files = ref( [] );
const sending = ref( false );
const dragging = ref( false );
const lightbox = ref( '' );
const stream = ref( null );
const fileInput = ref( null );
const last = ref( 0 );

const clientFilter = computed( () => ( props.clientId ? Number( props.clientId ) : 0 ) );

async function loadThreads() {
	try {
		const r = await api.getPath( 'messages/threads', { status: status.value, q: q.value, unread: onlyUnread.value ? 1 : '', client_id: clientFilter.value || '' } );
		threads.value = r.threads;
		store.unread = r.unread;
	} catch ( e ) { /* keep what we have */ }
}

const atBottom = () => ! stream.value || stream.value.scrollHeight - stream.value.scrollTop - stream.value.clientHeight < 140;
const toBottom = () => nextTick( () => { if ( stream.value ) stream.value.scrollTop = stream.value.scrollHeight; } );

function apply( r, first ) {
	const stick = first || atBottom();
	detail.value = r.thread;
	otherRead.value = r.other_read;
	typing.value = r.typing;
	if ( first ) msgs.value = [];
	r.messages.forEach( ( m ) => {
		const i = msgs.value.findIndex( ( x ) => x.id === m.id );
		if ( i > -1 ) msgs.value.splice( i, 1, m ); else msgs.value.push( m );
		last.value = Math.max( last.value, m.id );
	} );
	store.unread = r.unread_total;
	if ( stick ) toBottom();
}

async function open( id, push = true ) {
	cur.value = id;
	last.value = 0;
	msgs.value = [];
	detail.value = null;
	if ( push && ! props.embedded ) navigate( '/messages/' + id );
	try {
		apply( await api.getPath( `messages/threads/${ id }` ), true );
		api.post( `messages/threads/${ id }/read` ).then( ( x ) => { store.unread = x.unread; loadThreads(); } ).catch( () => {} );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

function back() {
	cur.value = 0;
	detail.value = null;
	if ( ! props.embedded ) navigate( '/messages' );
}

async function poll() {
	if ( ! cur.value || document.hidden ) return;
	try {
		const r = await api.getPath( `messages/threads/${ cur.value }`, last.value ? { after: last.value } : {} );
		const had = r.messages.length;
		apply( r, false );
		if ( had ) api.post( `messages/threads/${ cur.value }/read` ).then( ( x ) => { store.unread = x.unread; } ).catch( () => {} );
	} catch ( e ) { /* next tick */ }
}

let t1, t2;
onMounted( async () => {
	await loadThreads();
	if ( props.threadId ) open( props.threadId, false );
	t1 = setInterval( poll, 4000 );
	t2 = setInterval( () => { if ( ! document.hidden ) loadThreads(); }, 15000 );
} );
onBeforeUnmount( () => { clearInterval( t1 ); clearInterval( t2 ); } );
watch( () => props.threadId, ( id ) => { if ( id && id !== cur.value ) open( id, false ); } );
watch( [ status, onlyUnread ], loadThreads );
let qt;
watch( q, () => { clearTimeout( qt ); qt = setTimeout( loadThreads, 250 ); } );

/* composing */
const MAX = 25 * 1048576;
function addFiles( list, target = files ) {
	Array.from( list ).forEach( ( f ) => {
		if ( target.value.length >= 10 ) { ElMessage.warning( 'You can attach up to 10 files.' ); return; }
		if ( f.size > MAX ) { ElMessage.warning( `“${ f.name }” is larger than 25 MB.` ); return; }
		target.value.push( { file: f, preview: /^image\//.test( f.type ) && f.size < 8e6 ? URL.createObjectURL( f ) : '' } );
	} );
}
const onPaste = ( e ) => { const fs = e.clipboardData && e.clipboardData.files; if ( fs && fs.length ) { e.preventDefault(); addFiles( fs ); } };
const onDrop = ( e ) => { dragging.value = false; addFiles( e.dataTransfer.files ); };
let lastType = 0;
function onInput( e ) {
	const t = e.target;
	t.style.height = 'auto';
	t.style.height = Math.min( t.scrollHeight, 160 ) + 'px';
	if ( cur.value && Date.now() - lastType > 3000 ) {
		lastType = Date.now();
		api.post( `messages/threads/${ cur.value }/typing` ).catch( () => {} );
	}
}
function onKey( e ) {
	if ( e.key === 'Enter' && ! e.shiftKey && ! e.isComposing ) {
		e.preventDefault();
		send();
	}
}
async function send() {
	if ( sending.value || ! cur.value ) return;
	const body = text.value.trim();
	if ( ! body && ! files.value.length ) return;
	sending.value = true;
	try {
		const fd = new FormData();
		fd.append( 'body', body );
		files.value.forEach( ( f ) => fd.append( 'files[]', f.file ) );
		await api.upload( `messages/threads/${ cur.value }/messages`, fd );
		text.value = '';
		files.value = [];
		apply( await api.getPath( `messages/threads/${ cur.value }`, last.value ? { after: last.value } : {} ), false );
		toBottom();
		loadThreads();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		sending.value = false;
	}
}
async function removeMsg( m ) {
	try {
		await ElMessageBox.confirm( 'Delete this message?', 'Delete', { type: 'warning', confirmButtonText: 'Delete' } );
	} catch ( e ) { return; }
	try {
		await api.del( `messages/messages/${ m.id }` );
		last.value = 0;
		apply( await api.getPath( `messages/threads/${ cur.value }` ), true );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}
async function setStatus( s ) {
	try {
		await api.post( `messages/threads/${ cur.value }/status`, { status: s } );
		apply( await api.getPath( `messages/threads/${ cur.value }`, { after: last.value } ), false );
		loadThreads();
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
}

/* new conversation */
const nd = reactive( { open: false, client: props.clientId ? Number( props.clientId ) : null, context: '', subject: '', body: '', files: [], busy: false, contexts: [] } );
const nfile = ref( null );
watch( () => nd.client, async ( c ) => {
	nd.context = '';
	nd.contexts = c ? await api.getPath( 'messages/contexts', { client_id: c } ).catch( () => [] ) : [];
} );
function openNew() {
	Object.assign( nd, { open: true, context: '', subject: '', body: '', files: [] } );
	if ( props.clientId ) nd.client = Number( props.clientId );
}
async function createThread() {
	if ( ! nd.client ) { ElMessage.warning( 'Choose who this is with.' ); return; }
	if ( ! nd.body.trim() && ! nd.files.length ) { ElMessage.warning( 'Write a message or attach a file.' ); return; }
	nd.busy = true;
	try {
		const fd = new FormData();
		fd.append( 'client_id', nd.client );
		fd.append( 'context', nd.context || '' );
		fd.append( 'subject', nd.subject );
		fd.append( 'body', nd.body );
		nd.files.forEach( ( f ) => fd.append( 'files[]', f.file ) );
		const r = await api.upload( 'messages/threads', fd );
		nd.open = false;
		status.value = 'open';
		await loadThreads();
		open( r.thread_id );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		nd.busy = false;
	}
}

const rows = computed( () => msgs.value.map( ( m, i ) => {
	const p = msgs.value[ i - 1 ];
	return { m, day: ! p || dayKey( p.at ) !== dayKey( m.at ) ? dayLabel( m.at ) : '', cont: !! p && m.kind !== 'system' && p.kind !== 'system' && p.side === m.side && p.name === m.name && dayKey( p.at ) === dayKey( m.at ) && toDate( m.at ) - toDate( p.at ) < 300000 };
} ) );
const lastMine = computed( () => { let id = 0; msgs.value.forEach( ( m ) => { if ( m.mine && m.kind !== 'system' ) id = m.id; } ); return id; } );
</script>

<template>
	<div :class="[ 'bdx', { 'bdx--embedded': embedded, 'is-chat': cur } ]">
		<aside class="bdx__list">
			<div class="bdx__lh"><h3>{{ embedded ? 'Conversations' : 'Client messages' }}</h3><el-button type="primary" size="small" @click="openNew"><Plus :size="15" /> New</el-button></div>
			<div class="bdx__tools">
				<el-input v-model="q" placeholder="Search conversations" clearable><template #prefix><Search :size="15" /></template></el-input>
				<div style="display: flex; gap: 8px; align-items: center">
					<el-radio-group v-model="status" size="small"><el-radio-button value="open">Open</el-radio-button><el-radio-button value="closed">Closed</el-radio-button></el-radio-group>
					<el-checkbox v-model="onlyUnread" size="small">Unread</el-checkbox>
				</div>
			</div>
			<div class="bdx__threads">
				<div v-if="!threads.length" class="bd-muted bd-small" style="padding: 30px 14px; text-align: center">{{ q || onlyUnread ? 'No conversation matches.' : status === 'open' ? 'No open conversations.' : 'Nothing closed.' }}</div>
				<button v-for="t in threads" :key="t.id" type="button" :class="[ 'bdx__t', { 'is-on': t.id === cur, 'is-unread': t.unread } ]" @click="open( t.id )">
					<span class="bdx__av" :style="{ background: color( t.client_name || t.subject ) }">{{ ini( t.client_name || t.subject ) }}</span>
					<span class="bdx__tb">
						<span class="bdx__tt"><strong>{{ t.client_name || t.subject }}</strong><time>{{ ago( t.last_message_at ) }}</time></span>
						<span class="bdx__tp">{{ t.subject }}<template v-if="t.preview"> — {{ t.preview }}</template></span>
						<span v-if="t.context_name" class="bdx__tag">{{ t.context_name }}</span>
					</span>
					<span v-if="t.unread" class="bdx__dot">{{ t.unread }}</span>
				</button>
			</div>
		</aside>

		<section class="bdx__conv">
			<div v-if="!cur" class="bdx__empty"><div style="font-size: 38px">💬</div><strong>Select a conversation</strong><p>Client messages and files arrive here. You can also start one with any client.</p></div>
			<template v-else>
				<header class="bdx__ch">
					<el-button class="bd-only-mobile" text circle @click="back"><ArrowLeft :size="18" /></el-button>
					<div class="bdx__ct"><strong>{{ detail ? detail.subject : '…' }}</strong><span v-if="detail" class="bd-muted bd-small"><a v-if="!embedded" :href="'#/clients/' + detail.client_id" class="bd-link">{{ detail.client_name }}</a><template v-if="detail.context_name"> · {{ detail.context_name }}</template></span></div>
					<el-button v-if="detail && detail.status === 'open'" size="small" @click="setStatus( 'closed' )">Close</el-button>
					<el-button v-else-if="detail" size="small" @click="setStatus( 'open' )">Reopen</el-button>
				</header>
				<div ref="stream" class="bdx__stream">
					<template v-for="r in rows" :key="r.m.id">
						<div v-if="r.day" class="bdx__day">{{ r.day }}</div>
						<div v-if="r.m.kind === 'system'" class="bdx__sys">{{ r.m.body }} · {{ hm( r.m.at ) }}</div>
						<div v-else :class="[ 'bdx__m', { 'is-mine': r.m.mine, 'is-cont': r.cont } ]">
							<span class="bdx__av" :style="{ background: r.m.side === 'staff' ? '#14161d' : color( r.m.name ) }">{{ ini( r.m.name ) }}</span>
							<div>
								<div v-if="!r.m.mine && !r.cont" class="bdx__nm"><b>{{ r.m.name }}</b> <span v-if="r.m.side === 'staff'" class="bd-muted">team</span></div>
								<div class="bdx__bub">
									<div v-if="r.m.deleted" class="bdx__txt" style="font-style: italic; opacity: 0.7">This message was deleted</div>
									<div v-else-if="r.m.body" class="bdx__txt" v-html="linkify( r.m.body )" />
									<div v-if="r.m.files.some( ( f ) => f.image )" class="bdx__imgs"><button v-for="f in r.m.files.filter( ( x ) => x.image )" :key="f.id" type="button" class="bdx__img" @click="lightbox = f.url + '&inline=1'"><img loading="lazy" :alt="f.name" :src="f.url + '&inline=1'"></button></div>
									<a v-for="f in r.m.files.filter( ( x ) => !x.image )" :key="f.id" class="bdx__f" :href="f.url"><span class="bdx__fi">{{ ext( f.name ) }}</span><span class="bdx__fn"><span>{{ f.name }}</span><span>{{ size( f.size ) }}</span></span></a>
									<div class="bdx__meta"><span>{{ hm( r.m.at ) }}</span><button v-if="r.m.can_delete" type="button" @click="removeMsg( r.m )">Delete</button></div>
								</div>
								<div v-if="r.m.mine && r.m.id === lastMine && otherRead >= r.m.id" class="bdx__seen">Seen</div>
							</div>
						</div>
					</template>
				</div>
				<div v-if="typing" class="bdx__typing">{{ typing }} is typing…</div>
				<div :class="[ 'bdx__composer', { 'is-drag': dragging } ]" @dragenter.prevent="dragging = true" @dragover.prevent @dragleave.self="dragging = false" @drop.prevent="onDrop">
					<div v-if="files.length" class="bdx__chips"><div v-for="( f, i ) in files" :key="i" class="bdx__chip"><img v-if="f.preview" :src="f.preview" alt=""><span>{{ f.file.name }} · {{ size( f.file.size ) }}</span><button type="button" aria-label="Remove" @click="files.splice( i, 1 )">×</button></div></div>
					<div class="bdx__row">
						<el-button circle @click="fileInput.click()"><Paperclip :size="17" /></el-button>
						<textarea v-model="text" rows="1" placeholder="Write a reply…  (Enter to send, Shift+Enter for a new line)" aria-label="Message" @input="onInput" @keydown="onKey" @paste="onPaste" />
						<el-button type="primary" :loading="sending" @click="send"><Send :size="15" /> Send</el-button>
					</div>
					<input ref="fileInput" type="file" multiple hidden @change="addFiles( $event.target.files ); $event.target.value = ''">
					<div class="bdx__drop">Drop files to attach</div>
				</div>
			</template>
		</section>

		<el-dialog v-model="nd.open" title="New conversation" width="520px" append-to-body>
			<el-form label-position="top">
				<el-form-item v-if="!clientId" :label="$fmt( '{Client}' )"><RemoteSelect v-model="nd.client" resource="clients" :label="( c ) => c.name" :placeholder="$fmt( 'Choose a {client}' )" style="width: 100%" /></el-form-item>
				<el-form-item label="About"><el-select v-model="nd.context" style="width: 100%"><el-option value="" label="General" /><el-option v-for="p in nd.contexts" :key="p.value" :value="p.value" :label="( p.group ? p.group + ': ' : '' ) + p.name" /></el-select></el-form-item>
				<el-form-item label="Subject"><el-input v-model="nd.subject" maxlength="190" placeholder="What is this about?" /></el-form-item>
				<el-form-item label="Message"><el-input v-model="nd.body" type="textarea" :rows="5" maxlength="5000" /></el-form-item>
				<div v-if="nd.files.length" class="bdx__chips"><div v-for="( f, i ) in nd.files" :key="i" class="bdx__chip"><span>{{ f.file.name }} · {{ size( f.file.size ) }}</span><button type="button" aria-label="Remove" @click="nd.files.splice( i, 1 )">×</button></div></div>
			</el-form>
			<template #footer><el-button @click="nfile.click()"><Paperclip :size="15" /> Attach</el-button><el-button @click="nd.open = false">Cancel</el-button><el-button type="primary" :loading="nd.busy" @click="createThread">Send</el-button></template>
			<input ref="nfile" type="file" multiple hidden @change="addFiles( $event.target.files, { value: nd.files } ); $event.target.value = ''">
		</el-dialog>

		<Teleport to="body"><div v-if="lightbox" class="bdx__lb" style="position: fixed; inset: 0; background: rgba(0,0,0,.85); display: grid; place-items: center; z-index: 4000" @click="lightbox = ''"><img :src="lightbox" alt="" style="max-width: 92vw; max-height: 88vh; border-radius: 8px"></div></Teleport>
	</div>
</template>
