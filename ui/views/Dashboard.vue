<script setup>
import { ref, computed, onMounted } from 'vue';
import { CircleCheckFilled, CircleCheck } from '@element-plus/icons-vue';
import { api, errorMessage } from '../api';
import { store, navigate } from '../store';
import PageHeader from '../components/PageHeader.vue';
import SlotOutlet from '../components/SlotOutlet.vue';
import StatCard from '../components/StatCard.vue';

const data = ref( null );
const error = ref( '' );

const load = async () => {
	try {
		data.value = await api.getPath( 'home' );
		error.value = '';
	} catch ( e ) {
		error.value = errorMessage( e );
	}
};
onMounted( load );

const first = ( store.user || '' ).includes( '@' ) ? '' : ( store.user || '' ).split( ' ' )[ 0 ];
const hour = new Date().getHours();
const greeting = `${ hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening' }${ first ? ', ' + first : '' }`;
const todayLabel = new Date().toLocaleDateString( undefined, { weekday: 'long', month: 'long', day: 'numeric' } );

const summary = computed( () => {
	if ( ! data.value ) return '';
	const n = data.value.todo_total || 0;
	return n ? `You have ${ n } thing${ n === 1 ? '' : 's' } to do today.` : 'You’re all caught up for today.';
} );
const dueText = ( d ) => ( d === null || d === undefined ? '' : d < 0 ? `${ -d } day${ d === -1 ? '' : 's' } late` : d === 0 ? 'Today' : `In ${ d } day${ d === 1 ? '' : 's' }` );
const groups = computed( () => {
	const g = [ { label: 'This week', items: [] }, { label: 'Next week', items: [] }, { label: 'Later', items: [] } ];
	( ( data.value && data.value.coming_up ) || [] ).forEach( ( i ) => g[ i.days <= 7 ? 0 : i.days <= 14 ? 1 : 2 ].items.push( i ) );
	return g.filter( ( x ) => x.items.length );
} );

const setup = computed( () => ( data.value ? data.value.setup : [] ) );
const setupDone = computed( () => setup.value.filter( ( s ) => s.done ).length );
const dismissed = ref( ( () => { try { return localStorage.getItem( 'bizycore_setup_dismissed' ) === '1'; } catch ( e ) { return false; } } )() );
const dismissSetup = () => {
	dismissed.value = true;
	try { localStorage.setItem( 'bizycore_setup_dismissed', '1' ); } catch ( e ) { /* ignore */ }
};
const showSetup = computed( () => data.value && ! dismissed.value && setupDone.value < setup.value.length );
const ctx = computed( () => ( { home: data.value, reload: load } ) );
</script>

<template>
	<div class="bd-home">
		<PageHeader :title="greeting" :description="todayLabel + ( summary ? ' · ' + summary : '' )" />
		<el-alert v-if="error" :title="error" type="error" show-icon :closable="false" class="bd-mb" />
		<el-skeleton v-if="!data && !error" :rows="6" animated />

		<SlotOutlet name="home.top" :ctx="ctx" />

		<template v-if="data">
			<el-card v-if="showSetup" shadow="never" class="bd-mb bd-setup">
				<div class="bd-widget__head"><span>Get set up — {{ setupDone }} of {{ setup.length }} done</span><a href="#" @click.prevent="dismissSetup">Hide</a></div>
				<ul class="bd-setup__list">
					<li v-for="st in setup" :key="st.key" :class="{ 'is-done': st.done }">
						<el-icon><CircleCheckFilled v-if="st.done" /><CircleCheck v-else /></el-icon>
						<a href="#" @click.prevent="navigate( st.to )">{{ st.label }}</a>
					</li>
				</ul>
			</el-card>

			<el-row :gutter="16" class="bd-mb">
				<el-col :xs="12" :md="6"><StatCard :label="$tp( 'client' )" sub="Active" to="/clients">{{ data.stats.clients }}</StatCard></el-col>
				<el-col :xs="12" :md="6"><StatCard :label="$tp( 'team' )" sub="Active" to="/team">{{ data.stats.team }}</StatCard></el-col>
				<SlotOutlet name="home.stats" :ctx="ctx" />
			</el-row>

			<el-row :gutter="20">
				<el-col :xs="24" :lg="12">
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>To do today <span v-if="data.todo_total" class="bd-tabcount">{{ data.todo_total }}</span></span></div></template>
						<ul class="bd-list">
							<li v-for="( t, i ) in data.todo" :key="i">
								<div>
									<div class="bd-strong"><a v-if="t.link" class="bd-link" :href="'#' + t.link">{{ t.title }}</a><span v-else>{{ t.title }}</span></div>
									<div v-if="t.sub" class="bd-muted bd-small">{{ t.sub }}</div>
								</div>
								<span :class="[ 'bd-small', t.days < 0 ? 'bd-late' : 'bd-muted' ]">{{ dueText( t.days ) }}</span>
							</li>
							<li v-if="!data.todo.length" class="bd-muted">Nothing is due today. Enjoy the quiet.</li>
						</ul>
					</el-card>
				</el-col>
				<el-col :xs="24" :lg="12">
					<el-card shadow="never" class="bd-widget bd-mb">
						<template #header><div class="bd-widget__head"><span>Coming up</span></div></template>
						<div v-for="g in groups" :key="g.label" class="bd-mb">
							<div class="bd-muted bd-small bd-strong">{{ g.label }}</div>
							<ul class="bd-list">
								<li v-for="( i, k ) in g.items" :key="k">
									<div>
										<div class="bd-strong"><a v-if="i.link" class="bd-link" :href="'#' + i.link">{{ i.title }}</a><span v-else>{{ i.title }}</span></div>
										<div v-if="i.sub" class="bd-muted bd-small">{{ i.sub }}</div>
									</div>
									<span class="bd-small bd-muted">{{ dueText( i.days ) }}</span>
								</li>
							</ul>
						</div>
						<p v-if="!groups.length" class="bd-muted">Nothing coming up soon.</p>
					</el-card>
				</el-col>
			</el-row>

			<SlotOutlet name="home.panels" :ctx="ctx" />
		</template>
	</div>
</template>
