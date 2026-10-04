<script setup>
import { ref, onMounted, computed } from 'vue';
import { api } from '../api';
import ResourceTable from '../components/ResourceTable.vue';
import { clientsConfig } from '../resources';

const departments = ref( [] );
onMounted( async () => {
	try {
		departments.value = ( await api.list( 'departments', { per_page: 200 } ) ).items;
	} catch ( e ) {
		departments.value = [];
	}
} );
const config = computed( () => clientsConfig( departments.value ) );
</script>

<template>
	<ResourceTable :config="config" use-route-filters />
</template>
