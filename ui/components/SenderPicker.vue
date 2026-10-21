<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api';

/** "Send from": the business sender, or the signed-in person's own address when it is on the same domain as the email settings. v-model is the address ('' = business sender). */
const props = defineProps( { modelValue: { type: String, default: '' }, label: { type: String, default: 'Send emails from' }, help: { type: String, default: '' } } );
const emit = defineEmits( [ 'update:modelValue' ] );
const options = ref( [] );
onMounted( async () => {
	try {
		options.value = await api.getPath( 'mail/senders' );
		if ( props.modelValue && ! options.value.some( ( o ) => o.key === 'me' && o.email === props.modelValue ) ) emit( 'update:modelValue', '' ); // no longer allowed
	} catch ( e ) { options.value = []; }
} );
</script>

<template>
	<div v-if="options.length" class="bd-sender">
		<label class="bd-lab">{{ label }}</label>
		<el-select v-if="options.length > 1" :model-value="modelValue" style="width: 100%" :aria-label="label" @update:model-value="emit( 'update:modelValue', $event )">
			<el-option v-for="o in options" :key="o.key" :value="o.key === 'default' ? '' : o.email" :label="o.label" />
		</el-select>
		<div v-else class="bd-muted bd-small">{{ options[ 0 ].label }}</div>
		<div v-if="options.length > 1" class="bd-help">{{ help || 'Pick your own address to send as yourself; replies then come to you. It is offered because it is on the same domain as the email settings.' }}</div>
	</div>
</template>
