<script setup>
import { computed } from 'vue';
import { Plus } from 'lucide-vue-next';
import { store, routeModule, navigate } from '../store';
import { sdk } from '../sdkInstance';

/** What the "+" button creates, for the app you are in. */
const mod = computed( () => routeModule( ( store.route.path || '/' ).split( '/' ).filter( Boolean )[ 0 ] || '' ) );
const actions = computed( () => sdk.quickFor( mod.value ).filter( ( a ) => ! a.when || a.when() ) );
const pick = ( a ) => {
	store.quickOpen = false;
	if ( a.onClick ) {
		a.onClick();
	} else {
		navigate( a.route );
	}
};
</script>

<template>
	<el-drawer :model-value="store.quickOpen" direction="btt" size="auto" :with-header="false" append-to-body class="bd-sheet" @update:model-value="store.quickOpen = $event">
		<div class="bd-sheet__grab" aria-hidden="true" />
		<div class="bd-sheet__head"><h2>Create</h2><button type="button" class="bd-sheet__done" @click="store.quickOpen = false">Close</button></div>
		<div class="bd-sheet__group">
			<button v-for="a in actions" :key="a.label" type="button" class="bd-sheet__row" @click="pick( a )"><span>{{ a.label }}</span><Plus :size="18" :stroke-width="2" /></button>
		</div>
	</el-drawer>
</template>
