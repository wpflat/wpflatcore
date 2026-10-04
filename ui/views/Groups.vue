<script setup>
import { computed } from 'vue';
import ResourceTable from '../components/ResourceTable.vue';
import { groupsConfig } from '../resources';

const config = computed( () => groupsConfig() );
</script>

<template>
	<div>
		<el-alert class="bd-mb" type="info" show-icon :closable="false" :title="$fmt( 'Link a {group} to a WordPress role (for example Teacher) and everyone with that role is in it automatically. Open a {group} to choose its roles.' )" />
		<ResourceTable :config="config" />
	</div>
</template>
