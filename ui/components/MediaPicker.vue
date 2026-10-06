<script setup>
import { ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';

/** Pick an image from the WordPress Media Library (or add one to it). Emits the chosen { id, url }. */
const props = defineProps( { modelValue: { type: Boolean, default: false }, title: { type: String, default: 'Media Library' } } );
const emit = defineEmits( [ 'update:modelValue', 'pick' ] );
const items = ref( [] );
const search = ref( '' );
const page = ref( 1 );
const pages = ref( 1 );
const loading = ref( false );
const canAdd = ref( true );
const picked = ref( null );
const input = ref( null );
let timer = null;

const load = async ( reset ) => {
	if ( reset ) {
		page.value = 1;
		items.value = [];
	}
	loading.value = true;
	try {
		const r = await api.getPath( 'media-library', { search: search.value, page: page.value } );
		items.value = items.value.concat( r.items );
		pages.value = r.pages;
		canAdd.value = r.can_add;
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
watch( () => props.modelValue, ( on ) => {
	if ( on ) {
		picked.value = null;
		search.value = '';
		load( true );
	}
} );
const typing = () => {
	clearTimeout( timer );
	timer = setTimeout( () => load( true ), 350 );
};
const more = () => {
	page.value += 1;
	load( false );
};
const onFile = async ( e ) => {
	const f = e.target.files[ 0 ];
	e.target.value = '';
	if ( ! f ) return;
	loading.value = true;
	try {
		const fd = new FormData();
		fd.append( 'file', f );
		const row = await api.upload( 'media-library/upload', fd );
		items.value.unshift( row );
		picked.value = row;
	} catch ( err ) {
		ElMessage.error( errorMessage( err ) );
	} finally {
		loading.value = false;
	}
};
const use = () => {
	if ( ! picked.value ) return;
	emit( 'pick', { id: picked.value.id, url: picked.value.url } );
	emit( 'update:modelValue', false );
};
</script>

<template>
	<el-dialog :model-value="modelValue" :title="title" width="min(820px, 96vw)" append-to-body @update:model-value="( v ) => emit( 'update:modelValue', v )">
		<div class="bd-mp__bar">
			<el-input v-model="search" placeholder="Search the Media Library" clearable @input="typing" />
			<template v-if="canAdd">
				<input ref="input" type="file" hidden accept="image/*" @change="onFile">
				<el-button @click="input && input.click()">Upload new image</el-button>
			</template>
		</div>
		<div v-loading="loading" class="bd-mp__grid">
			<p v-if="! loading && ! items.length" class="bd-muted">No images found. {{ canAdd ? 'Upload one to add it to the Media Library.' : '' }}</p>
			<button v-for="it in items" :key="it.id" type="button" class="bd-mp__item" :class="{ 'is-on': picked && picked.id === it.id }" :title="it.title" @click="picked = it" @dblclick="picked = it; use()">
				<img :src="it.thumb" :alt="it.title" loading="lazy">
				<span>{{ it.title }}</span>
			</button>
		</div>
		<div v-if="page < pages" style="text-align:center;margin-top:10px"><el-button :loading="loading" @click="more">Load more</el-button></div>
		<template #footer>
			<el-button @click="emit( 'update:modelValue', false )">Cancel</el-button>
			<el-button type="primary" :disabled="! picked" @click="use">Use this image</el-button>
		</template>
	</el-dialog>
</template>

<style>
.bd-mp__bar { display: flex; gap: 8px; margin-bottom: 12px; }
.bd-mp__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; min-height: 120px; max-height: 52vh; overflow: auto; }
.bd-mp__item { border: 2px solid transparent; background: var(--el-fill-color-light, #f5f7fa); border-radius: 8px; padding: 6px; cursor: pointer; text-align: center; font: inherit; }
.bd-mp__item img { width: 100%; height: 84px; object-fit: contain; display: block; background: repeating-conic-gradient(#e6e8ec 0 25%, #fff 0 50%) 0 0 / 12px 12px; border-radius: 4px; }
.bd-mp__item span { display: block; font-size: 12px; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bd-mp__item.is-on { border-color: var(--el-color-primary, #2b6cff); }
</style>
