import { markRaw, reactive } from 'vue';

/**
 * The browser side of the module API. Core's app creates one SDK and exposes it as window.WPFlatCore; each module's
 * bundle (built separately, with Vue and Element Plus left external) calls registerRoute / registerSlot on it.
 *
 * - routes:  a module's screens, matched by path pattern ("/tasks/:id")
 * - slots:   named places in Core's screens where modules add content (client tabs, dashboard cards, portal tabs…)
 * - terms:   the owner's words (Clients → Students), read with t('client') / tp('client')
 * - modules: which modules are installed and switched on (from the boot data)
 */
export function createSdk( boot = {} ) {
	const state = reactive( {
		modules: ( boot.modules || [] ).map( ( m ) => ( { ...m } ) ),
		words: { ...( boot.terms || {} ) },
		routes: [],
		slots: {},
		extras: {},
		icons: {},
		fieldTypes: {},
		formatters: {},
	} );

	const enabled = ( id ) => id === 'core' || state.modules.some( ( m ) => m.id === id && m.enabled !== false );

	const t = ( key, plural = false ) => {
		const w = state.words[ key ];
		if ( ! w ) {
			return key.charAt( 0 ).toUpperCase() + key.slice( 1 ) + ( plural ? 's' : '' );
		}
		return plural ? w.p : w.s;
	};
	const tp = ( key ) => t( key, true );

	/** "Add a {client}" → "Add a student"; {Client}, {clients}, {Clients} also work. */
	const fmt = ( text ) =>
		String( text ).replace( /\{([A-Za-z]+?)(s?)\}/g, ( whole, name, s ) => {
			const w = state.words[ name.charAt( 0 ).toLowerCase() + name.slice( 1 ) ];
			if ( ! w ) {
				return whole;
			}
			const val = s ? w.p : w.s;
			return name.charAt( 0 ) === name.charAt( 0 ).toUpperCase() ? val : val.toLowerCase();
		} );

	/** Register a screen: { path: '/tasks/:id', module: 'tasks', component, title? }. */
	const registerRoute = ( def ) => {
		if ( ! def || ! def.path || ! def.module || ! def.component ) {
			throw new Error( 'registerRoute needs path, module and component' );
		}
		const parts = def.path.split( '/' ).filter( Boolean );
		state.routes.push( { ...def, component: markRaw( def.component ), parts } );
	};

	/** Find the screen for a path such as "/tasks/12"; returns { route, params } or null. Routes of off modules never match. */
	const resolve = ( path ) => {
		const segs = String( path ).split( '?' )[ 0 ].split( '/' ).filter( Boolean );
		let best = null;
		for ( const r of state.routes ) {
			if ( ! enabled( r.module ) || r.parts.length !== segs.length ) {
				continue;
			}
			const params = {};
			let score = 0;
			const ok = r.parts.every( ( p, i ) => {
				if ( p.startsWith( ':' ) ) {
					params[ p.slice( 1 ) ] = decodeURIComponent( segs[ i ] );
					return true;
				}
				score++;
				return p === segs[ i ];
			} );
			if ( ok && ( ! best || score > best.score ) ) {
				best = { route: r, params, score };
			}
		}
		return best ? { route: best.route, params: best.params } : null;
	};

	/** Add something to a named slot: { id, module, component, order?, when?(ctx) }. Re-registering an id replaces it. */
	const registerSlot = ( name, def ) => {
		if ( ! name || ! def || ! def.id || ! def.module || ! def.component ) {
			throw new Error( 'registerSlot needs a slot name, id, module and component' );
		}
		const list = ( state.slots[ name ] = state.slots[ name ] || [] );
		const at = list.findIndex( ( s ) => s.id === def.id );
		const entry = { order: 50, ...def, component: markRaw( def.component ) };
		if ( at >= 0 ) {
			list.splice( at, 1, entry );
		} else {
			list.push( entry );
		}
	};

	/** What a slot shows now: only enabled modules, filtered by `when(ctx)`, ordered. */
	const slot = ( name, ctx = {} ) =>
		( state.slots[ name ] || [] )
			.filter( ( s ) => enabled( s.module ) && ( ! s.when || s.when( ctx ) ) )
			.sort( ( a, b ) => a.order - b.order );

	/**
	 * Add a piece of data to a named extension point: a table column, a form field, a filter… (anything but a component,
	 * which goes in a slot). { id, module, order?, when?(ctx), ...payload }. Re-registering an id replaces it.
	 */
	const extend = ( point, def ) => {
		if ( ! point || ! def || ! def.id || ! def.module ) {
			throw new Error( 'extend needs a point name, id and module' );
		}
		const list = ( state.extras[ point ] = state.extras[ point ] || [] );
		const at = list.findIndex( ( x ) => x.id === def.id );
		const entry = { order: 50, ...def };
		if ( at >= 0 ) {
			list.splice( at, 1, entry );
		} else {
			list.push( entry );
		}
	};

	/** What an extension point holds now: enabled modules only, filtered by `when(ctx)`, ordered. */
	const extensions = ( point, ctx = {} ) =>
		( state.extras[ point ] || [] )
			.filter( ( x ) => enabled( x.module ) && ( ! x.when || x.when( ctx ) ) )
			.sort( ( a, b ) => a.order - b.order );

	/** Sidebar icons: the module registers a Vue component under the icon name its registry entry uses. */
	const registerIcon = ( name, component ) => {
		state.icons[ name ] = markRaw( component );
	};

	/** Teach forms a new field type: registerFieldType( 'richtext', Component ). The component gets modelValue and the field. */
	const registerFieldType = ( type, component ) => {
		state.fieldTypes[ type ] = markRaw( component );
	};

	/** Let a module teach charts and tables how to show its values: registerFormatter( 'money', ( amount, currency ) => '₦1,000' ). */
	const registerFormatter = ( kind, fn ) => {
		state.formatters[ kind ] = fn;
	};
	/** Show a value the way its module wants, or as a plain number when nobody registered a formatter. */
	const format = ( kind, value, ...rest ) => ( state.formatters[ kind ] ? state.formatters[ kind ]( value, ...rest ) : Number( value ).toLocaleString() );

	return { state, enabled, t, tp, fmt, registerFormatter, format, registerRoute, resolve, registerSlot, slot, extend, extensions, registerIcon, registerFieldType };
}
