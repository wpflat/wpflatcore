<script setup>
import ResourceTable from '../components/ResourceTable.vue';
import { teamConfig } from '../resources';
</script>

<template>
	<ResourceTable :config="teamConfig()" use-route-filters />
</template>
