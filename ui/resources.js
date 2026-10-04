/**
 * Table + form configuration for Core's resources (departments, clients, contacts, team, activity, lookups),
 * consumed by ResourceTable.vue. Modules add columns, fields and filters through the SDK extension points:
 *   client.fields, client.columns, client.filters, team.fields, team.columns, team.filters, activity.types
 * Each extension is { id, module, order?, field | column | filter | type }.
 */
import { ElMessage } from 'element-plus';
import { h } from 'vue';
import { api, errorMessage } from './api';
import { sdk } from './sdkInstance';
import { navigate, store } from './store';
import { avatarStyle, dept, humanize, initials, options } from './util';

const ACTIVE_STATUSES = [
	{ value: 'active', label: 'Active' },
	{ value: 'inactive', label: 'Inactive' },
];
const muted = ( t ) => h( 'span', { class: 'bd-muted' }, t );
const t = ( k ) => sdk.t( k );
const tp = ( k ) => sdk.tp( k );
/** Base items merged with what modules contribute, ordered. */
const merged = ( base, point, key ) => [ ...base.map( ( x, i ) => ( { order: ( i + 1 ) * 10, item: x } ) ), ...sdk.extensions( point ).map( ( e ) => ( { order: e.order, item: e[ key ] } ) ) ].sort( ( a, b ) => a.order - b.order ).map( ( x ) => x.item );

/* ───────────── Departments ───────────── */

export const departmentsConfig = () => ( {
	title: dept().many,
	description: `The ${ dept().lowerMany } of your business — tag each ${ t( 'client' ).toLowerCase() } with the ones they belong to, and filter your numbers by ${ dept().lower }.`,
	resource: 'departments',
	singular: dept().lower,
	newLabel: `Add ${ dept().lower }`,
	filters: [
		{ name: 'type', label: 'Type', options: options( 'department_type' ) },
		{ name: 'status', label: 'Status', options: ACTIVE_STATUSES },
	],
	defaults: { status: 'active', type: ( options( 'department_type' )[ 0 ] || {} ).value || '' },
	actionsWidth: 96,
	actions: ( r ) => [ { label: 'Overview', type: 'primary', onClick: () => navigate( `/departments/${ r.id }` ) } ],
	columns: [
		{ type: 'logo', label: '', width: 64 },
		{ prop: 'name', label: dept().one, type: 'strong', minWidth: 160 },
		{ prop: 'type', label: 'Type', type: 'lookup', lookup: 'department_type' },
		{ prop: 'description', label: 'Description', minWidth: 200 },
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: [
		{ name: 'name', label: `${ dept().one } name`, required: true, wide: true },
		{ name: 'type', label: `${ dept().one } type`, type: 'select', required: true, options: () => options( 'department_type' ) },
		{ name: 'status', label: 'Status', type: 'select', required: true, options: ACTIVE_STATUSES },
		{ name: 'description', label: 'Description', type: 'textarea', wide: true },
		{ name: 'logo_attachment_id', label: 'Logo', type: 'attachment', urlKey: 'logo_url', wide: true },
	],
} );

/* ───────────── Groups (Classes, Cohorts, Batches… — whatever the owner calls them) ───────────── */

export const groupsConfig = () => ( {
	title: tp( 'group' ),
	description: sdk.fmt( 'Put your {clients} into {groups} — a class, a cohort, a batch — and see who belongs where.' ),
	resource: 'groups',
	singular: t( 'group' ).toLowerCase(),
	newLabel: `Add ${ t( 'group' ).toLowerCase() }`,
	emptyText: `No ${ tp( 'group' ).toLowerCase() } yet.`,
	initialFilters: { status: 'active' },
	filters: [ { name: 'status', label: 'Status', options: ACTIVE_STATUSES } ],
	defaults: { status: 'active', color: '#6366f1' },
	actionsWidth: 96,
	actions: ( r ) => [ { label: 'Open', type: 'primary', onClick: () => navigate( `/groups/${ r.id }` ) } ],
	columns: [
		{
			label: t( 'group' ),
			minWidth: 220,
			render: ( r ) => h( 'a', { class: 'bd-strong bd-link bd-cell-group', href: `#/groups/${ r.id }` }, [ h( 'span', { class: 'bd-swatch', style: { background: r.color || '#6b7280' } } ), r.name ] ),
		},
		{ prop: 'description', label: 'Description', minWidth: 220 },
		{ label: tp( 'client' ), minWidth: 110, render: ( r ) => String( r.member_count || 0 ) },
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: groupFields(),
} );

export const groupFields = () => [
	{ name: 'name', label: `${ t( 'group' ) } name`, required: true, wide: true },
	{ name: 'description', label: 'Description', type: 'textarea', wide: true },
	{ name: 'color', label: 'Colour', type: 'color' },
	{ name: 'status', label: 'Status', type: 'select', required: true, options: ACTIVE_STATUSES },
];

/* ───────────── Clients (Customers, Students, Learners… — whatever the owner calls them) ───────────── */

export const clientFields = () =>
	merged(
		[
			{ name: 'name', label: `${ t( 'client' ) } name`, required: true, wide: true },
			{ name: 'type', label: `${ t( 'client' ) } type`, type: 'select', required: true, options: () => options( 'client_type' ) },
			{ name: 'status', label: 'Status', type: 'select', required: true, options: () => options( 'client_status' ) },
			{ name: 'department_ids', label: dept().many, type: 'departments', wide: true },
			{ name: 'group_ids', label: tp( 'group' ), type: 'groups', wide: true },
			{ name: 'contact_person', label: 'Primary contact name', show: ( v, isNew ) => isNew, help: `You can add more contacts (and set who is copied) from the ${ t( 'client' ).toLowerCase() } page.` },
			{ name: 'email', label: 'Primary contact email', type: 'email', show: ( v, isNew ) => isNew },
			{ name: 'phone', label: 'Primary contact phone', show: ( v, isNew ) => isNew },
			{ name: 'address', label: 'Address', type: 'textarea', wide: true },
			{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
		],
		'client.fields',
		'field'
	);

/** "Ada Obi · ada@x.com  +2 more" from a client's contacts (primary first). */
const contactLine = ( r ) => {
	const list = r.contacts && r.contacts.length ? r.contacts : [];
	const first = list.find( ( c ) => c.kind === 'primary' ) || list[ 0 ];
	if ( ! first ) {
		return [ r.contact_person, r.email ].filter( Boolean ).join( ' · ' ) || '—';
	}
	return [ first.name, first.email ].filter( Boolean ).join( ' · ' ) + ( list.length > 1 ? `  +${ list.length - 1 } more` : '' );
};

export const clientDefaults = () => {
	const d = { type: ( options( 'client_type' )[ 0 ] || {} ).value || 'retainer', status: 'active', department_ids: [], group_ids: [] };
	sdk.extensions( 'client.fields' ).forEach( ( e ) => {
		if ( e.default !== undefined ) {
			d[ e.field.name ] = typeof e.default === 'function' ? e.default() : e.default;
		}
	} );
	return d;
};

export const clientsConfig = ( departments = [] ) => ( {
	title: tp( 'client' ),
	description: `Everyone you serve. Open ${ /^[aeiou]/i.test( t( 'client' ) ) ? 'an' : 'a' } ${ t( 'client' ).toLowerCase() } for their details, contacts and history.`,
	resource: 'clients',
	bulkDepartment: true,
	bulkDepartmentMulti: true,
	singular: t( 'client' ).toLowerCase(),
	newLabel: `Add ${ t( 'client' ).toLowerCase() }`,
	initialFilters: { status: 'active' },
	filters: [
		{ name: 'type', label: 'Type', options: options( 'client_type' ) },
		{ name: 'department_id', label: dept().one, options: departments.map( ( b ) => ( { value: b.id, label: b.name } ) ) },
		{ name: 'group_id', label: t( 'group' ), remote: { resource: 'groups', label: ( g ) => g.name, params: { status: 'active' } } },
		{ name: 'status', label: 'Status', options: options( 'client_status', true ) },
		...sdk.extensions( 'client.filters' ).map( ( e ) => e.filter ),
	],
	defaults: clientDefaults(),
	columns: [
		{
			label: t( 'client' ),
			minWidth: 260,
			render: ( r ) =>
				h( 'a', { class: 'bd-cell-client', href: `#/clients/${ r.id }` }, [
					h( 'span', { class: 'bd-ava', style: avatarStyle( r.name ) }, initials( r.name ) ),
					h( 'div', [ h( 'div', { class: 'bd-strong' }, r.name ), h( 'div', { class: 'bd-muted bd-small' }, contactLine( r ) ) ] ),
				] ),
		},
		{ prop: 'type', label: 'Type', type: 'lookup', lookup: 'client_type' },
		{ label: dept().many, type: 'departments', minWidth: 220 },
		{ label: tp( 'group' ), type: 'groups', minWidth: 180 },
		...sdk.extensions( 'client.columns' ).map( ( e ) => e.column ),
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: clientFields(),
} );

/* ───────────── Activity and lookups ───────────── */

export const activityConfig = ( { embedded } = {} ) => ( {
	title: 'Activity',
	description: 'A running history of everything that changes.',
	resource: 'activity',
	singular: 'entry',
	readOnly: true,
	perPage: embedded ? 10 : 30,
	emptyText: 'No activity yet.',
	filters: embedded
		? []
		: [ { name: 'entity_type', label: 'Type', options: [ { value: 'clients', label: tp( 'client' ) }, { value: 'departments', label: dept().many }, { value: 'team', label: tp( 'team' ) }, ...sdk.extensions( 'activity.types' ).map( ( e ) => e.type ) ].map( ( o ) => ( { value: o.value, label: o.label || humanize( o.value ) } ) ) } ],
	columns: [
		{ prop: 'created_at', label: 'When', type: 'datetime', minWidth: 140 },
		...( embedded ? [] : [ { prop: 'client_name', label: t( 'client' ), type: 'client', minWidth: 160 } ] ),
		{ prop: 'summary', label: 'What happened', minWidth: 300 },
		{ prop: 'user_name', label: 'By' },
	],
} );

export const lookupsConfig = ( type ) => ( {
	resource: 'lookups',
	singular: 'option',
	newLabel: 'Add option',
	searchable: false,
	perPage: 100,
	defaults: { is_active: true, sort_order: 50, color: '#6b7280', months: 0 },
	columns: [
		{ prop: 'sort_order', label: '#', width: 60 },
		{ prop: 'label', label: 'Label', type: 'strong', minWidth: 160 },
		{ prop: 'item_key', label: 'Key', type: 'code' },
		{ prop: 'color', label: 'Colour', type: 'swatch', width: 80 },
		...sdk.extensions( 'lookup.columns', { type } ).map( ( e ) => e.column ),
		{ prop: 'is_active', label: 'Active', type: 'yesno', width: 80 },
		{ prop: 'is_system', label: 'Built-in', render: ( r ) => ( r.is_system ? 'Yes' : '' ), width: 90 },
	],
	fields: [
		{ name: 'label', label: 'Label', required: true },
		{ name: 'sort_order', label: 'Order', type: 'number', step: '1' },
		{ name: 'color', label: 'Colour', type: 'color' },
		...sdk.extensions( 'lookup.fields', { type } ).map( ( e ) => e.field ),
		{ name: 'is_active', label: 'Active', type: 'checkbox', checkLabel: 'Available in dropdowns' },
	],
} );

/* ───────────── Contacts ───────────── */

export const contactsConfig = () => ( {
	resource: 'contacts',
	singular: 'contact',
	newLabel: 'Add contact',
	searchable: false,
	perPage: 50,
	emptyText: 'No contacts yet — add the people who should receive documents.',
	defaults: { kind: 'secondary' },
	actionsWidth: 120,
	actions: ( r, ctx ) => [
		{
			label: r.kind === 'primary' ? 'Make secondary' : 'Make primary',
			menu: true,
			onClick: async () => {
				try {
					await api.update( 'contacts', r.id, { kind: r.kind === 'primary' ? 'secondary' : 'primary' } );
					ElMessage.success( 'Updated' );
					ctx.reload();
				} catch ( e ) {
					ElMessage.error( errorMessage( e ) );
				}
			},
		},
	],
	columns: [
		{
			label: 'Contact',
			minWidth: 230,
			render: ( r ) =>
				h( 'div', { class: 'bd-cell-client' }, [
					h( 'span', { class: 'bd-ava', style: avatarStyle( r.name ) }, initials( r.name ) ),
					h( 'div', [ h( 'div', { class: 'bd-strong' }, r.name ), r.job_title ? h( 'div', { class: 'bd-muted bd-small' }, r.job_title ) : null ] ),
				] ),
		},
		{ prop: 'email', label: 'Email', minWidth: 220 },
		{ prop: 'phone', label: 'Phone', minWidth: 130 },
		{
			label: 'Documents',
			minWidth: 170,
			render: ( r ) => h( 'span', { class: [ 'bd-badge', r.kind === 'primary' ? 'bd-badge--info' : 'bd-badge--neutral' ] }, r.kind === 'primary' ? 'Primary · receives' : 'Secondary · copied' ),
		},
	],
	fields: [
		{ name: 'name', label: 'Full name', required: true, wide: true },
		{ name: 'job_title', label: 'Job title', placeholder: 'e.g. Accountant, COO' },
		{ name: 'phone', label: 'Phone' },
		{ name: 'email', label: 'Email', type: 'email', required: true, wide: true },
		{
			name: 'kind',
			label: 'Role on documents',
			type: 'select',
			required: true,
			wide: true,
			options: [
				{ value: 'primary', label: 'Primary — receives invoices, contracts and other documents' },
				{ value: 'secondary', label: 'Secondary — copied (Cc)' },
			],
			help: `A ${ t( 'client' ).toLowerCase() } can have several primary contacts (for example the accountant and the COO).`,
		},
	],
} );

/* ───────────── Team ───────────── */

const KIND_LABEL = { employee: 'Employee', contractor: 'Contractor' };

export const teamFields = () =>
	merged(
		[
			{ name: 'name', label: 'Name', required: true, wide: true },
			{ name: 'email', label: 'Email', type: 'email', required: true },
			{ name: 'role', label: 'Role', placeholder: 'e.g. Designer, Account manager' },
			{ name: 'kind', label: 'Type', type: 'select', options: [ { value: 'employee', label: 'Employee' }, { value: 'contractor', label: 'Contractor' } ] },
			{ name: 'department_id', label: dept().one, type: 'remote', remote: { resource: 'departments', label: ( b ) => b.name, params: () => ( { status: 'active' } ) } },
			{ name: 'start_date', label: 'Start date', type: 'date' },
			{ name: 'end_date', label: 'End date', type: 'date', help: 'Leave empty while they are still with you.' },
			{ name: 'cc_documents', label: 'Document emails', type: 'checkbox', checkLabel: 'Copy (Cc) this person on every document email', wide: true },
			{ name: 'status', label: 'Status', type: 'select', required: true, options: ACTIVE_STATUSES },
			{ name: 'notes', label: 'Notes', type: 'textarea', wide: true },
		],
		'team.fields',
		'field'
	);

export const teamConfig = () => ( {
	title: tp( 'team' ),
	description: 'The people you work with.',
	resource: 'team',
	bulkDepartment: true,
	singular: t( 'team' ).toLowerCase(),
	newLabel: `Add ${ t( 'team' ).toLowerCase() }`,
	emptyText: `No ${ tp( 'team' ).toLowerCase() } yet. Add the people who work with you.`,
	initialFilters: { status: 'active' },
	filters: [
		{ name: 'kind', label: 'Type', options: [ { value: 'employee', label: 'Employees' }, { value: 'contractor', label: 'Contractors' } ] },
		{ name: 'status', label: 'Status', options: ACTIVE_STATUSES },
		{ name: 'department_id', label: dept().one, remote: { resource: 'departments', label: ( b ) => b.name, params: { status: 'active' } } },
		...sdk.extensions( 'team.filters' ).map( ( e ) => e.filter ),
	],
	defaults: { status: 'active', kind: 'employee', cc_documents: false },
	onEdit: ( r ) => navigate( `/team/${ r.id }` ),
	actionsWidth: 110,
	actions: ( r, ctx ) => [ ! r.is_me ? { label: 'This is me', menu: true, onClick: async () => { try { await api.update( 'team', r.id, { wp_user_id: store.userId } ); ElMessage.success( 'Linked to your login.' ); ctx.reload(); } catch ( e ) { ElMessage.error( errorMessage( e ) ); } } } : null ],
	columns: [
		{
			label: 'Person',
			minWidth: 250,
			render: ( r ) =>
				h( 'div', { class: 'bd-cell-client' }, [
					h( 'span', { class: 'bd-ava', style: avatarStyle( r.name ) }, initials( r.name ) ),
					h( 'div', [
						h( 'div', [ h( 'a', { class: 'bd-strong bd-link', href: `#/team/${ r.id }` }, r.name ), r.is_me ? h( 'span', { class: 'bd-badge bd-badge--success bd-badge--plain bd-ml' }, 'You' ) : null ] ),
						h( 'div', { class: 'bd-muted bd-small' }, [ r.role, KIND_LABEL[ r.kind ] ].filter( Boolean ).join( ' · ' ) ),
					] ),
				] ),
		},
		{ label: dept().one, minWidth: 200, render: ( r ) => ( r.department_name ? h( 'span', { class: 'bd-badge bd-badge--neutral bd-badge--plain' }, r.department_name ) : muted( '—' ) ) },
		...sdk.extensions( 'team.columns' ).map( ( e ) => e.column ),
		{ prop: 'status', label: 'Status', type: 'status' },
	],
	fields: teamFields(),
} );
