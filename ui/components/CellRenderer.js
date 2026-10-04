import { h } from 'vue';
import Badge from './Badge.vue';
import StatusTag from './StatusTag.vue';
import { avatarStyle, colorOf, fmtDate, fmtDateTime, initials, labelOf } from '../util';
import { sdk } from '../sdkInstance';

const muted = ( text ) => h( 'span', { class: 'bd-muted' }, text );

/** Renders one table cell from a column definition. */
export default function CellRenderer( { col, row, ctx } ) {
	if ( col.render ) {
		return col.render( row, ctx, h );
	}
	const v = row[ col.prop ];

	switch ( col.type ) {
		case 'money':
			return h( 'span', { class: 'bd-money' }, sdk.format( 'money', v, row[ col.currencyProp || 'currency' ] ) );
		case 'date':
			return fmtDate( v );
		case 'datetime':
			return fmtDateTime( v );
		case 'status':
			return h( StatusTag, { status: v } );
		case 'strong':
			return h( 'strong', v );
		case 'yesno':
			return v ? 'Yes' : 'No';
		case 'lookup': {
			const color = colorOf( col.lookup, v );
			return h( Badge, { color: color || '' }, () => labelOf( col.lookup, v ) );
		}
		case 'client':
			return row.client_id
				? h( 'a', { class: 'bd-cell-client bd-link', href: `#/clients/${ row.client_id }` }, [ h( 'span', { class: 'bd-ava bd-ava--sm', style: avatarStyle( row.client_name ) }, initials( row.client_name ) ), h( 'span', row.client_name || `#${ row.client_id }` ) ] )
				: muted( '—' );
		case 'departments':
			return row.departments && row.departments.length
				? row.departments.map( ( b ) => h( Badge, { key: b.id, tone: 'neutral' }, () => b.name ) )
				: muted( '—' );
		case 'groups':
			return row.groups && row.groups.length
				? row.groups.map( ( g ) => h( Badge, { key: g.id, color: g.color || '' }, () => g.name ) )
				: muted( '—' );
		case 'logo':
			return row.logo_url ? h( 'img', { class: 'bd-logo', src: row.logo_url, alt: '' } ) : h( 'span', { class: 'bd-logo bd-logo--blank' } );
		case 'active':
			return v ? 'Yes' : 'No';
		case 'swatch':
			return v ? h( 'span', { class: 'bd-swatch', style: { background: v } } ) : '—';
		case 'code':
			return h( 'code', v );
		default:
			return v === undefined || v === null || v === '' ? muted( '—' ) : String( v );
	}
}
CellRenderer.props = [ 'col', 'row', 'ctx' ];
