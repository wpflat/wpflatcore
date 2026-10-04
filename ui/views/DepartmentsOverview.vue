<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { api, errorMessage } from '../api';
import AnalyticsBar from '../components/AnalyticsBar.vue';
import PageHeader from '../components/PageHeader.vue';
import SlotOutlet from '../components/SlotOutlet.vue';
import { rangeFor } from '../analytics';
import { dept } from '../util';
import { navigate, store } from '../store';

const filters = reactive( { period: 'ytd', ...rangeFor( 'ytd' ) } );
const data = ref( null );
const loading = ref( true );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( 'departments/overview', { from: filters.from, to: filters.to } );
	} catch ( e ) {
		ElMessage.error( errorMessage( e ) );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ filters.from, filters.to ], load );

const open = ( id ) => navigate( '/departments/' + id );
const cards = computed( () => ( data.value ? data.value.departments : [] ) );
</script>

<template>
	<div v-loading="loading" class="bd-depts">
		<PageHeader :title="dept().many" :description="'Everything that belongs to each ' + dept().lower + '.'">
			<el-button v-if="store.canManage" @click="navigate( '/settings?tab=departments' )">Manage {{ dept().lowerMany }}</el-button>
		</PageHeader>
		<AnalyticsBar :model-value="filters" :show-department="false" @update:model-value="Object.assign( filters, $event )" />

		<p v-if="data && !cards.length" class="bd-muted">No {{ dept().lowerMany }} yet — add them in Settings.</p>
		<div class="bd-deptgrid">
			<a v-for="d in cards" :key="d.id" class="bd-deptcard" :href="'#/departments/' + d.id" @click.prevent="open( d.id )">
				<div class="bd-deptcard__head">
					<strong>{{ d.name }}</strong>
					<span v-if="d.description" class="bd-muted bd-small">{{ d.description }}</span>
				</div>
				<div class="bd-deptcard__nums">
					<span><b>{{ d.clients }}</b> {{ $tp( 'client' ).toLowerCase() }}</span>
					<span><b>{{ d.people }}</b> {{ $tp( 'team' ).toLowerCase() }}</span>
					<SlotOutlet name="department.card.counts" :ctx="{ card: d, department: d, filters }" />
				</div>
				<div class="bd-deptcard__money"><SlotOutlet name="department.card.figures" :ctx="{ card: d, department: d, filters }" /></div>
			</a>
		</div>
	</div>
</template>
