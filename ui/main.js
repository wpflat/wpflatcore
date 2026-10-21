import * as Vue from 'vue';
import { createApp } from 'vue';
import * as ElementPlusRuntime from 'element-plus';
import 'element-plus/dist/index.css';
import 'element-plus/theme-chalk/dark/css-vars.css';
import './styles/theme.scss';
import './styles/mobile.scss';
import App from './App.vue';
import { api, errorMessage } from './api';
import { components } from './components-registry';
import { sdk } from './sdkInstance';
import { navigate, store } from './store';
import * as util from './util';
import Badge from './components/Badge.vue';
import ChartCard from './components/ChartCard.vue';
import FormField from './components/FormField.vue';
import PageHeader from './components/PageHeader.vue';
import RecordDrawer from './components/RecordDrawer.vue';
import RemoteSelect from './components/RemoteSelect.vue';
import ResourceTable from './components/ResourceTable.vue';
import SenderPicker from './components/SenderPicker.vue';
import SlotOutlet from './components/SlotOutlet.vue';
import StatCard from './components/StatCard.vue';
import StatusTag from './components/StatusTag.vue';
import ViewSwitch from './components/ViewSwitch.vue';
import AnalyticsBar from './components/AnalyticsBar.vue';
import Chart from './components/Chart.vue';
import AttachmentField from './components/AttachmentField.vue';
import LinkPicker from './components/LinkPicker.vue';
import LinkedRecords from './components/LinkedRecords.vue';
import BackupRestore from './components/BackupRestore.vue';
import MigrationCard from './components/MigrationCard.vue';
import FreshInstall from './components/FreshInstall.vue';
import { startMobile } from './mobile';

/**
 * Module plugins are built as separate bundles that share this page's Vue and Element Plus (they are not bundled
 * again). Everything a module may use is on window.WPFlatCore: the SDK (routes, slots, extension points, words), the
 * API client, the store, shared components and helpers, and the Element Plus message boxes.
 */
const ui = { ElMessage: ElementPlusRuntime.ElMessage, ElMessageBox: ElementPlusRuntime.ElMessageBox, ElNotification: ElementPlusRuntime.ElNotification };
window.Vue = Vue;
window.WPFlatCore = {
	...sdk,
	sdk,
	api,
	errorMessage,
	store,
	navigate,
	util,
	ui,
	components: { Badge, ChartCard, FormField, PageHeader, RecordDrawer, RemoteSelect, ResourceTable, SenderPicker, SlotOutlet, StatCard, StatusTag, ViewSwitch, AnalyticsBar, Chart, AttachmentField, LinkPicker, LinkedRecords },
};

// Core's own additions to the shared places modules also use.
sdk.registerSlot( 'settings.admin-tabs', { id: 'backup', label: 'Backup & restore', module: 'core', order: 10, component: BackupRestore } );
sdk.registerSlot( 'settings.admin-tabs', { id: 'migrate', label: 'Move from BizyDash', module: 'core', order: 20, component: MigrationCard } );
sdk.registerSlot( 'home.top', { id: 'fresh-install', module: 'core', order: 1, component: FreshInstall } );

sdk.registerQuickAdd( 'core', [ { label: 'New ' + sdk.t( 'client' ).toLowerCase(), route: '/clients?new=1' }, { label: 'New team member', route: '/team?new=1' } ] );

const el = document.getElementById( 'wpflatcore-root' );
if ( el ) {
	const app = createApp( App );
	components.forEach( ( c ) => app.use( c ) );
	Object.entries( window.WPFlatCore.components ).forEach( ( [ name, c ] ) => app.component( name, c ) );
	// Words the owner chose: {{ $tp('client') }}, $fmt('Add a {client}').
	app.config.globalProperties.$t = sdk.t;
	app.config.globalProperties.$tp = sdk.tp;
	app.config.globalProperties.$fmt = sdk.fmt;
	app.mount( el );
	startMobile( el, ( window.wpflatcoreBoot || {} ).workerUrl || '' );
}
