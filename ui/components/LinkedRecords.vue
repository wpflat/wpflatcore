<script setup>
import { onMounted, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import LinkPicker from './LinkPicker.vue';

/**
 * Everything linked to one record, from any module, with "Link…" and remove. Put it on any record's page:
 *   <LinkedRecords type="invoice" :id="12" :allow="[ 'project', 'task' ]" />
 * Kinds from modules that are not installed never show up, and the links return if the module does.
 */
const props = defineProps( { type: { type: String, required: true }, id: { type: [ Number, String ], required: true }, allow: { type: Array, default: () => [] }, title: { type: String, default: 'Linked records' } } );
const emit = defineEmits( [ 'changed' ] );

const items = ref( [] );
const open = ref( false );
const error = ref( '' );

const load = async () => {
	try {
		items.value = await api.getPath( 'links', { type: props.type, id: props.id } );
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
onMounted( load );
watch( () => [ props.type, props.id ], load );

const pick = async ( p ) => {
	try {
		await api.post( 'links', { from_type: props.type, from_id: Number( props.id ), to_type: p.type, to_id: p.id } );
		open.value = false;
		await load();
		emit( 'changed' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
const remove = async ( it ) => {
	try {
		await api.del( `links?from_type=${ props.type }&from_id=${ props.id }&to_type=${ it.type }&to_id=${ it.id }&kind=${ it.kind || 'related' }` );
		await load();
		emit( 'changed' );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	}
};
</script>

<template>
	<el-card shadow="never" class="bd-widget">
		<template #header><div class="bd-widget__head"><span>{{ title }}</span><a href="#" @click.prevent="open = true">Link…</a></div></template>
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" />
		<ul class="bd-list">
			<li v-for="it in items" :key="it.link_id">
				<div>
					<div class="bd-strong"><span class="bd-badge bd-badge--neutral bd-badge--plain">{{ it.label }}</span> <a v-if="it.url" :href="it.url">{{ it.title }}</a><span v-else>{{ it.title }}</span></div>
					<div v-if="it.sub" class="bd-muted bd-small">{{ it.sub }}</div>
				</div>
				<el-button text size="small" @click="remove( it )">Remove</el-button>
			</li>
			<li v-if="!items.length" class="bd-muted">Nothing linked yet.</li>
		</ul>
		<LinkPicker v-model="open" :from="{ type, id }" :allow="allow" @pick="pick" />
	</el-card>
</template>
