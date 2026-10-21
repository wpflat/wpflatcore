<script setup>
import { ref, watch, computed } from 'vue';
import { api, errorMessage } from '../api';

/**
 * "Link to…": pick a kind of record (only kinds from installed modules appear) and search for the one to attach.
 * Emits `pick` with { type, id, title }.
 */
const props = defineProps( { modelValue: Boolean, from: { type: Object, required: true }, allow: { type: Array, default: () => [] } } );
const emit = defineEmits( [ 'update:modelValue', 'pick' ] );

const types = ref( {} );
const type = ref( '' );
const q = ref( '' );
const results = ref( [] );
const busy = ref( false );
const error = ref( '' );
let timer = null;

const choices = computed( () => Object.entries( types.value ).filter( ( [ k ] ) => k !== props.from.type && ( ! props.allow.length || props.allow.includes( k ) ) ) );

const search = async () => {
	if ( ! type.value ) return;
	busy.value = true;
	error.value = '';
	try {
		results.value = await api.getPath( 'links/search', { type: type.value, q: q.value, limit: 12 } );
	} catch ( e ) {
		error.value = errorMessage( e );
	}
	busy.value = false;
};

watch( () => props.modelValue, async ( open ) => {
	if ( ! open ) return;
	try {
		types.value = await api.getPath( 'links/types' );
		if ( ! type.value || ! choices.value.find( ( [ k ] ) => k === type.value ) ) {
			type.value = choices.value.length ? choices.value[ 0 ][ 0 ] : '';
		}
		q.value = '';
		search();
	} catch ( e ) {
		error.value = errorMessage( e );
	}
} );
watch( type, search );
watch( q, () => {
	clearTimeout( timer );
	timer = setTimeout( search, 250 );
} );
</script>

<template>
	<el-dialog :model-value="modelValue" title="Link to…" width="460px" @update:model-value="emit( 'update:modelValue', $event )">
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" style="margin-bottom: 12px" />
		<el-empty v-if="!choices.length" description="Nothing else can be linked yet. Install another WPFlat module to link records across." :image-size="64" />
		<template v-else>
			<el-radio-group v-model="type" size="small" style="margin-bottom: 10px">
				<el-radio-button v-for="[ k, d ] in choices" :key="k" :value="k">{{ d.label }}</el-radio-button>
			</el-radio-group>
			<el-input v-model="q" placeholder="Search…" clearable />
			<ul v-loading="busy" class="bd-list" style="margin-top: 10px; max-height: 280px; overflow: auto">
				<li v-for="r in results" :key="r.id">
					<div><div class="bd-strong">{{ r.title }}</div><div v-if="r.sub" class="bd-muted bd-small">{{ r.sub }}</div></div>
					<el-button size="small" @click="emit( 'pick', { type, id: r.id, title: r.title } )">Link</el-button>
				</li>
				<li v-if="!results.length && !busy" class="bd-muted">No matches.</li>
			</ul>
		</template>
	</el-dialog>
</template>
