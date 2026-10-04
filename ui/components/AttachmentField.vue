<script setup>
import { ref, computed } from 'vue';
import { Paperclip } from '@element-plus/icons-vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';

const props = defineProps( { modelValue: { type: Number, default: 0 }, url: { type: String, default: '' }, title: { type: String, default: 'Choose file' } } );
const emit = defineEmits( [ 'update:modelValue', 'update:url' ] );
const name = ref( '' );
const busy = ref( false );
const input = ref( null );
const shownUrl = computed( () => ( name.value ? '' : props.url ) );

/** Upload straight to Bizy's own files folder (no WordPress media library involved). */
const pick = () => input.value && input.value.click();
const onFile = async ( e ) => {
	const f = e.target.files[ 0 ];
	e.target.value = '';
	if ( ! f ) return;
	busy.value = true;
	try {
		const fd = new FormData();
		fd.append( 'file', f );
		const r = await api.upload( 'files/upload', fd );
		name.value = r.name;
		emit( 'update:modelValue', r.id );
		emit( 'update:url', r.url );
	} catch ( err ) {
		ElMessage.error( errorMessage( err ) );
	} finally {
		busy.value = false;
	}
};
const clear = () => {
	name.value = '';
	emit( 'update:modelValue', 0 );
	emit( 'update:url', '' );
};
</script>

<template>
	<div class="bd-attach">
		<input ref="input" type="file" hidden accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" @change="onFile">
		<template v-if="modelValue">
			<el-icon><Paperclip /></el-icon>
			<a v-if="shownUrl" :href="shownUrl" target="_blank" rel="noreferrer">View file</a>
			<span v-else>{{ name || 'Attachment #' + modelValue }}</span>
			<el-button size="small" :loading="busy" @click="pick">Replace</el-button>
			<el-button size="small" text type="danger" @click="clear">Remove</el-button>
		</template>
		<template v-else>
			<el-button size="small" :icon="Paperclip" :loading="busy" @click="pick">Choose file</el-button>
		</template>
	</div>
</template>
