<script setup>
import { computed } from 'vue';
import { sdk } from '../sdkInstance';

/**
 * A named place where modules add content. Each registered entry renders its component with the context as props,
 * so a client page can offer "Billing" and "Work" tabs without knowing which modules exist.
 */
const props = defineProps( { name: { type: String, required: true }, ctx: { type: Object, default: () => ( {} ) } } );
const items = computed( () => sdk.slot( props.name, props.ctx ) );
</script>

<template>
	<template v-for="it in items" :key="it.id"><component :is="it.component" v-bind="ctx" /></template>
</template>
