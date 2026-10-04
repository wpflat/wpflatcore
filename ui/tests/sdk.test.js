import { describe, it, expect } from 'vitest';
import { createSdk } from '../sdk';

const boot = {
	modules: [ { id: 'tasks', enabled: true }, { id: 'money', enabled: false } ],
	terms: { client: { s: 'Student', p: 'Students' }, project: { s: 'Project', p: 'Projects' } },
};
const C = { name: 'C' };

describe( 'terminology', () => {
	it( 'uses the owner’s words', () => {
		const s = createSdk( boot );
		expect( s.t( 'client' ) ).toBe( 'Student' );
		expect( s.tp( 'client' ) ).toBe( 'Students' );
	} );
	it( 'falls back to a sensible default for unknown keys', () => {
		const s = createSdk( boot );
		expect( s.t( 'widget' ) ).toBe( 'Widget' );
		expect( s.tp( 'widget' ) ).toBe( 'Widgets' );
	} );
	it( 'formats sentences with all four token shapes', () => {
		const s = createSdk( boot );
		expect( s.fmt( 'Add a {client}' ) ).toBe( 'Add a student' );
		expect( s.fmt( '{Clients} owing money' ) ).toBe( 'Students owing money' );
		expect( s.fmt( 'All {clients}' ) ).toBe( 'All students' );
		expect( s.fmt( '{Client} list' ) ).toBe( 'Student list' );
		expect( s.fmt( 'Keep {unknown} as is' ) ).toBe( 'Keep {unknown} as is' );
	} );
} );

describe( 'routes', () => {
	it( 'matches patterns and prefers the most specific route', () => {
		const s = createSdk( boot );
		s.registerRoute( { path: '/tasks/:id', module: 'tasks', component: C } );
		s.registerRoute( { path: '/tasks/mine', module: 'tasks', component: { name: 'Mine' } } );
		expect( s.resolve( '/tasks/12' ).params ).toEqual( { id: '12' } );
		expect( s.resolve( '/tasks/mine' ).route.component.name ).toBe( 'Mine' );
		expect( s.resolve( '/nothing' ) ).toBeNull();
	} );
	it( 'ignores routes of switched-off or missing modules', () => {
		const s = createSdk( boot );
		s.registerRoute( { path: '/billing', module: 'money', component: C } );
		s.registerRoute( { path: '/legal', module: 'docs', component: C } );
		expect( s.resolve( '/billing' ) ).toBeNull();
		expect( s.resolve( '/legal' ) ).toBeNull();
	} );
	it( 'rejects incomplete registrations', () => {
		const s = createSdk( boot );
		expect( () => s.registerRoute( { path: '/x' } ) ).toThrow();
	} );
} );

describe( 'slots', () => {
	it( 'lists only enabled modules, in order, filtered by context', () => {
		const s = createSdk( boot );
		s.registerSlot( 'client.tabs', { id: 'tasks', module: 'tasks', component: C, order: 20 } );
		s.registerSlot( 'client.tabs', { id: 'money', module: 'money', component: C, order: 10 } );
		s.registerSlot( 'client.tabs', { id: 'vip', module: 'tasks', component: C, order: 5, when: ( c ) => c.vip } );
		expect( s.slot( 'client.tabs' ).map( ( x ) => x.id ) ).toEqual( [ 'tasks' ] );
		expect( s.slot( 'client.tabs', { vip: true } ).map( ( x ) => x.id ) ).toEqual( [ 'vip', 'tasks' ] );
		expect( s.slot( 'dashboard.cards' ) ).toEqual( [] );
	} );
	it( 'replaces a slot entry with the same id', () => {
		const s = createSdk( boot );
		s.registerSlot( 'a', { id: 'x', module: 'tasks', component: C, order: 1 } );
		s.registerSlot( 'a', { id: 'x', module: 'tasks', component: C, order: 2 } );
		expect( s.slot( 'a' ) ).toHaveLength( 1 );
		expect( s.slot( 'a' )[ 0 ].order ).toBe( 2 );
	} );
	it( 'follows a module being switched on later', () => {
		const s = createSdk( boot );
		s.registerSlot( 'a', { id: 'm', module: 'money', component: C } );
		expect( s.slot( 'a' ) ).toHaveLength( 0 );
		s.state.modules.find( ( m ) => m.id === 'money' ).enabled = true;
		expect( s.slot( 'a' ) ).toHaveLength( 1 );
	} );
} );

describe( 'extension points', () => {
	it( 'collects data from enabled modules in order and filters by context', () => {
		const s = createSdk( boot );
		s.extend( 'team.columns', { id: 'work', module: 'tasks', order: 20, label: 'Work' } );
		s.extend( 'team.columns', { id: 'pay', module: 'money', order: 10, label: 'Pay' } );
		s.extend( 'team.columns', { id: 'vip', module: 'tasks', order: 5, label: 'VIP', when: ( c ) => c.vip } );
		expect( s.extensions( 'team.columns' ).map( ( x ) => x.id ) ).toEqual( [ 'work' ] );
		expect( s.extensions( 'team.columns', { vip: true } ).map( ( x ) => x.id ) ).toEqual( [ 'vip', 'work' ] );
		expect( s.extensions( 'nothing' ) ).toEqual( [] );
	} );
	it( 'rejects incomplete registrations and replaces by id', () => {
		const s = createSdk( boot );
		expect( () => s.extend( 'x', { id: 'a' } ) ).toThrow();
		s.extend( 'x', { id: 'a', module: 'tasks', label: 'one' } );
		s.extend( 'x', { id: 'a', module: 'tasks', label: 'two' } );
		expect( s.extensions( 'x' ).map( ( e ) => e.label ) ).toEqual( [ 'two' ] );
	} );
	it( 'stores icons by name', () => {
		const s = createSdk( boot );
		const I = { name: 'I' };
		s.registerIcon( 'work', I );
		expect( s.state.icons.work ).toBe( I );
	} );
} );
