<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ArrowLeft } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import AnalyticsBar from '../components/AnalyticsBar.vue';
import ChartCard from '../components/ChartCard.vue';
import SlotOutlet from '../components/SlotOutlet.vue';
import StatCard from '../components/StatCard.vue';
import { rangeFor } from '../analytics';
import { dept, humanize, labelOf } from '../util';
import { navigate, store } from '../store';

const props = defineProps( { id: { type: [ String, Number ], required: true } } );
const filters = reactive( { period: 'ytd', ...rangeFor( 'ytd' ) } );
const data = ref( null );
const loading = ref( true );
const error = ref( '' );

const load = async () => {
	loading.value = true;
	try {
		data.value = await api.getPath( `departments/${ props.id }/overview`, { from: filters.from, to: filters.to } );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	} finally {
		loading.value = false;
	}
};
onMounted( load );
watch( () => [ props.id, filters.from, filters.to ], load );

const d = computed( () => data.value || {} );
const num = computed( () => Number( props.id ) );
/** Link into another list filtered to this department (id 0 = no filter). */
const q = ( route ) => ( num.value ? `${ route }${ route.includes( '?' ) ? '&' : '?' }department_id=${ num.value }` : route );
// What modules see when they add to this page.
const ctx = computed( () => ( { department: d.value, departmentId: num.value, filters, q } ) );
</script>

<template>
	<div v-loading="loading" class="bd-deptpage">
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<template v-if="data">
			<div class="bd-pagehead">
				<div>
					<el-button text :icon="ArrowLeft" @click="navigate( '/departments' )">All {{ dept().lowerMany }}</el-button>
					<h1>{{ d.name }}</h1>
					<p v-if="d.description" class="bd-muted">{{ d.description }}</p>
				</div>
				<div class="bd-pagehead__actions"><el-button v-if="store.canManage && num" @click="navigate( '/settings?tab=departments' )">Edit {{ dept().lower }}</el-button></div>
			</div>

			<AnalyticsBar :model-value="filters" :show-department="false" @update:model-value="Object.assign( filters, $event )" />

			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="6"><StatCard :label="$tp( 'client' )" :sub="'in this ' + dept().lower" :to="num ? '/clients?department_id=' + num : ''">{{ d.clients.length }}</StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard :label="$tp( 'team' )" sub="Active" :to="q( '/team' )">{{ d.people.length }}</StatCard></el-col>
				<SlotOutlet name="department.stats" :ctx="ctx" />
			</el-row>

			<div class="bd-angrid">
				<ChartCard :title="$tp( 'client' )" :empty="!d.clients.length" :empty-text="'No ' + $tp( 'client' ).toLowerCase() + ' are attached yet.'">
					<template #tools><a class="bd-link bd-small" :href="'#/clients?department_id=' + num" @click.prevent="navigate( '/clients?department_id=' + num )">View all</a></template>
					<el-table :data="d.clients.slice( 0, 8 )" size="small">
						<el-table-column :label="$t( 'client' )" min-width="180"><template #default="{ row }"><a class="bd-link" :href="'#/clients/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Type" min-width="110"><template #default="{ row }">{{ labelOf( 'client_type', row.type ) }}</template></el-table-column>
						<el-table-column label="Status" min-width="100"><template #default="{ row }">{{ labelOf( 'client_status', row.status ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
				<ChartCard :title="$tp( 'team' )" :empty="!d.people.length" :empty-text="'Nobody is in this ' + dept().lower + '.'">
					<template #tools><a class="bd-link bd-small" :href="'#' + q( '/team' )" @click.prevent="navigate( q( '/team' ) )">View all</a></template>
					<el-table :data="d.people" size="small">
						<el-table-column label="Person" min-width="180"><template #default="{ row }"><a class="bd-link" :href="'#/team/' + row.id">{{ row.name }}</a></template></el-table-column>
						<el-table-column label="Role" min-width="140" prop="role" />
						<el-table-column label="Type" min-width="100"><template #default="{ row }">{{ humanize( row.kind ) }}</template></el-table-column>
					</el-table>
				</ChartCard>
			</div>

			<SlotOutlet name="department.sections" :ctx="ctx" />
		</template>
	</div>
</template>
