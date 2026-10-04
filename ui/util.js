import { store } from './store';

export const lookup = ( type ) => store.meta.lookups[ type ] || [];

export const options = ( type, includeInactive = false ) =>
	lookup( type )
		.filter( ( i ) => includeInactive || i.is_active )
		.map( ( i ) => ( { value: i.key, label: i.label } ) );

export const humanize = ( s ) =>
	String( s || '' )
		.replace( /[_-]/g, ' ' )
		.replace( /^\w/, ( c ) => c.toUpperCase() );

export const labelOf = ( type, key ) => {
	const hit = lookup( type ).find( ( i ) => i.key === key );
	return hit ? hit.label : key ? humanize( key ) : '—';
};

export const colorOf = ( type, key ) => {
	const hit = lookup( type ).find( ( i ) => i.key === key );
	return hit ? hit.color : '';
};

export const fmtDate = ( d ) => {
	if ( ! d ) {
		return '—';
	}
	const m = String( d ).match( /^(\d{4})-(\d{2})-(\d{2})/ );
	if ( ! m ) {
		return d;
	}
	return new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ).toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
};

export const fmtDateTime = ( d ) => {
	if ( ! d ) {
		return '—';
	}
	const m = String( d ).match( /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/ );
	if ( ! m ) {
		return d;
	}
	return new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ] ).toLocaleString( undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
};

const pad = ( n ) => String( n ).padStart( 2, '0' );
export const isoDate = ( d ) => `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
export const today = () => isoDate( new Date() );
export const addDays = ( days ) => {
	const d = new Date();
	d.setDate( d.getDate() + days );
	return isoDate( d );
};

/** Element Plus tag type for a status string. */
export const STATUS_TYPES = {
	active: 'success',
	paid: 'success',
	renewed: 'success',
	pending: 'warning',
	due: 'warning',
	partially_paid: 'warning',
	paused: 'warning',
	upcoming: 'primary',
	sent: 'primary',
	invoiced: 'primary',
	overdue: 'danger',
	cancelled: 'info',
	inactive: 'info',
	draft: 'info',
	skipped: 'info',
	waived: 'info',
};

/**
 * Validates a mixed selection of renewals/charges for invoicing.
 * Pending charges that fall due with a selected renewal are rolled in (deduplicated by id).
 * Returns { ok, reason, client_id, client_name, currency, total, charge_ids, renewal_ids, count, hasRolledIn }.
 */
/** Initials + a stable hue for avatar chips. */
export const initials = ( name ) =>
	String( name || '?' )
		.replace( /\(.*?\)/g, '' )
		.trim()
		.split( /\s+/ )
		.slice( 0, 2 )
		.map( ( w ) => w[ 0 ] )
		.join( '' )
		.toUpperCase() || '?';

export const avatarStyle = ( name ) => {
	let h = 0;
	for ( const c of String( name || '' ) ) {
		h = ( h * 31 + c.charCodeAt( 0 ) ) >>> 0;
	}
	return { '--h': h % 360 };
};

export const relDays = ( days ) => {
	if ( days === null || days === undefined ) {
		return 'No date';
	}
	if ( days === 0 ) {
		return 'Today';
	}
	return days > 0 ? `In ${ days } day${ days === 1 ? '' : 's' }` : `${ -days } day${ days === -1 ? '' : 's' } overdue`;
};

/** Billing-pipeline states → label + badge tone. */
export const round2 = ( n ) => Math.round( ( Number( n ) || 0 ) * 100 ) / 100;

export const addDaysTo = ( iso, days ) => {
	const m = String( iso || '' ).match( /^(\d{4})-(\d{2})-(\d{2})$/ );
	const d = m ? new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] ) : new Date();
	d.setDate( d.getDate() + days );
	return isoDate( d );
};

/** Copy text to the clipboard (with a fallback for non-secure contexts). */
export async function copyText( text ) {
	try {
		await navigator.clipboard.writeText( text );
		return true;
	} catch ( e ) {
		const ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		let ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( err ) {
			ok = false;
		}
		document.body.removeChild( ta );
		return ok;
	}
}

/** Status of any bill (one-off or recurring cycle), derived by the server. */
/** Work: plain-word statuses. */
/** What this business calls its departments, from the owner's chosen words (Settings → Words). */
export const dept = () => {
	const bc = window.BizyCore;
	const one = bc ? bc.t( 'department' ) : 'Department';
	const many = bc ? bc.tp( 'department' ) : 'Departments';
	return { one, many, lower: one.toLowerCase(), lowerMany: many.toLowerCase() };
};

