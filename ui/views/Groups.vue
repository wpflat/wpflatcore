<script setup>
import { computed } from 'vue';
import ResourceTable from '../components/ResourceTable.vue';
import { groupsConfig } from '../resources';

const config = computed( () => groupsConfig() );
</script>

<template>
	<ResourceTable :config="config" />
</template>
