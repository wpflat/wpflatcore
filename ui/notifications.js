import { api } from './api';
import { store, navigate } from './store';

/** Mark one notification read and go where it points (an app route for the team, a full address for clients). */
export async function openNotification( n ) {
	if ( ! n.read ) {
		api.post( 'notifications/read', { ids: [ n.id ] } ).then( ( r ) => { store.notifUnread = r.unread; } ).catch( () => {} );
	}
	if ( ! n.link ) return;
	if ( n.link.startsWith( 'http' ) ) {
		window.location.href = n.link;
	} else {
		navigate( n.link );
	}
}
