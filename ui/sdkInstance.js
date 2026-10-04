import { createSdk } from './sdk';

const boot = window.bizycoreBoot || {};
const nav = boot.nav && boot.nav.modules ? boot.nav.modules : [];

/**
 * The one SDK of this page. Modules count as "on" for the signed-in person when they appear in their navigation
 * (switched on, compatible, and allowed by their role), so slots and routes follow permissions as well as installs.
 */
export const sdk = createSdk( { modules: nav.map( ( m ) => ( { id: m.id, enabled: true } ) ), terms: boot.terms || {} } );
